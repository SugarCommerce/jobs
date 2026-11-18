<?php
/**
 * Incoming events handled once, however often they arrive.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use InvalidArgumentException;
use SugarCommerce\Dates\Clock;

/**
 * Class Inbox
 *
 * The receiving side of a webhook. Senders deliver at least once -- they
 * retry on a timeout, on a 500, and sometimes for no reason anybody can
 * see -- so the same event arrives twice, and a handler that grants access
 * or sends an email must not run twice.
 *
 * **Claimed by the unique index.** The usual guard, "have we seen this id?"
 * then insert, has a window between the two that two concurrent deliveries
 * both pass through. Here the claim *is* the insert, and the event id's
 * unique index lets exactly one of them win.
 *
 * **A failure is not "done".** A handler that threw marks the event failed,
 * and the sender's retry -- which it sends precisely because we answered
 * 500 -- takes the row over. Answering "already processed" to that retry is
 * the classic version of this bug: the first attempt failed, the retry was
 * acknowledged and dropped, and the event was never handled.
 *
 * **Nor is a crash "busy" for ever.** A row stays pending if the request
 * died mid-handler. After `stale_after` seconds a retry may take it over --
 * far longer than a handler runs, far shorter than a sender's schedule.
 * Every takeover is one conditional UPDATE, so two retries racing for the
 * same row still produce one owner.
 *
 *     $claim = $inbox->claim( $event->id, [ 'type' => $event->type ] );
 *
 *     if ( ! $claim->owned() ) {
 *         return new WP_REST_Response( null, Claim::Busy === $claim ? 409 : 200 );
 *     }
 *
 *     try {
 *         handle( $event );
 *         $inbox->complete( $event->id );
 *     } catch ( Throwable $e ) {
 *         $inbox->fail( $event->id, $e->getMessage() );
 *         return new WP_REST_Response( null, 500 );
 *     }
 *
 * @since 1.0.0
 */
final class Inbox {

	/**
	 * Seconds a claim holds before a retry may take the event over.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const STALE_AFTER = 300;

	/**
	 * The database.
	 *
	 * @since 1.0.0
	 * @var object
	 */
	private object $db;

	/**
	 * Use a table of events.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $table       The table's full name, with the
	 *                                 site's prefix.
	 * @param string      $key         The event id's column. Must carry a
	 *                                 unique index: it is the guarantee.
	 * @param int         $stale_after Seconds before a pending claim can be
	 *                                 taken over.
	 * @param object|null $db          The database: `$wpdb` by default.
	 *
	 * @throws InvalidArgumentException On a name that is not a plain
	 *                                  identifier, or a stale time under a
	 *                                  second -- every retry would take
	 *                                  over a handler still running.
	 */
	public function __construct(
		private readonly string $table,
		private readonly string $key = 'event_id',
		private readonly int $stale_after = self::STALE_AFTER,
		?object $db = null,
	) {
		self::identifier( $table );
		self::identifier( $key );

		if ( $stale_after < 1 ) {
			throw new InvalidArgumentException( 'A claim must hold for at least a second.' );
		}

		$this->db = $db ?? $GLOBALS['wpdb'];
	}

	/**
	 * The table, for dbDelta(), with whatever else you keep about an event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $columns Columns of your own -- its type, its payload --
	 *                        each line ending in a comma and a newline.
	 * @param string $collate The site's charset and collation.
	 *
	 * @return string
	 */
	public function schema( string $columns = '', string $collate = '' ): string {
		return "CREATE TABLE {$this->table} (\n"
			. "\tid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
			. "\t{$this->key} varchar(191) NOT NULL,\n"
			. $columns
			. "\tstatus varchar(20) NOT NULL DEFAULT 'pending',\n"
			. "\tattempts int(10) unsigned NOT NULL DEFAULT 1,\n"
			. "\tlast_error text DEFAULT NULL,\n"
			. "\tdate_created bigint(20) unsigned NOT NULL DEFAULT 0,\n"
			. "\tdate_claimed bigint(20) unsigned DEFAULT NULL,\n"
			. "\tdate_processed bigint(20) unsigned DEFAULT NULL,\n"
			. "\tPRIMARY KEY  (id),\n"
			. "\tUNIQUE KEY {$this->key} ({$this->key})\n"
			. ') ' . $collate . ';';
	}

	/**
	 * Take ownership of an event, or say why not.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $id     The sender's event id.
	 * @param array<string, mixed> $fields Columns of your own, written when
	 *                                     the event is first seen.
	 *
	 * @return Claim Only an Owned claim may run the handler.
	 *
	 * @throws InvalidArgumentException On a field name that is not a plain
	 *                                  identifier.
	 */
	public function claim( string $id, array $fields = [] ): Claim {
		$now = Clock::time();
		$row = [
			$this->key     => $id,
			'status'       => 'pending',
			'attempts'     => 1,
			'date_created' => $now,
			'date_claimed' => $now,
		] + $fields;

		$columns      = implode( ', ', array_map( [ self::class, 'identifier' ], array_keys( $row ) ) );
		$placeholders = implode( ', ', array_fill( 0, count( $row ), '%s' ) );

		// The insert is the claim: the unique index lets one delivery in.
		$first = $this->db->query(
			$this->db->prepare( "INSERT IGNORE INTO {$this->table} ({$columns}) VALUES ({$placeholders})", ...array_values( $row ) )
		);

		if ( 1 === (int) $first ) {
			return Claim::Owned;
		}

		$taken = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
				    SET status = 'pending', attempts = attempts + 1, date_claimed = %d, last_error = NULL
				  WHERE {$this->key} = %s
				    AND ( status = 'failed'
				          OR ( status = 'pending' AND COALESCE( date_claimed, date_created ) < %d ) )",
				$now,
				$id,
				$now - $this->stale_after
			)
		);

		if ( (int) $taken > 0 ) {
			return Claim::Owned;
		}

		$status = $this->db->get_var( $this->db->prepare( "SELECT status FROM {$this->table} WHERE {$this->key} = %s", $id ) );

		return 'done' === $status ? Claim::Done : Claim::Busy;
	}

	/**
	 * Mark an event handled.
	 *
	 * Done is final: a delivery that lost its claim to a retry and finishes
	 * late changes nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The sender's event id.
	 *
	 * @return int Rows changed.
	 */
	public function complete( string $id ): int {
		return (int) $this->db->update(
			$this->table,
			[
				'status'         => 'done',
				'date_processed' => Clock::time(),
			],
			[
				$this->key => $id,
				'status'   => 'pending',
			]
		);
	}

	/**
	 * Mark an event failed, so the sender's retry can take it over.
	 *
	 * Only one still pending. A delivery that ran past `stale_after` may
	 * fail after a retry took the event over and finished it; marking that
	 * failed would hand the next retry an event already handled, and the
	 * handler would run twice -- which is what this class is for preventing.
	 *
	 * The error is stored as given. An exception's message can carry a URL
	 * with a token in it, or a customer's address: pass what is safe to keep.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id    The sender's event id.
	 * @param string $error What went wrong, for whoever looks.
	 *
	 * @return int Rows changed.
	 */
	public function fail( string $id, string $error ): int {
		return (int) $this->db->update(
			$this->table,
			[
				'status'     => 'failed',
				'last_error' => $error,
			],
			[
				$this->key => $id,
				'status'   => 'pending',
			]
		);
	}

	/**
	 * A name that is safe to put in SQL as it stands.
	 *
	 * Table and column names are interpolated -- identifiers cannot be
	 * bound -- so they are checked once, and anything else throws rather
	 * than guess.
	 *
	 * @param string $name The table or column name.
	 *
	 * @return string The name.
	 *
	 * @throws InvalidArgumentException When it is not a plain identifier.
	 */
	private static function identifier( string $name ): string {
		// `$` alone also matches before a final newline.
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $name ) ) {
			throw new InvalidArgumentException( 'Not a plain identifier: ' . $name );
		}

		return $name;
	}
}
