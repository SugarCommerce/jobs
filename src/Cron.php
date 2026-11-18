<?php
/**
 * WP-Cron jobs and schedules, declared.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use SugarCommerce\Dates\Clock;

/**
 * Class Cron
 *
 * A scheduled job is two halves that have to happen at different times and
 * are easy to get wrong separately. The callback has to be attached on
 * *every* request, because WP-Cron fires the hook on some later request
 * than the one that scheduled it -- a callback attached only at activation
 * means an event that fires into an empty room for ever, and nothing
 * reports it. The scheduling has to happen only once, because scheduling
 * the same hook twice runs the job twice.
 *
 * So declaring a job does both: attaches now, schedules only if nothing
 * already has. And because an orphaned event outlives the plugin that made
 * it and fires into code that is no longer there, on somebody else's site,
 * for ever, everything declared can be taken out again in one call at
 * uninstall.
 *
 * Recurrences beyond the four WordPress ships -- hourly, twicedaily, daily
 * and weekly -- are declared here too, rather than through the
 * `cron_schedules` filter every plugin writes the same three lines for: so
 * that two plugins asking for the same five-minute schedule get one, and so
 * that a job can be refused for naming a recurrence that does not exist,
 * which otherwise runs once and never again with nothing to say why.
 *
 *     Cron::schedule( 'every_five_minutes', 5 * MINUTE_IN_SECONDS, __( 'Every five minutes', 'myplugin' ) );
 *
 *     Cron::job( 'myplugin_sync', [
 *         'schedule' => 'every_five_minutes',
 *         'callback' => [ Sync::class, 'run' ],
 *     ] );
 *
 *     // uninstall.php
 *     Cron::unschedule_all( 'myplugin_' );
 *
 * WordPress cron runs on traffic, not on time: a quiet site runs a "daily"
 * job late. Nothing here changes that.
 *
 * @since 1.0.0
 */
final class Cron {

	/**
	 * Every job declared, by hook.
	 *
	 * @since 1.0.0
	 * @var array<string, array<string, mixed>>
	 */
	private static array $jobs = [];

	/**
	 * Every recurrence declared, by name.
	 *
	 * @since 1.0.0
	 * @var array<string, array{interval: int, display: string}>
	 */
	private static array $schedules = [];

	/**
	 * Whether the `cron_schedules` filter is attached.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Add a recurrence.
	 *
	 * Existing ones are not replaced: core's own four, and anything another
	 * plugin added first, stay as they are. A plugin quietly changing what
	 * `daily` means for the whole site is the thing this avoids.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name     How jobs refer to it.
	 * @param int    $interval Seconds between runs.
	 * @param string $display  What a person sees, translated by the caller.
	 *                         The name when left out.
	 *
	 * @return bool False if the interval is not a positive number of seconds.
	 */
	public static function schedule( string $name, int $interval, string $display = '' ): bool {
		if ( '' === $name || $interval < 1 ) {
			return false;
		}

		self::$schedules[ $name ] = [
			'interval' => $interval,
			'display'  => '' === $display ? $name : $display,
		];

		if ( ! self::$hooked ) {
			self::$hooked = true;

			add_filter( 'cron_schedules', [ self::class, 'apply_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		}

		return true;
	}

	/**
	 * Hand WordPress the recurrences. The `cron_schedules` filter.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $schedules What WordPress has so far.
	 *
	 * @return array<string, mixed>
	 */
	public static function apply_schedules( $schedules ): array {
		$schedules = (array) $schedules;

		foreach ( self::$schedules as $name => $schedule ) {
			if ( ! isset( $schedules[ $name ] ) ) {
				$schedules[ $name ] = $schedule;
			}
		}

		return $schedules;
	}

	/**
	 * Whether a recurrence exists, core's or anybody's.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The recurrence.
	 *
	 * @return bool
	 */
	public static function has_schedule( string $name ): bool {
		if ( isset( self::$schedules[ $name ] ) ) {
			return true;
		}

		return function_exists( 'wp_get_schedules' ) && isset( wp_get_schedules()[ $name ] );
	}

	/**
	 * Every recurrence this copy declared.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function schedules(): array {
		return self::$schedules;
	}

	/**
	 * Declare a job.
	 *
	 * Call it on every request -- on `init` or `plugins_loaded`, not on
	 * activation. Attaching the callback is what has to happen every time;
	 * the scheduling happens once and this works out which.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $hook   The action WP-Cron will fire.
	 * @param array<string, mixed> $config {
	 *     @type callable          $callback What runs. Required.
	 *     @type string            $schedule A recurrence: `hourly`, `daily`,
	 *                                       or one added with schedule().
	 *                                       Empty for a job that runs once.
	 *     @type array<int, mixed> $args     What the callback is handed.
	 *     @type int               $start    When it first runs, as a
	 *                                       timestamp. Now when left out or
	 *                                       in the past.
	 * }
	 *
	 * @return bool False if the job cannot run as described.
	 */
	public static function job( string $hook, array $config ): bool {
		$config = array_merge(
			[
				'callback' => null,
				'schedule' => '',
				'args'     => [],
				'start'    => 0,
			],
			$config
		);

		if ( '' === $hook || ! is_callable( $config['callback'] ) ) {
			return false;
		}

		// A recurrence nothing has added gives an event that runs once and
		// never again, with nothing to say why. Better to refuse it than to
		// schedule something that quietly stops.
		if ( '' !== $config['schedule'] && ! self::has_schedule( (string) $config['schedule'] ) ) {
			return false;
		}

		$config['args'] = array_values( (array) $config['args'] );

		self::$jobs[ $hook ] = $config;

		add_action( $hook, $config['callback'], 10, max( 1, count( $config['args'] ) ) );

		self::queue( $hook, $config );

		return true;
	}

	/**
	 * Put a job in the queue, if it is not there already.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $hook   The action.
	 * @param array<string, mixed> $config Its configuration.
	 *
	 * @return void
	 */
	private static function queue( string $hook, array $config ): void {
		$args = $config['args'];

		if ( wp_next_scheduled( $hook, $args ) ) {
			return;
		}

		// Now, unless a first run was named. Not a moment in the past: core
		// refuses a non-positive timestamp, and a start time that has been
		// and gone is the commonest way a job is scheduled and never runs.
		$now   = Clock::time();
		$start = (int) $config['start'];
		$start = $start > $now ? $start : $now;

		if ( '' === $config['schedule'] ) {
			wp_schedule_single_event( $start, $hook, $args );

			return;
		}

		wp_schedule_event( $start, (string) $config['schedule'], $hook, $args );
	}

	/**
	 * Whether a job is in the queue.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $hook The action.
	 * @param array<int, mixed> $args The arguments it was scheduled with.
	 *
	 * @return bool
	 */
	public static function scheduled( string $hook, array $args = [] ): bool {
		return null !== self::next_run( $hook, $args );
	}

	/**
	 * When a job next runs, or null if it is not queued.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $hook The action.
	 * @param array<int, mixed> $args The arguments it was scheduled with.
	 *
	 * @return int|null
	 */
	public static function next_run( string $hook, array $args = [] ): ?int {
		$timestamp = wp_next_scheduled( $hook, $args );

		return false === $timestamp ? null : (int) $timestamp;
	}

	/**
	 * Take a job out of the queue.
	 *
	 * wp_clear_scheduled_hook(), not wp_unschedule_event(), because the
	 * latter removes one occurrence at one timestamp -- and a hook scheduled
	 * twice by accident is exactly the mess uninstall is supposed to tidy.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $hook The action.
	 * @param array<int, mixed> $args The arguments it was scheduled with.
	 *
	 * @return bool
	 */
	public static function unschedule( string $hook, array $args = [] ): bool {
		unset( self::$jobs[ $hook ] );

		return false !== wp_clear_scheduled_hook( $hook, $args );
	}

	/**
	 * Take every job out of the queue. For uninstall.
	 *
	 * Every job declared on this request, with the arguments it was
	 * declared with. And, given a prefix, every queued event whose hook
	 * starts with it, whatever its arguments -- which is what uninstall
	 * needs, because the plugin being removed is not loaded and has
	 * declared nothing, and because a job queued by an older version with
	 * other arguments is still in the queue.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Hooks starting with this go too: `myplugin_`.
	 *                       Empty for only what was declared.
	 *
	 * @return int How many hooks were cleared.
	 */
	public static function unschedule_all( string $prefix = '' ): int {
		$cleared = [];

		foreach ( self::$jobs as $hook => $config ) {
			if ( self::unschedule( $hook, (array) $config['args'] ) ) {
				$cleared[ $hook ] = true;
			}
		}

		if ( '' !== $prefix && function_exists( '_get_cron_array' ) ) {
			foreach ( (array) _get_cron_array() as $events ) {
				foreach ( (array) $events as $hook => $instances ) {
					if ( ! str_starts_with( (string) $hook, $prefix ) ) {
						continue;
					}

					foreach ( (array) $instances as $instance ) {
						if ( false !== wp_clear_scheduled_hook( (string) $hook, (array) ( $instance['args'] ?? [] ) ) ) {
							$cleared[ $hook ] = true;
						}
					}
				}
			}
		}

		return count( $cleared );
	}

	/**
	 * Every job declared on this request.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function jobs(): array {
		return self::$jobs;
	}
}
