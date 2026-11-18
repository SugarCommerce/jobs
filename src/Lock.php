<?php
/**
 * One run at a time.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use Closure;
use SugarCommerce\Dates\Clock;

/**
 * Class Lock
 *
 * A lock for a run of background work, held in a row of the options table
 * under a lease. A queue drained every minute that can take longer than a
 * minute overlaps itself; the row claims in {@see Queue} already make that
 * safe, and this makes it quiet -- the second run sees the first and
 * leaves.
 *
 * In the database rather than a file, because a WordPress site may be more
 * than one web server and the database is the one thing they share.
 *
 * Taken with one INSERT that the option's unique name lets exactly one
 * request win, and held until `$ttl` seconds have passed. A request that
 * dies holding it -- a fatal, a host's time limit -- cannot leave the work
 * locked for ever: once the lease is over the next run takes it over, with
 * one conditional UPDATE, so two runs racing for an expired lock still
 * produce one owner. Let go only by its holder: a run that outlived its
 * lease and finishes late does not release the lock somebody else now
 * holds.
 *
 *     Lock::with( 'myplugin_drain', fn() => $outbox->drain( $send ) );
 *
 * Read and written directly, never through get_option(): an object cache
 * holding the row would answer "free" to every request after the first.
 *
 * @since 1.0.0
 */
final class Lock {

	/**
	 * Seconds a lock is held, by default.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const TTL = 300;

	/**
	 * Hold a lock; acquire() is the way in.
	 *
	 * @since 1.0.0
	 *
	 * @param object $db    The database.
	 * @param string $name  The option it is held in.
	 * @param string $value What this holder wrote: when it ends, and who.
	 */
	private function __construct( private readonly object $db, private readonly string $name, private string $value ) {}

	/**
	 * Take the lock, or return null while another run holds it.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $name The lock: name it for the plugin and the
	 *                          work, `myplugin_drain_outbox`.
	 * @param int         $ttl  Seconds it is held at most. Longer than the
	 *                          work takes.
	 * @param object|null $db   The database: `$wpdb` by default.
	 *
	 * @return self|null
	 */
	public static function acquire( string $name, int $ttl = self::TTL, ?object $db = null ): ?self {
		$db    = $db ?? $GLOBALS['wpdb'];
		$now   = Clock::time();
		$value = self::value( $now + max( 1, $ttl ) );

		$taken = $db->query(
			$db->prepare( "INSERT IGNORE INTO {$db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value )
		);

		if ( 1 !== (int) $taken ) {
			// Held -- unless its lease is over. The value starts with its
			// end, ten digits, so a string comparison is a time comparison
			// on every database.
			$taken = $db->query(
				$db->prepare(
					"UPDATE {$db->options} SET option_value = %s WHERE option_name = %s AND option_value < %s",
					$value,
					$name,
					sprintf( '%010d', $now )
				)
			);
		}

		return 1 === (int) $taken ? new self( $db, $name, $value ) : null;
	}

	/**
	 * Run some work under the lock, or skip it while another run holds it.
	 *
	 * Let go when the work returns or throws, so one failing step does not
	 * hold its lock for the rest of the run.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $name    The lock.
	 * @param Closure     $work    The work.
	 * @param mixed       $skipped What to return while another run holds it.
	 * @param int         $ttl     Seconds it is held at most.
	 * @param object|null $db      The database: `$wpdb` by default.
	 *
	 * @return mixed What the work returned, or `$skipped`.
	 */
	public static function with( string $name, Closure $work, mixed $skipped = null, int $ttl = self::TTL, ?object $db = null ): mixed {
		$lock = self::acquire( $name, $ttl, $db );

		if ( null === $lock ) {
			return $skipped;
		}

		try {
			return $work();
		} finally {
			$lock->release();
		}
	}

	/**
	 * Hold it longer, for work that turned out slower than planned.
	 *
	 * @since 1.0.0
	 *
	 * @param int $ttl Seconds from now.
	 *
	 * @return bool False when the lock is no longer this holder's.
	 */
	public function extend( int $ttl = self::TTL ): bool {
		$value = self::value( Clock::time() + max( 1, $ttl ) );

		$changed = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$this->name,
				$this->value
			)
		);

		if ( 1 !== (int) $changed ) {
			return false;
		}

		$this->value = $value;

		return true;
	}

	/**
	 * Let the next run in. Nothing when the lock is no longer this
	 * holder's.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether it was let go.
	 */
	public function release(): bool {
		return 1 === (int) $this->db->query(
			$this->db->prepare( "DELETE FROM {$this->db->options} WHERE option_name = %s AND option_value = %s", $this->name, $this->value )
		);
	}

	/**
	 * What a holder writes: when its lease ends, then who it is.
	 *
	 * @since 1.0.0
	 *
	 * @param int $until When the lease ends.
	 *
	 * @return string
	 */
	private static function value( int $until ): string {
		return sprintf( '%010d', $until ) . '.' . bin2hex( random_bytes( 8 ) );
	}
}
