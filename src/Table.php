<?php
/**
 * The shape of one queue table.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use InvalidArgumentException;

/**
 * Class Table
 *
 * Which table a queue lives in, and the few names that differ between one
 * queue and the next. Every queue table has `id`, `status`, `attempts`,
 * `next_attempt_at`, `claimed_until` and `last_error`; what a finished row
 * is called and where its finishing time goes are the plugin's own words.
 * Purging old rows also needs to know when a row was queued: `date_created`
 * unless named otherwise.
 *
 * Times are Unix seconds in integer columns: a lease is compared with "now"
 * on every claim, and a number compares the same in every timezone and on
 * every database.
 *
 * These names are interpolated into SQL -- identifiers cannot be bound -- so
 * they are checked here, once, and the constructor throws rather than guess.
 *
 * @since 1.0.0
 */
final readonly class Table {

	/**
	 * The statuses the queue itself moves a row through.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	public const QUEUE_STATUSES = [ 'pending', 'sending', 'failed' ];

	/**
	 * The columns the queue itself writes.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	public const QUEUE_COLUMNS = [ 'id', 'status', 'attempts', 'next_attempt_at', 'claimed_until', 'last_error' ];

	/**
	 * Name the table and the columns that differ from queue to queue.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $name           The table's full name, with the
	 *                                    site's prefix: `$wpdb->prefix .
	 *                                    'myplugin_outbox'`.
	 * @param string      $sent_status    Status for a row that went, such
	 *                                    as `sent` or `delivered`.
	 * @param string      $sent_column    Column that records when it went.
	 * @param string|null $status_column  Column for the receiver's status
	 *                                    code, when the table keeps one.
	 * @param string      $created_column Column that records when a row was
	 *                                    queued.
	 *
	 * @throws InvalidArgumentException On a name that is not a plain
	 *                                  identifier; a sent status the queue
	 *                                  already uses, which would send the
	 *                                  row again or never; or a column the
	 *                                  queue already writes.
	 */
	public function __construct(
		public string $name,
		public string $sent_status = 'sent',
		public string $sent_column = 'date_sent',
		public ?string $status_column = null,
		public string $created_column = 'date_created',
	) {
		foreach ( [ $name, $sent_status, $sent_column, $status_column ?? 'x', $created_column ] as $identifier ) {
			self::identifier( $identifier );
		}

		if ( in_array( $sent_status, self::QUEUE_STATUSES, true ) ) {
			throw new InvalidArgumentException( 'The sent status cannot be one the queue uses: ' . $sent_status );
		}

		foreach ( [ $sent_column, $status_column, $created_column ] as $column ) {
			if ( in_array( $column, self::QUEUE_COLUMNS, true ) ) {
				throw new InvalidArgumentException( 'The queue already writes the column ' . $column );
			}
		}
	}

	/**
	 * The table, for dbDelta(), with whatever else a row carries.
	 *
	 *     dbDelta( $table->schema( "payload longtext NOT NULL,\n", $wpdb->get_charset_collate() ) );
	 *
	 * @since 1.0.0
	 *
	 * @param string $columns Columns of your own, each line ending in a
	 *                        comma and a newline.
	 * @param string $collate The site's charset and collation.
	 *
	 * @return string
	 */
	public function schema( string $columns = '', string $collate = '' ): string {
		$status = null !== $this->status_column ? "\t{$this->status_column} smallint(5) unsigned NOT NULL DEFAULT 0,\n" : '';

		// dbDelta() reads this line by line: one column a line, two spaces
		// after PRIMARY KEY, and KEY rather than INDEX.
		return "CREATE TABLE {$this->name} (\n"
			. "\tid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
			. $columns
			. "\tstatus varchar(20) NOT NULL DEFAULT 'pending',\n"
			. "\tattempts int(10) unsigned NOT NULL DEFAULT 0,\n"
			. "\tnext_attempt_at bigint(20) unsigned NOT NULL DEFAULT 0,\n"
			. "\tclaimed_until bigint(20) unsigned DEFAULT NULL,\n"
			. "\tlast_error text DEFAULT NULL,\n"
			. $status
			. "\t{$this->created_column} bigint(20) unsigned NOT NULL DEFAULT 0,\n"
			. "\t{$this->sent_column} bigint(20) unsigned DEFAULT NULL,\n"
			. "\tPRIMARY KEY  (id),\n"
			. "\tKEY due (status,next_attempt_at)\n"
			. ') ' . $collate . ';';
	}

	/**
	 * A name that is safe to put in SQL as it stands.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The table or column name.
	 *
	 * @return string The name.
	 *
	 * @throws InvalidArgumentException When it is not a plain identifier.
	 */
	public static function identifier( string $name ): string {
		// `$` alone also matches before a final newline.
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $name ) ) {
			throw new InvalidArgumentException( 'Not a plain identifier: ' . $name );
		}

		return $name;
	}
}
