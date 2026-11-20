<?php
/**
 * Scheduling: running a job now, once, later, at a time or on repeat, and taking it back.
 *
 * @package     SugarCommerce\Jobs
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Traits\Scheduler;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\LocalJob;

/**
 * Scheduling: running a job now, once, later, at a time or on repeat, and taking it back.
 *
 * Through Action Scheduler when it is there, and WP-Cron when it is not.
 *
 * @since 1.0.0
 */
trait Scheduling {

	/**
	 * Queue an action for a moment. No deduplication, no retry.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $hook      The action.
	 * @param array<int, mixed> $args      What it is handed.
	 * @param int               $timestamp When.
	 *
	 * @return bool False without a scheduler.
	 */
	public function schedule( string $hook, array $args, int $timestamp ): bool {
		if ( ! self::available() ) {
			return false;
		}

		as_schedule_single_action( $timestamp, $hook, $args, $this->group );

		return true;
	}

	/**
	 * Queue an action, unless the same one is already waiting.
	 *
	 * For "something changed, tell them in a minute": ten changes in that
	 * minute are one action, which reads the state when it runs.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $hook  The action.
	 * @param array<int, mixed> $args  What it is handed, and matched on.
	 * @param int               $delay Seconds to wait first.
	 *
	 * @return bool False without a scheduler.
	 */
	public function once( string $hook, array $args = [], int $delay = 0 ): bool {
		if ( ! self::available() ) {
			return false;
		}

		if ( as_has_scheduled_action( $hook, $args, $this->group ) ) {
			return true;
		}

		as_schedule_single_action( Clock::time() + max( 0, $delay ), $hook, $args, $this->group );

		return true;
	}

	/**
	 * Declare a recurring job.
	 *
	 * Attached now and put in the calendar once, on `init` -- Action
	 * Scheduler's store is not ready before then, and a job asked for
	 * earlier is silently not queued. Every `interval` seconds after that,
	 * counting from the first run, which is an hour away so that
	 * activating a plugin does not start its clear-out in the same request.
	 *
	 * Seconds, so a daily job drifts an hour when the clocks change. For
	 * "at eight in the morning" use self::at().
	 *
	 * @since 1.0.0
	 *
	 * @param string               $hook     The action.
	 * @param int                  $interval Seconds between runs.
	 * @param callable             $callback What runs.
	 * @param array<string, mixed> $config   {
	 *     Optional.
	 *
	 *     @type int               $first Seconds from now to the first run.
	 *                                    An hour by default.
	 *     @type array<int, mixed> $args  What it is handed.
	 * }
	 *
	 * @return bool False if it cannot run as described.
	 */
	public function recurring( string $hook, int $interval, callable $callback, array $config = [] ): bool {
		if ( '' === $hook || $interval < 1 ) {
			return false;
		}

		$this->recurring[ $hook ] = [
			'interval' => $interval,
			'first'    => max( 0, (int) ( $config['first'] ?? HOUR_IN_SECONDS ) ),
			'args'     => array_values( (array) ( $config['args'] ?? [] ) ),
		];

		add_action( $hook, $callback, 10, count( $this->recurring[ $hook ]['args'] ) );

		// On an admin, cron or command-line request, as at() tends its
		// calendar: a look at the calendar is two queries, and a visitor
		// to the front of the site -- or a webhook, or a REST call -- has
		// no business paying for them. Action Scheduler itself runs from
		// cron, so a job that has never been booked is booked on the
		// request that would run it.
		self::when_ready(
			function () use ( $hook ): void {
				if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
					$this->schedule_recurring( $hook );
				}
			},
			20
		);

		return true;
	}

	/**
	 * Put a recurring job in the calendar, if it is not there already.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The action.
	 *
	 * @return bool Whether it is in the calendar.
	 */
	public function schedule_recurring( string $hook ): bool {
		$job = $this->recurring[ $hook ] ?? null;

		if ( null === $job || ! self::available() || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return false;
		}

		if ( as_has_scheduled_action( $hook, $job['args'], $this->group ) ) {
			return true;
		}

		as_schedule_recurring_action( Clock::time() + $job['first'], $job['interval'], $hook, $job['args'], $this->group );

		return true;
	}

	/**
	 * Declare a job that runs at a time of day, where the site is.
	 *
	 * One action that books the next when it runs, and not a repeating one.
	 * Action Scheduler repeats by a number of seconds or by a cron line read
	 * in UTC, and neither is "eight o'clock here": the first drifts an hour
	 * twice a year when the clocks change, and the second is eight o'clock
	 * in Greenwich. Working the next time out afresh each time, in the
	 * site's timezone, is right through both.
	 *
	 * Often the schedule is a setting -- off, weekly or monthly -- so the
	 * job is booked under a key, `wanted` says which key is wanted now,
	 * and the calendar is made to agree with it on the next admin or cron
	 * request. A booking for a key no longer wanted does not run its
	 * callback when it fires; it puts the calendar right instead.
	 *
	 *     $queue->at( 'myplugin_send_summary', [ Summary::class, 'send' ], [
	 *         'time'   => '08:00',
	 *         'every'  => [ 'weekly' => 'week:mon', 'monthly' => 'month:1' ],
	 *         'wanted' => fn(): string => Summary::enabled() ? Summary::frequency() : '',
	 *     ] );
	 *
	 * @since 1.0.0
	 *
	 * @param string               $hook     The action.
	 * @param callable             $callback What runs, handed the key.
	 * @param array<string, mixed> $config   {
	 *     @type string                       $time   `HH:MM`, the site's
	 *                                                own clock.
	 *     @type string|array<string, string> $every  A rule, or rules by
	 *                                                key: `day`,
	 *                                                `week:mon` … `week:sun`,
	 *                                                `month:1` … `month:31`.
	 *     @type callable                     $wanted Which key is wanted
	 *                                                now, '' for none. The
	 *                                                first key when left out.
	 *     @type string                       $option Remembers which key is
	 *                                                booked. The hook with
	 *                                                `_booked` by default.
	 * }
	 *
	 * @return LocalJob|null Null if it cannot run as described.
	 */
	public function at( string $hook, callable $callback, array $config ): ?LocalJob {
		$job = LocalJob::make( $this, $hook, $callback, $config );

		if ( null === $job ) {
			return null;
		}

		$this->local[ $hook ] = $job;

		add_action( $hook, [ $job, 'run' ], 10, 1 );

		self::when_ready( static fn() => $job->ensure(), 30 );

		return $job;
	}

	/**
	 * A wall-clock job declared with self::at().
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The action.
	 *
	 * @return LocalJob|null
	 */
	public function local( string $hook ): ?LocalJob {
		return $this->local[ $hook ] ?? null;
	}

	/**
	 * Whether an action is waiting or running.
	 *
	 * @since 1.0.0
	 *
	 * @param string                 $hook The action.
	 * @param array<int, mixed>|null $args Its arguments, or null for any.
	 *
	 * @return bool
	 */
	public function scheduled( string $hook, ?array $args = null ): bool {
		return self::available() && (bool) as_has_scheduled_action( $hook, $args, $this->group );
	}

	/**
	 * When an action is next due.
	 *
	 * @since 1.0.0
	 *
	 * @param string                 $hook The action.
	 * @param array<int, mixed>|null $args Its arguments, or null for any.
	 *
	 * @return int|true|null A timestamp; true when it is running this
	 *                       moment, which the scheduler says in place of a
	 *                       time; null when nothing is waiting.
	 */
	public function next_run( string $hook, ?array $args = null ): int|true|null {
		if ( ! self::available() || ! function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}

		$next = as_next_scheduled_action( $hook, $args, $this->group );

		if ( true === $next ) {
			return true;
		}

		return is_int( $next ) || ( is_numeric( $next ) && (int) $next > 0 ) ? (int) $next : null;
	}

	/**
	 * Take an action out of the calendar.
	 *
	 * Exactly these arguments: Action Scheduler matches them as written,
	 * so an empty list is only the actions queued with none.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $hook The action.
	 * @param array<int, mixed> $args Its arguments.
	 *
	 * @return void
	 */
	public function unschedule( string $hook, array $args = [] ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, $this->group );
		}
	}

	/**
	 * Take every action in the group out of the calendar. For uninstall.
	 *
	 * The reason a group is worth having: an orphaned action outlives the
	 * plugin that queued it, and Action Scheduler runs it -- or logs it
	 * failing -- on somebody else's site for ever.
	 *
	 * Only when a copy of Action Scheduler is running, which at uninstall
	 * means one another plugin loaded: the uninstalling plugin is not.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether there was a scheduler to ask.
	 */
	public function unschedule_all(): bool {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return false;
		}

		as_unschedule_all_actions( '', [], $this->group );

		foreach ( $this->local as $job ) {
			$job->forget();
		}

		return true;
	}
}
