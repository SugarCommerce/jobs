<?php
/**
 * Cron tests.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Cron;

/**
 * Scheduled work, and taking it away again.
 *
 * Two halves that happen at different times and go wrong separately. The
 * callback has to be attached on every request, because WP-Cron fires the
 * hook on some later request than the one that scheduled it — a callback
 * attached only at activation is an event that fires into an empty room for
 * ever, and nothing reports it. The scheduling has to happen only once,
 * because a hook in the queue twice runs the job twice.
 *
 * And the cleanup, which is the reason to have the library at all: an
 * orphaned cron event outlives the plugin that made it and fires into code
 * that is no longer there, on somebody else's site.
 */
final class CronTest extends TestCase {

	/**
	 * Empty the queue and the registries.
	 */
	protected function setUp(): void {
		jobs_reset();
	}

	/**
	 * Something for a job to run.
	 *
	 * Not called callback(): PHPUnit's Assert has a final method by that
	 * name, and overriding it is a fatal before a single test runs.
	 *
	 * @return callable
	 */
	private function job(): callable {
		return static fn(): bool => true;
	}

	/**
	 * Registering attaches the callback and queues the event.
	 */
	public function test_a_job_is_attached_and_queued(): void {
		$this->assertTrue( Cron::job( 'myplugin_sync', [ 'schedule' => 'daily', 'callback' => $this->job() ] ) );

		$this->assertArrayHasKey( 'myplugin_sync', $GLOBALS['jobs_hooks'] );
		$this->assertNotNull( Cron::next_run( 'myplugin_sync' ) );
	}

	/**
	 * Registering again attaches again and does not queue again.
	 *
	 * Both halves. The callback must be attached on every request or the
	 * event fires into nothing; the event must not be queued twice or the
	 * job runs twice.
	 */
	public function test_registering_twice_queues_once(): void {
		Cron::job( 'myplugin_sync', [ 'schedule' => 'daily', 'callback' => $this->job() ] );
		Cron::job( 'myplugin_sync', [ 'schedule' => 'daily', 'callback' => $this->job() ] );

		$this->assertCount( 2, $GLOBALS['jobs_hooks']['myplugin_sync'], 'The callback was not reattached.' );
		$this->assertCount( 1, $GLOBALS['cron_queue'], 'The event was queued twice.' );
	}

	/**
	 * A job with no schedule runs once.
	 */
	public function test_a_job_without_a_schedule_runs_once(): void {
		Cron::job( 'myplugin_once', [ 'callback' => $this->job() ] );

		$this->assertSame( '', $GLOBALS['cron_queue'][0]['recurrence'] );
	}

	/**
	 * A job with nothing to run is refused.
	 */
	public function test_a_job_without_a_callback_is_refused(): void {
		$this->assertFalse( Cron::job( 'myplugin_sync', [ 'schedule' => 'daily' ] ) );
		$this->assertSame( [], $GLOBALS['cron_queue'] );
	}

	/**
	 * A recurrence nothing registered is refused.
	 *
	 * WordPress schedules it happily and the event runs once and never
	 * again, with nothing anywhere to say why. Refusing is the only way the
	 * mistake is visible at the moment it is made.
	 */
	public function test_an_unknown_recurrence_is_refused(): void {
		$this->assertFalse( Cron::job( 'myplugin_sync', [ 'schedule' => 'fortnightly', 'callback' => $this->job() ] ) );

		Cron::schedule( 'fortnightly', 14 * DAY_IN_SECONDS, 'Every fortnight' );

		$this->assertTrue( Cron::job( 'myplugin_sync', [ 'schedule' => 'fortnightly', 'callback' => $this->job() ] ) );
	}

	/**
	 * Core's own recurrences are accepted without registering anything.
	 *
	 * @dataProvider coreScheduleProvider
	 *
	 * @param string $schedule One of core's four.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'coreScheduleProvider' )]
	public function test_cores_recurrences_are_known( string $schedule ): void {
		$this->assertTrue( Cron::has_schedule( $schedule ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function coreScheduleProvider(): array {
		$schedules = [ 'hourly', 'twicedaily', 'daily', 'weekly' ];

		return array_combine( $schedules, array_map( static fn( string $one ): array => [ $one ], $schedules ) );
	}

	/**
	 * A start time in the past is not used.
	 *
	 * Core refuses a non-positive timestamp outright, and a start that has
	 * been and gone is the commonest way a job is scheduled and never runs.
	 */
	public function test_a_missing_start_becomes_now(): void {
		Cron::job( 'myplugin_sync', [ 'schedule' => 'daily', 'callback' => $this->job(), 'start' => 0 ] );

		$this->assertGreaterThan( 0, $GLOBALS['cron_queue'][0]['timestamp'] );
	}

	/**
	 * A start time given is used.
	 */
	public function test_a_start_time_is_honoured(): void {
		$start = time() + HOUR_IN_SECONDS;

		Cron::job( 'myplugin_sync', [ 'schedule' => 'daily', 'callback' => $this->job(), 'start' => $start ] );

		$this->assertSame( $start, $GLOBALS['cron_queue'][0]['timestamp'] );
	}

	/**
	 * Removing a job clears every occurrence of it.
	 *
	 * The one that matters. wp_unschedule_event() removes one occurrence at
	 * one timestamp, so a hook that ended up in the queue twice — which is
	 * exactly the mess uninstall exists to tidy — was left half there.
	 */
	public function test_removing_a_job_clears_every_occurrence(): void {
		// Queued twice behind this library's back, as a bug elsewhere would.
		wp_schedule_event( time(), 'daily', 'myplugin_sync', [] );
		wp_schedule_event( time() + 60, 'daily', 'myplugin_sync', [] );

		$this->assertCount( 2, $GLOBALS['cron_queue'] );

		Cron::unschedule( 'myplugin_sync' );

		$this->assertSame( [], $GLOBALS['cron_queue'] );
	}

	/**
	 * Arguments identify an event, so only the matching one is cleared.
	 *
	 * The same hook scheduled for two different sites, or two different
	 * feeds, is two events — and clearing one must not take the other.
	 */
	public function test_only_the_matching_arguments_are_cleared(): void {
		Cron::job( 'myplugin_sync', [ 'schedule' => 'daily', 'callback' => $this->job(), 'args' => [ 'a' ] ] );
		wp_schedule_event( time(), 'daily', 'myplugin_sync', [ 'b' ] );

		Cron::unschedule( 'myplugin_sync', [ 'a' ] );

		$this->assertCount( 1, $GLOBALS['cron_queue'] );
		$this->assertSame( [ 'b' ], $GLOBALS['cron_queue'][0]['args'] );
	}

	/**
	 * Everything registered can be cleared at once.
	 */
	public function test_every_job_can_be_cleared(): void {
		Cron::job( 'myplugin_one', [ 'schedule' => 'daily', 'callback' => $this->job() ] );
		Cron::job( 'myplugin_two', [ 'callback' => $this->job() ] );

		$this->assertSame( 2, Cron::unschedule_all() );
		$this->assertSame( [], $GLOBALS['cron_queue'] );
	}

	/**
	 * A schedule is offered to WordPress through the filter.
	 */
	public function test_a_schedule_reaches_the_filter(): void {
		Cron::schedule( 'every_five_minutes', 5 * MINUTE_IN_SECONDS, 'Every five minutes' );

		$schedules = Cron::apply_schedules( [] );

		$this->assertSame( 300, $schedules['every_five_minutes']['interval'] );
		$this->assertSame( 'Every five minutes', $schedules['every_five_minutes']['display'] );
	}

	/**
	 * An existing recurrence is not replaced.
	 *
	 * A plugin quietly redefining what `daily` means, for every other plugin
	 * on the site, is what this avoids.
	 */
	public function test_an_existing_recurrence_is_left_alone(): void {
		Cron::schedule( 'daily', 60, 'Every minute, really' );

		$schedules = Cron::apply_schedules( [ 'daily' => [ 'interval' => DAY_IN_SECONDS, 'display' => 'Once Daily' ] ] );

		$this->assertSame( DAY_IN_SECONDS, $schedules['daily']['interval'] );
	}

	/**
	 * A nonsensical interval is refused.
	 */
	public function test_an_impossible_interval_is_refused(): void {
		$this->assertFalse( Cron::schedule( 'never', 0, 'Never' ) );
		$this->assertFalse( Cron::schedule( '', 60, 'Nameless' ) );
	}

	/**
	 * A display name is not required.
	 */
	public function test_a_schedule_without_a_display_name_uses_its_own(): void {
		Cron::schedule( 'every_five_minutes', 300 );

		$this->assertSame( 'every_five_minutes', Cron::apply_schedules( [] )['every_five_minutes']['display'] );
	}

	/**
	 * next_run answers null rather than false.
	 *
	 * wp_next_scheduled() returns false for "not queued" and a timestamp
	 * otherwise, and false is also what a timestamp of zero looks like to
	 * anything careless.
	 */
	public function test_next_run_is_null_when_nothing_is_queued(): void {
		$this->assertNull( Cron::next_run( 'myplugin_nothing' ) );
	}

	/**
	 * A start that has been and gone is now, not a moment in the past.
	 */
	public function test_a_past_start_becomes_now(): void {
		Clock::freeze( 1_800_000_000 );

		Cron::job( 'myplugin_sync', [ 'schedule' => 'daily', 'callback' => $this->job(), 'start' => 1_700_000_000 ] );

		Clock::reset();

		$this->assertSame( 1_800_000_000, $GLOBALS['cron_queue'][0]['timestamp'] );
	}

	/**
	 * A job given several arguments is handed all of them.
	 *
	 * add_action() defaults to one, so a job declared with two arguments
	 * used to get the first and lose the second.
	 */
	public function test_a_job_with_arguments_accepts_them_all(): void {
		Cron::job( 'myplugin_sync', [ 'callback' => $this->job(), 'args' => [ 'a', 'b' ] ] );

		$this->assertSame( [ 2 ], $GLOBALS['jobs_hook_args']['myplugin_sync'] );
		$this->assertSame( [ 'a', 'b' ], $GLOBALS['cron_queue'][0]['args'] );
	}

	/**
	 * Whether a job is queued.
	 */
	public function test_scheduled_says_whether_it_is_queued(): void {
		$this->assertFalse( Cron::scheduled( 'myplugin_sync' ) );

		Cron::job( 'myplugin_sync', [ 'schedule' => 'hourly', 'callback' => $this->job() ] );

		$this->assertTrue( Cron::scheduled( 'myplugin_sync' ) );
		$this->assertArrayHasKey( 'myplugin_sync', Cron::jobs() );
	}

	/**
	 * At uninstall nothing is declared, so a prefix finds every event the
	 * plugin ever queued -- whatever arguments an older version used -- and
	 * leaves everybody else's alone.
	 */
	public function test_uninstall_by_prefix_clears_what_was_never_declared(): void {
		wp_schedule_event( time(), 'daily', 'myplugin_sync', [] );
		wp_schedule_event( time(), 'daily', 'myplugin_sync', [ 'v1-argument' ] );
		wp_schedule_single_event( time() + 60, 'myplugin_report', [ 7 ] );
		wp_schedule_event( time(), 'daily', 'otherplugin_sync', [] );

		$this->assertSame( 2, Cron::unschedule_all( 'myplugin_' ) );

		$left = array_column( $GLOBALS['cron_queue'], 'hook' );

		$this->assertSame( [ 'otherplugin_sync' ], $left );
	}

	/**
	 * Without a prefix, only what was declared goes.
	 */
	public function test_uninstall_without_a_prefix_clears_what_was_declared(): void {
		wp_schedule_event( time(), 'daily', 'myplugin_orphan', [] );

		Cron::job( 'myplugin_sync', [ 'schedule' => 'daily', 'callback' => $this->job() ] );

		$this->assertSame( 1, Cron::unschedule_all() );
		$this->assertSame( [ 'myplugin_orphan' ], array_column( $GLOBALS['cron_queue'], 'hook' ) );
	}
}
