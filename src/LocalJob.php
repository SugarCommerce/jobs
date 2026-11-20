<?php
/**
 * A job at a time of day by the site's clock.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use SugarCommerce\Dates\Clock;

/**
 * A job at a time of day by the site's clock: one action, booked afresh
 * each time it runs.
 *
 * Made by {@see Scheduler::at()}. The booking carries a key -- `weekly`,
 * `monthly`, whatever the setting calls its choices -- as the action's one
 * argument, and an option remembers which key is booked, so that a change
 * to the setting can be seen without asking the scheduler.
 *
 * The rules a key maps to:
 *
 * - `day`: every day.
 * - `week:mon` … `week:sun`: that day each week.
 * - `month:1` … `month:31`: that date each month. A month without it is
 *   skipped, so `month:31` is seven times a year; use `month:1` for "once
 *   a month".
 *
 * `daily`, `weekly` (Monday) and `monthly` (the 1st) are read as those.
 *
 * @since 1.0.0
 */
final class LocalJob {

	/**
	 * Days of the week, as `N` formats them.
	 *
	 * @var array<string, string>
	 */
	private const array DAYS = [
		'mon' => '1',
		'tue' => '2',
		'wed' => '3',
		'thu' => '4',
		'fri' => '5',
		'sat' => '6',
		'sun' => '7',
	];

	/**
	 * Names read as a rule.
	 *
	 * @var array<string, string>
	 */
	private const array ALIASES = [
		'daily'   => 'day',
		'weekly'  => 'week:mon',
		'monthly' => 'month:1',
	];

	/**
	 * The group it is booked in.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * The action.
	 *
	 * @var string
	 */
	private string $hook;

	/**
	 * What runs, handed the key.
	 *
	 * @var callable
	 */
	private $callback;

	/**
	 * The hour.
	 *
	 * @var int
	 */
	private int $hour;

	/**
	 * The minute.
	 *
	 * @var int
	 */
	private int $minute;

	/**
	 * Rules by key.
	 *
	 * @var array<string, string>
	 */
	private array $rules;

	/**
	 * Which key is wanted now.
	 *
	 * @var callable|null
	 */
	private $wanted;

	/**
	 * Remembers which key is booked.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Use Scheduler::at().
	 *
	 * @param Scheduler             $scheduler The group.
	 * @param string                $hook      The action.
	 * @param callable              $callback  What runs.
	 * @param int                   $hour      The hour.
	 * @param int                   $minute    The minute.
	 * @param array<string, string> $rules     Rules by key.
	 * @param callable|null         $wanted    Which key is wanted now.
	 * @param string                $option    Remembers which is booked.
	 */
	private function __construct( Scheduler $scheduler, string $hook, callable $callback, int $hour, int $minute, array $rules, ?callable $wanted, string $option ) {
		$this->scheduler = $scheduler;
		$this->hook      = $hook;
		$this->callback  = $callback;
		$this->hour      = $hour;
		$this->minute    = $minute;
		$this->rules     = $rules;
		$this->wanted    = $wanted;
		$this->option    = $option;
	}

	/**
	 * A job from Scheduler::at()'s configuration.
	 *
	 * @param Scheduler            $scheduler The group.
	 * @param string               $hook      The action.
	 * @param callable             $callback  What runs.
	 * @param array<string, mixed> $config    See Scheduler::at().
	 *
	 * @return self|null Null if a time or a rule cannot be read.
	 */
	public static function make( Scheduler $scheduler, string $hook, callable $callback, array $config ): ?self {
		$time = self::time( (string) ( $config['time'] ?? '' ) );

		if ( '' === $hook || null === $time ) {
			return null;
		}

		$every = $config['every'] ?? '';
		$rules = [];

		foreach ( is_array( $every ) ? $every : [ (string) $every => (string) $every ] as $key => $rule ) {
			$rule = self::rule( (string) $rule );

			// A rule nobody can read is a job that never runs, with
			// nothing to say why. Refused here instead.
			if ( '' === (string) $key || null === $rule ) {
				return null;
			}

			$rules[ (string) $key ] = $rule;
		}

		if ( [] === $rules ) {
			return null;
		}

		$wanted = $config['wanted'] ?? null;
		$option = (string) ( $config['option'] ?? '' );

		return new self(
			$scheduler,
			$hook,
			$callback,
			$time[0],
			$time[1],
			$rules,
			is_callable( $wanted ) ? $wanted : null,
			'' !== $option ? $option : $hook . '_booked'
		);
	}

	/**
	 * The action fired: run, and book the next.
	 *
	 * Booked for a key that is no longer wanted -- the setting changed, or
	 * was switched off, after this was booked -- it is not run, and the
	 * calendar is put right.
	 *
	 * @param mixed $key The key it was booked for.
	 *
	 * @return mixed What the callback answered, or null when it did not run.
	 */
	public function run( $key = '' ): mixed {
		$key = (string) $key;

		if ( $this->wanted() !== $key ) {
			$this->ensure( true );

			return null;
		}

		$result = ( $this->callback )( $key );

		$this->book( $key );

		return $result;
	}

	/**
	 * Make the calendar agree with what is wanted.
	 *
	 * Booked when something is wanted and nothing is booked, booked again
	 * when the key has changed, and taken out when nothing is wanted. Asked
	 * on admin and cron requests only: a visitor to the front of the site
	 * has no business paying for a look at the calendar.
	 *
	 * @param bool $anywhere Whether to do it whatever kind of request this is.
	 *
	 * @return void
	 */
	public function ensure( bool $anywhere = false ): void {
		if ( ! Scheduler::available() || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		if ( ! $anywhere && ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		$wanted = $this->wanted();
		$booked = $this->booked();

		if ( '' === $wanted ) {
			// Only when something was booked: the usual case for a job
			// that is off is nothing to do, and that should cost nothing.
			if ( '' !== $booked ) {
				$this->clear();
			}

			return;
		}

		if ( $booked === $wanted && $this->scheduler->scheduled( $this->hook, [ $wanted ] ) ) {
			return;
		}

		$this->clear();
		$this->book( $wanted );
	}

	/**
	 * Book the next run for a key.
	 *
	 * @param string $key Which.
	 *
	 * @return bool False without a scheduler, or for a key with no rule.
	 */
	public function book( string $key ): bool {
		if ( ! isset( $this->rules[ $key ] ) || ! $this->scheduler->schedule( $this->hook, [ $key ], $this->next( $key ) ) ) {
			return false;
		}

		update_option( $this->option, $key, false );

		return true;
	}

	/**
	 * Take every booking out of the calendar.
	 *
	 * Each key by name: Action Scheduler matches arguments as written, and
	 * asking it to unschedule the hook with none takes out only the
	 * bookings that have none -- which these never do.
	 *
	 * @return void
	 */
	public function clear(): void {
		foreach ( array_keys( $this->rules ) as $key ) {
			$this->scheduler->unschedule( $this->hook, [ $key ] );
		}

		$this->scheduler->unschedule( $this->hook );

		$this->forget();
	}

	/**
	 * Forget which key is booked, leaving the calendar alone.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_option( $this->option );
	}

	/**
	 * Which key is booked, or '' for none.
	 *
	 * @return string
	 */
	public function booked(): string {
		return (string) get_option( $this->option, '' );
	}

	/**
	 * Which key is wanted now, or '' for none.
	 *
	 * @return string
	 */
	public function wanted(): string {
		if ( null === $this->wanted ) {
			return (string) array_key_first( $this->rules );
		}

		$key = (string) ( $this->wanted )();

		return isset( $this->rules[ $key ] ) ? $key : '';
	}

	/**
	 * When a key is next due.
	 *
	 * @param string   $key Which.
	 * @param int|null $now The moment to count from. Now when left out.
	 *
	 * @return int A timestamp, always after `$now`.
	 */
	public function next( string $key, ?int $now = null ): int {
		return self::next_at( $this->rules[ $key ] ?? 'day', $this->hour, $this->minute, $now ?? Clock::time(), wp_timezone() );
	}

	/**
	 * When a rule is next due, at a time of day in a timezone.
	 *
	 * Today, if today is the day and the time has not passed; otherwise
	 * the next day that is, at that time by that day's clock -- so the
	 * answer is right on both sides of a change of the clocks.
	 *
	 * @param string       $rule   A rule, as self::rule() reads it.
	 * @param int          $hour   The hour.
	 * @param int          $minute The minute.
	 * @param int          $now    The moment to count from.
	 * @param DateTimeZone $zone   Where the clock is.
	 *
	 * @return int A timestamp, always after `$now`.
	 */
	public static function next_at( string $rule, int $hour, int $minute, int $now, DateTimeZone $zone ): int {
		$rule  = self::rule( $rule ) ?? 'day';
		$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
		$day   = $local->setTime( $hour, $minute );

		// A year and a day: every rule is due inside it, `month:31`
		// included.
		for ( $tries = 0; $tries < 367; $tries++ ) {
			$at = $day->getTimestamp();

			if ( $at > $now && self::due( $rule, $day ) ) {
				return $at;
			}

			$day = $day->modify( '+1 day' )->setTime( $hour, $minute );
		}

		// Not reachable. A week on, so that nothing is ever booked for the
		// past.
		return $now + 7 * DAY_IN_SECONDS;
	}

	/**
	 * Whether a rule falls on a day.
	 *
	 * @param string            $rule A clean rule.
	 * @param DateTimeImmutable $day  The day.
	 *
	 * @return bool
	 */
	private static function due( string $rule, DateTimeImmutable $day ): bool {
		if ( 'day' === $rule ) {
			return true;
		}

		[ $unit, $value ] = explode( ':', $rule, 2 );

		return 'week' === $unit ? self::DAYS[ $value ] === $day->format( 'N' ) : $value === $day->format( 'j' );
	}

	/**
	 * A rule, cleaned, or null when it cannot be read.
	 *
	 * @param string $rule What was given.
	 *
	 * @return string|null
	 */
	public static function rule( string $rule ): ?string {
		$rule = strtolower( trim( $rule ) );
		$rule = self::ALIASES[ $rule ] ?? $rule;

		if ( 'day' === $rule ) {
			return $rule;
		}

		if ( 1 === preg_match( '/^week:(mon|tue|wed|thu|fri|sat|sun)$/D', $rule ) ) {
			return $rule;
		}

		if ( 1 === preg_match( '/^month:([1-9]|[12]\d|3[01])$/D', $rule ) ) {
			return $rule;
		}

		return null;
	}

	/**
	 * `HH:MM` as an hour and a minute, or null.
	 *
	 * @param string $time What was given.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public static function time( string $time ): ?array {
		if ( 1 !== preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/D', trim( $time ), $match ) ) {
			return null;
		}

		return [ (int) $match[1], (int) $match[2] ];
	}
}
