<?php
/**
 * Wall-clock job tests.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use SugarCommerce\Jobs\LocalJob;
use SugarCommerce\Jobs\Scheduler;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "At eight in the morning where the site is", and keeping the calendar
 * level with a setting.
 *
 * The arithmetic is checked against the clock changing, because that is the
 * case a repeating action gets wrong: a weekly action of 604,800 seconds
 * that was eight o'clock in winter is nine o'clock all summer.
 */
final class LocalJobTest extends TestCase {

	/**
	 * What the setting says, for the job's `wanted`.
	 *
	 * @var string
	 */
	private string $setting = 'weekly';

	/**
	 * Keys the callback was run with.
	 *
	 * @var string[]
	 */
	private array $sent = [];

	/**
	 * Empty the queue and the registries.
	 */
	protected function setUp(): void {
		jobs_reset();

		$this->setting = 'weekly';
		$this->sent    = [];
	}

	/**
	 * A timestamp from a local wall-clock time.
	 *
	 * @param string $local `Y-m-d H:i`.
	 * @param string $zone  The timezone.
	 *
	 * @return int
	 */
	private function at( string $local, string $zone ): int {
		return ( new DateTimeImmutable( $local, new DateTimeZone( $zone ) ) )->getTimestamp();
	}

	/**
	 * The job a summary email would be.
	 *
	 * @return LocalJob
	 */
	private function summary(): LocalJob {
		$job = Scheduler::group( 'myplugin' )->at(
			'myplugin_send_summary',
			function ( string $key ): string {
				$this->sent[] = $key;

				return 'sent';
			},
			[
				'time'   => '08:00',
				'every'  => [
					'weekly'  => 'week:mon',
					'monthly' => 'month:1',
				],
				'wanted' => fn(): string => $this->setting,
				'option' => 'myplugin_summary_schedule',
			]
		);

		$this->assertNotNull( $job );

		return $job;
	}

	/**
	 * When a rule is next due.
	 *
	 * @param string $rule     The rule.
	 * @param string $now      Local `Y-m-d H:i`.
	 * @param string $expected Local `Y-m-d H:i`.
	 * @param string $zone     The timezone.
	 */
	#[DataProvider( 'dues' )]
	public function test_when_a_rule_is_next_due( string $rule, string $now, string $expected, string $zone ): void {
		$this->assertSame(
			$this->at( $expected, $zone ),
			LocalJob::next_at( $rule, 8, 0, $this->at( $now, $zone ), new DateTimeZone( $zone ) )
		);
	}

	/**
	 * Rules, moments and when each is next due.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function dues(): array {
		return [
			// 16 November 2026 is a Monday.
			'monday, before eight'        => [ 'week:mon', '2026-11-16 07:59', '2026-11-16 08:00', 'Asia/Bangkok' ],
			'monday, at eight is past'    => [ 'week:mon', '2026-11-16 08:00', '2026-11-23 08:00', 'Asia/Bangkok' ],
			'tuesday'                     => [ 'week:mon', '2026-11-17 12:00', '2026-11-23 08:00', 'Asia/Bangkok' ],
			'a friday'                    => [ 'week:fri', '2026-11-17 12:00', '2026-11-20 08:00', 'Asia/Bangkok' ],
			'weekly is monday'            => [ 'weekly', '2026-11-17 12:00', '2026-11-23 08:00', 'Asia/Bangkok' ],
			'first of next month'         => [ 'month:1', '2026-11-17 12:00', '2026-12-01 08:00', 'Asia/Bangkok' ],
			'first, before eight'         => [ 'month:1', '2026-12-01 06:00', '2026-12-01 08:00', 'Asia/Bangkok' ],
			'into the new year'           => [ 'monthly', '2026-12-01 09:00', '2027-01-01 08:00', 'Asia/Bangkok' ],
			'every day, later today'      => [ 'day', '2026-11-17 07:00', '2026-11-17 08:00', 'Asia/Bangkok' ],
			'every day, tomorrow'         => [ 'daily', '2026-11-17 09:00', '2026-11-18 08:00', 'Asia/Bangkok' ],
			'a 31st skips short months'   => [ 'month:31', '2027-02-01 12:00', '2027-03-31 08:00', 'Asia/Bangkok' ],
			'clocks go forward in London' => [ 'week:mon', '2026-03-28 12:00', '2026-03-30 08:00', 'Europe/London' ],
			'clocks go back in London'    => [ 'week:mon', '2026-10-24 12:00', '2026-10-26 08:00', 'Europe/London' ],
			'clocks go forward in NY'     => [ 'week:mon', '2026-03-07 12:00', '2026-03-09 08:00', 'America/New_York' ],
		];
	}

	/**
	 * Eight o'clock local is not eight o'clock UTC, either side of a change.
	 */
	public function test_the_utc_moment_moves_with_the_clocks(): void {
		$zone = new DateTimeZone( 'Europe/London' );

		$winter = LocalJob::next_at( 'week:mon', 8, 0, $this->at( '2026-03-21 12:00', 'UTC' ), $zone );
		$summer = LocalJob::next_at( 'week:mon', 8, 0, $this->at( '2026-03-28 12:00', 'UTC' ), $zone );

		$this->assertSame( '08:00', gmdate( 'H:i', $winter ) );
		$this->assertSame( '07:00', gmdate( 'H:i', $summer ) );
		$this->assertSame( 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS, $summer - $winter );
	}

	/**
	 * Minutes count.
	 */
	public function test_minutes_count(): void {
		$this->assertSame(
			$this->at( '2026-11-17 08:30', 'UTC' ),
			LocalJob::next_at( 'day', 8, 30, $this->at( '2026-11-17 08:29', 'UTC' ), new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Times read and refused.
	 */
	public function test_times(): void {
		$this->assertSame( [ 8, 0 ], LocalJob::time( '08:00' ) );
		$this->assertSame( [ 8, 5 ], LocalJob::time( '8:05' ) );
		$this->assertSame( [ 23, 59 ], LocalJob::time( '23:59' ) );
		$this->assertNull( LocalJob::time( '24:00' ) );
		$this->assertNull( LocalJob::time( '08:60' ) );
		$this->assertNull( LocalJob::time( '8am' ) );
		$this->assertNull( LocalJob::time( '' ) );
	}

	/**
	 * Rules read and refused.
	 */
	public function test_rules(): void {
		$this->assertSame( 'week:mon', LocalJob::rule( 'weekly' ) );
		$this->assertSame( 'month:1', LocalJob::rule( 'Monthly' ) );
		$this->assertSame( 'day', LocalJob::rule( 'daily' ) );
		$this->assertSame( 'week:sun', LocalJob::rule( 'week:sun' ) );
		$this->assertSame( 'month:31', LocalJob::rule( 'month:31' ) );
		$this->assertNull( LocalJob::rule( 'month:0' ) );
		$this->assertNull( LocalJob::rule( 'month:32' ) );
		$this->assertNull( LocalJob::rule( 'week:monday' ) );
		$this->assertNull( LocalJob::rule( 'fortnightly' ) );
	}

	/**
	 * A job that cannot run as described is refused, not booked to never run.
	 */
	public function test_an_unreadable_job_is_refused(): void {
		$queue = Scheduler::group( 'myplugin' );

		$this->assertNull( $queue->at( 'a', static fn() => null, [ 'time' => '25:00', 'every' => 'day' ] ) );
		$this->assertNull( $queue->at( 'b', static fn() => null, [ 'time' => '08:00', 'every' => 'fortnightly' ] ) );
		$this->assertNull( $queue->at( 'c', static fn() => null, [ 'time' => '08:00', 'every' => [] ] ) );
		$this->assertNull( $queue->at( '', static fn() => null, [ 'time' => '08:00', 'every' => 'day' ] ) );
	}

	/**
	 * Booked on an admin request, under the key, at the next due moment.
	 */
	public function test_it_is_booked_on_an_admin_request(): void {
		$GLOBALS['jobs_is_admin'] = true;

		$job = $this->summary();

		jobs_fire_init();

		$queued = $GLOBALS['as_actions'];

		$this->assertCount( 1, $queued );
		$this->assertSame( 'myplugin_send_summary', $queued[0]['hook'] );
		$this->assertSame( [ 'weekly' ], $queued[0]['args'] );
		$this->assertSame( 'myplugin', $queued[0]['group'] );
		$this->assertSame( $job->next( 'weekly' ), $queued[0]['timestamp'] );
		$this->assertSame( 'weekly', get_option( 'myplugin_summary_schedule' ) );
	}

	/**
	 * And on a cron request, which is where a site nobody visits gets it.
	 */
	public function test_it_is_booked_on_a_cron_request(): void {
		$GLOBALS['jobs_doing_cron'] = true;

		$this->summary();

		jobs_fire_init();

		$this->assertCount( 1, $GLOBALS['as_actions'] );
	}

	/**
	 * A booking with no note of it is booked again, and noted, not doubled.
	 */
	public function test_a_lost_note_is_put_right(): void {
		$job = $this->summary();

		$job->ensure( true );

		delete_option( 'myplugin_summary_schedule' );

		$job->ensure( true );

		$this->assertCount( 1, $GLOBALS['as_actions'] );
		$this->assertSame( 'weekly', $job->booked() );
	}

	/**
	 * A visitor's request does not look at the calendar.
	 */
	public function test_a_visitor_does_not_pay_for_it(): void {
		$this->summary();

		jobs_fire_init();

		$this->assertSame( [], $GLOBALS['as_actions'] );
	}

	/**
	 * Asked again with nothing changed, nothing changes.
	 */
	public function test_ensuring_twice_books_once(): void {
		$job = $this->summary();

		$job->ensure( true );
		$job->ensure( true );

		$this->assertCount( 1, $GLOBALS['as_actions'] );
	}

	/**
	 * A changed setting replaces the booking, and leaves no stale one.
	 */
	public function test_a_changed_setting_replaces_the_booking(): void {
		$job = $this->summary();

		$job->ensure( true );

		$this->setting = 'monthly';

		$job->ensure( true );

		$this->assertCount( 1, $GLOBALS['as_actions'], 'The weekly booking was left in the calendar.' );
		$this->assertSame( [ 'monthly' ], $GLOBALS['as_actions'][0]['args'] );
		$this->assertSame( 'monthly', $job->booked() );
	}

	/**
	 * Switched off, the booking goes and so does the note of it.
	 */
	public function test_switching_off_clears_it(): void {
		$job = $this->summary();

		$job->ensure( true );

		$this->setting = '';

		$job->ensure( true );

		$this->assertSame( [], $GLOBALS['as_actions'] );
		$this->assertSame( '', $job->booked() );
	}

	/**
	 * A key nobody declared is off.
	 */
	public function test_an_unknown_key_is_off(): void {
		$job = $this->summary();

		$this->setting = 'hourly';

		$this->assertSame( '', $job->wanted() );
		$this->assertFalse( $job->book( 'hourly' ) );
	}

	/**
	 * Running it runs the callback and books the next.
	 */
	public function test_running_it_books_the_next(): void {
		$job = $this->summary();

		$this->assertSame( 'sent', $job->run( 'weekly' ) );

		$this->assertSame( [ 'weekly' ], $this->sent );
		$this->assertCount( 1, $GLOBALS['as_actions'] );
		$this->assertSame( [ 'weekly' ], $GLOBALS['as_actions'][0]['args'] );
	}

	/**
	 * A booking for a key no longer wanted does not run; it fixes the calendar.
	 */
	public function test_a_stale_booking_does_not_run(): void {
		$job = $this->summary();

		$this->setting = 'monthly';

		$this->assertNull( $job->run( 'weekly' ) );

		$this->assertSame( [], $this->sent );
		$this->assertSame( [ 'monthly' ], $GLOBALS['as_actions'][0]['args'] );
	}

	/**
	 * Fired by the scheduler, through the hook.
	 */
	public function test_the_hook_runs_it(): void {
		$this->summary();

		do_action( 'myplugin_send_summary', 'weekly' );

		$this->assertSame( [ 'weekly' ], $this->sent );
	}

	/**
	 * One rule and no `wanted` is always on, under the rule's own name.
	 */
	public function test_a_single_rule_is_always_wanted(): void {
		$job = Scheduler::group( 'myplugin' )->at( 'myplugin_digest', static fn() => null, [ 'time' => '07:30', 'every' => 'week:fri' ] );

		$this->assertSame( 'week:fri', $job->wanted() );

		$job->ensure( true );

		$this->assertSame( [ 'week:fri' ], $GLOBALS['as_actions'][0]['args'] );
		$this->assertSame( 'week:fri', get_option( 'myplugin_digest_booked' ) );
	}

	/**
	 * Uninstall forgets what was booked, as well as the bookings.
	 */
	public function test_uninstall_forgets_the_booking(): void {
		$job = $this->summary();

		$job->ensure( true );

		Scheduler::group( 'myplugin' )->unschedule_all();

		$this->assertSame( [], $GLOBALS['as_actions'] );
		$this->assertSame( '', $job->booked() );
	}
}
