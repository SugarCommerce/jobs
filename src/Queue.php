<?php
/**
 * A queue table, worked under a lease.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use SugarCommerce\Dates\Clock;
use Throwable;

/**
 * Class Queue
 *
 * Anything a plugin sends to somebody else -- webhooks, email, a licence
 * server, a payment provider -- belongs in a table first and on the wire
 * second, so a slow receiver is a queue rather than a checkout that times
 * out halfway. The table is the easy part. Three things go wrong in the
 * loop that drains it:
 *
 * - **Two workers send the same row.** A run every minute that takes
 *   longer than a minute overlaps the next, and on a busy site WP-Cron
 *   starts two at once anyway. Both select the same due rows and both send
 *   them. Here each row is *claimed* with one conditional UPDATE, so
 *   exactly one worker wins it; the loser moves on to the next.
 * - **A crashed worker strands its rows.** Mark a row "sending" to stop the
 *   first problem and a request that dies mid-send -- a fatal, a host's
 *   time limit -- leaves it marked for ever. Here the claim is a *lease*:
 *   once `claimed_until` has passed the row is due again, and nothing has
 *   to notice or clean up.
 * - **Everything is retried.** A 400 fails identically on the twelfth
 *   attempt. Here a failure goes to a {@see Retry}, which retries the other
 *   end's problems and gives up on its refusals.
 *
 * And one subtler one: a worker that outlives its lease has lost the row to
 * whoever claimed it next, so its result is **not written** -- otherwise a
 * slow failure could put back a row the second worker had already sent,
 * and it would go a third time.
 *
 * It does not promise exactly once. A send that succeeded just before its
 * worker died is sent again when the lease runs out, so a receiver must
 * tolerate a duplicate: give it a stable id to deduplicate on, and if the
 * receiver is yours, that is what {@see Inbox} is for.
 *
 *     $outbox = new Queue( new Table( $wpdb->prefix . 'myplugin_outbox' ) );
 *
 *     $outbox->push( [ 'url' => $url, 'body' => $json ] );
 *
 *     // Every minute, from a recurring job:
 *     $outbox->drain( function ( array $row ): Outcome {
 *         $response = wp_remote_post( $row['url'], [ 'body' => $row['body'] ] );
 *         ...
 *     } );
 *
 * @since 1.0.0
 */
final class Queue {

	/**
	 * Seconds a claim holds before the row is anybody's again.
	 *
	 * Comfortably longer than one attempt takes, and short enough that a
	 * crashed run's rows go out a few minutes later.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const LEASE = 300;

	/**
	 * The database.
	 *
	 * @since 1.0.0
	 * @var object
	 */
	private object $db;

	/**
	 * When to try again after a failure.
	 *
	 * @since 1.0.0
	 * @var Retry
	 */
	private Retry $retry;

	/**
	 * Run a queue over one table.
	 *
	 * @since 1.0.0
	 *
	 * @param Table       $table Which table, and its names.
	 * @param Retry|null  $retry When to try again. Five waits from a minute
	 *                           to six hours by default.
	 * @param object|null $db    The database: `$wpdb` by default.
	 */
	public function __construct( private readonly Table $table, ?Retry $retry = null, ?object $db = null ) {
		$this->retry = $retry ?? new Retry();
		$this->db    = $db ?? $GLOBALS['wpdb'];
	}

	/**
	 * The table.
	 *
	 * @since 1.0.0
	 *
	 * @return Table
	 */
	public function table(): Table {
		return $this->table;
	}

	/**
	 * Put a row in the queue.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $fields Columns of your own: what the row
	 *                                     carries.
	 * @param int                  $delay  Seconds before it is due.
	 *
	 * @return int The row's id, 0 when it could not be written.
	 */
	public function push( array $fields, int $delay = 0 ): int {
		$now = Clock::time();

		$written = $this->db->insert(
			$this->table->name,
			array_merge(
				[ $this->table->created_column => $now ],
				array_diff_key( $fields, array_flip( Table::QUEUE_COLUMNS ) ),
				[
					'status'          => 'pending',
					'attempts'        => 0,
					'next_attempt_at' => $now + max( 0, $delay ),
				]
			)
		);

		return false === $written ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Claim and attempt what is due, settling each row.
	 *
	 * A sender that throws has failed that row, not the run: the rows after
	 * it are somebody else's and still due. When the budget runs out, the
	 * rows claimed and not yet tried are let go at once rather than left
	 * waiting for their lease.
	 *
	 * @since 1.0.0
	 *
	 * @param callable    $attempt Sends one row: ( array $row ): Outcome.
	 * @param int         $limit   Most rows in this run.
	 * @param Budget|null $budget  How long the run may take.
	 *
	 * @return array{attempted: int, sent: int, failed: int}
	 */
	public function drain( callable $attempt, int $limit = 100, ?Budget $budget = null ): array {
		$counts = [
			'attempted' => 0,
			'sent'      => 0,
			'failed'    => 0,
		];

		$rows = $this->claim( $limit );

		foreach ( $rows as $index => $row ) {
			if ( null !== $budget && $budget->spent() ) {
				foreach ( array_slice( $rows, $index ) as $left ) {
					$this->release( $left );
				}

				break;
			}

			++$counts['attempted'];

			try {
				$outcome = $attempt( $row );
			} catch ( Throwable $e ) {
				$outcome = Outcome::failed( $e->getMessage() );
			}

			if ( ! $outcome instanceof Outcome ) {
				$outcome = Outcome::failed( 'The sender did not say what happened.' );
			}

			++$counts[ $this->settle( $row, $outcome ) ? 'sent' : 'failed' ];
		}

		return $counts;
	}

	/**
	 * Take up to `$limit` due rows for this worker.
	 *
	 * Due means pending and past its time, or claimed by a worker whose
	 * lease has run out.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Most rows. Less than one claims nothing.
	 * @param int $lease Seconds the claim holds.
	 *
	 * @return array<int, array<string, mixed>> The claimed rows, as they
	 *                                          now stand.
	 */
	public function claim( int $limit = 100, int $lease = self::LEASE ): array {
		if ( $limit < 1 ) {
			return [];
		}

		$now   = Clock::time();
		$table = $this->table->name;
		$due   = "( status = 'pending' AND next_attempt_at <= %d ) OR ( status = 'sending' AND claimed_until < %d )";

		$ids = (array) $this->db->get_col(
			$this->db->prepare( "SELECT id FROM {$table} WHERE {$due} ORDER BY id LIMIT %d", $now, $now, $limit )
		);

		$claimed = [];

		foreach ( $ids as $id ) {
			$id = (int) $id;

			$won = $this->db->query(
				$this->db->prepare(
					"UPDATE {$table} SET status = 'sending', claimed_until = %d WHERE id = %d AND ( {$due} )",
					$now + max( 1, $lease ),
					$id,
					$now,
					$now
				)
			);

			if ( 1 !== (int) $won ) {
				continue;
			}

			$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

			if ( is_array( $row ) ) {
				$claimed[] = $row;
			}
		}

		return $claimed;
	}

	/**
	 * Record how an attempt went.
	 *
	 * Written only while the row is still this claim's: still `sending`,
	 * under the same lease. A worker that ran past its lease has lost the
	 * row to another, and what it has to say is dropped rather than written
	 * over whatever the other worker recorded.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $row     The row as claim() returned it.
	 * @param Outcome              $outcome What happened.
	 *
	 * @return bool Whether it was sent.
	 */
	public function settle( array $row, Outcome $outcome ): bool {
		$now     = Clock::time();
		$attempt = self::whole( $row['attempts'] ?? 0 ) + 1;
		$fields  = [
			'attempts'      => $attempt,
			'claimed_until' => null,
		];

		if ( null !== $this->table->status_column ) {
			$fields[ $this->table->status_column ] = $outcome->status;
		}

		if ( $outcome->sent ) {
			$fields['status']                    = $this->table->sent_status;
			$fields['last_error']                = null;
			$fields[ $this->table->sent_column ] = $now;
		} else {
			$again = ! $outcome->dropped && $this->retry->again( $outcome, $attempt );

			$fields['status']          = $again ? 'pending' : 'failed';
			$fields['last_error']      = mb_substr( $outcome->error, 0, 500 );
			$fields['next_attempt_at'] = $now + ( $again ? $this->retry->delay( $attempt, $outcome->retry_after ) : 0 );
		}

		$this->db->update(
			$this->table->name,
			$fields,
			[
				'id'            => self::whole( $row['id'] ?? 0 ),
				'status'        => 'sending',
				'claimed_until' => self::whole( $row['claimed_until'] ?? 0 ),
			]
		);

		return $outcome->sent;
	}

	/**
	 * Let a claimed row go untried: due again now, no attempt counted.
	 *
	 * Only while it is still this claim's.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $row The row as claim() returned it.
	 *
	 * @return bool Whether it was let go.
	 */
	public function release( array $row ): bool {
		return 1 === (int) $this->db->update(
			$this->table->name,
			[
				'status'        => 'pending',
				'claimed_until' => null,
			],
			[
				'id'            => self::whole( $row['id'] ?? 0 ),
				'status'        => 'sending',
				'claimed_until' => self::whole( $row['claimed_until'] ?? 0 ),
			]
		);
	}

	/**
	 * Rows that should have gone by now and have not.
	 *
	 * For a health check: a queue whose worker has stopped does not fail,
	 * it just fills up, and nothing else will say so.
	 *
	 * @since 1.0.0
	 *
	 * @param int $grace Seconds past due before a row counts.
	 *
	 * @return int
	 */
	public function overdue( int $grace = 900 ): int {
		return self::whole(
			$this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$this->table->name} WHERE status IN ('pending', 'sending') AND next_attempt_at < %d",
					Clock::time() - $grace
				)
			)
		);
	}

	/**
	 * Rows that gave up.
	 *
	 * For a health check or an attention badge: a failed row is something
	 * nobody will retry until a person decides to.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function failed_count(): int {
		return self::whole( $this->db->get_var( "SELECT COUNT(*) FROM {$this->table->name} WHERE status = 'failed'" ) );
	}

	/**
	 * Put a row that gave up back in the queue, with its attempts reset.
	 *
	 * Only a failed row: one still pending or being sent is the queue's,
	 * and resetting it would hand a second worker a row the first is
	 * sending.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id The row.
	 *
	 * @return int Rows changed: 0 when it was not failed.
	 */
	public function retry( int $id ): int {
		return self::whole(
			$this->db->update(
				$this->table->name,
				[
					'status'          => 'pending',
					'attempts'        => 0,
					'next_attempt_at' => Clock::time(),
					'claimed_until'   => null,
				],
				[
					'id'     => $id,
					'status' => 'failed',
				]
			)
		);
	}

	/**
	 * Remove finished rows queued before a cut-off.
	 *
	 * Sent or failed, never pending or sending. A delivered payload is a
	 * copy of whatever it carried, kept for no reason once it has arrived.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $before Unix time: rows queued before this go.
	 * @param bool $dry    Count them instead.
	 *
	 * @return int Rows removed, or that would have been.
	 */
	public function purge( int $before, bool $dry = false ): int {
		$where = "status NOT IN ('pending', 'sending') AND {$this->table->created_column} < %d";

		if ( $dry ) {
			return self::whole( $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table->name} WHERE {$where}", $before ) ) );
		}

		return self::whole( $this->db->query( $this->db->prepare( "DELETE FROM {$this->table->name} WHERE {$where}", $before ) ) );
	}

	/**
	 * A number read back from the database, as an int.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value A column's value.
	 *
	 * @return int Zero for anything that is not a number.
	 */
	private static function whole( mixed $value ): int {
		return match ( true ) {
			is_int( $value )     => $value,
			is_numeric( $value ) => (int) $value,
			default              => 0,
		};
	}
}
