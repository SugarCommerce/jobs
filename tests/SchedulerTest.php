<?php
/**
 * Action Scheduler engine tests.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use SugarCommerce\Jobs\Retry;
use SugarCommerce\Jobs\Scheduler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deferred, retried, recurring work in one plugin's group.
 *
 * What these protect is mostly the shape of what lands in somebody's queue:
 * the hook, the arguments and the group. A plugin that ships and then
 * changes any of the three orphans every action already waiting.
 */
final class SchedulerTest extends TestCase {

	/**
	 * Empty the queue and the registries.
	 */
	protected function setUp(): void {
		jobs_reset();
	}

	/**
	 * Every pending action.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function queued(): array {
		return $GLOBALS['as_actions'];
	}

	/**
	 * A failure with a status, as WordPress reports one.
	 *
	 * @param int $status The HTTP status, 0 for none.
	 *
	 * @return object
	 */
	private function error( int $status ): object {
		return new class( $status ) {
			/**
			 * The status.
			 *
			 * @param int $status The status.
			 */
			public function __construct( private int $status ) {}

			/**
			 * The data a WP_Error carries.
			 *
			 * @return array<string, int>
			 */
			public function get_error_data(): array {
				return [ 'status' => $this->status ];
			}
		};
	}

	/**
	 * A deferred job lands as [ id, attempt ] in the plugin's group.
	 */
	public function test_a_deferred_job_is_queued_with_its_id_and_attempt(): void {
		Scheduler::group( 'myplugin' )->defer( 'myplugin_sync', 12 );

		$queued = $this->queued();

		$this->assertCount( 1, $queued );
		$this->assertSame( 'myplugin_sync', $queued[0]['hook'] );
		$this->assertSame( [ 12, 1 ], $queued[0]['args'] );
		$this->assertSame( 'myplugin', $queued[0]['group'] );
		$this->assertEqualsWithDelta( time(), $queued[0]['timestamp'], 2 );
	}

	/**
	 * Saving three times queues one job.
	 */
	public function test_a_first_attempt_is_deduplicated(): void {
		$queue = Scheduler::group( 'myplugin' );

		$queue->defer( 'myplugin_sync', 12 );
		$queue->defer( 'myplugin_sync', 12 );
		$queue->defer( 'myplugin_sync', 12 );

		$this->assertCount( 1, $this->queued() );
	}

	/**
	 * Two different things are two jobs.
	 */
	public function test_different_ids_are_different_jobs(): void {
		$queue = Scheduler::group( 'myplugin' );

		$queue->defer( 'myplugin_sync', 12 );
		$queue->defer( 'myplugin_sync', 13 );

		$this->assertCount( 2, $this->queued() );
	}

	/**
	 * A retry is the one job, already counted, and never deduplicated.
	 */
	public function test_a_retry_is_not_deduplicated(): void {
		$queue = Scheduler::group( 'myplugin' );

		$queue->defer( 'myplugin_sync', 12 );
		$queue->defer( 'myplugin_sync', 12, 2, 60 );

		$this->assertCount( 2, $this->queued() );
		$this->assertSame( [ 12, 2 ], $this->queued()[1]['args'] );
		$this->assertEqualsWithDelta( time() + 60, $this->queued()[1]['timestamp'], 2 );
	}

	/**
	 * The same hook in another plugin's group is another plugin's job.
	 */
	public function test_groups_do_not_deduplicate_each_other(): void {
		Scheduler::group( 'one' )->defer( 'shared_sync', 12 );
		Scheduler::group( 'two' )->defer( 'shared_sync', 12 );

		$this->assertCount( 2, $this->queued() );
	}

	/**
	 * Nothing to do about nothing.
	 *
	 * @param int|string $id What a caller might pass for "no row".
	 */
	#[DataProvider( 'nothing' )]
	public function test_an_empty_id_queues_nothing( int|string $id ): void {
		Scheduler::group( 'myplugin' )->defer( 'myplugin_sync', $id );

		$this->assertSame( [], $this->queued() );
	}

	/**
	 * Ids that are not a thing.
	 *
	 * @return array<string, array{0: int|string}>
	 */
	public static function nothing(): array {
		return [
			'zero'         => [ 0 ],
			'negative'     => [ -3 ],
			'empty string' => [ '' ],
		];
	}

	/**
	 * A remote reference is carried, for the job that outlives its row.
	 */
	public function test_a_string_reference_is_carried(): void {
		Scheduler::group( 'myplugin' )->defer( 'myplugin_archive', 'prod_123' );

		$this->assertSame( [ 'prod_123', 1 ], $this->queued()[0]['args'] );
	}

	/**
	 * The group is one instance, so its jobs are known wherever it is asked.
	 */
	public function test_a_group_is_one_instance(): void {
		$this->assertSame( Scheduler::group( 'myplugin' ), Scheduler::group( 'myplugin' ) );
		$this->assertNotSame( Scheduler::group( 'myplugin' ), Scheduler::group( 'other' ) );
		$this->assertSame( 'myplugin', Scheduler::group( 'myplugin' )->name() );
	}

	/**
	 * Declaring a job attaches it, for both of its arguments.
	 */
	public function test_a_job_is_attached(): void {
		$ran = [];

		Scheduler::group( 'myplugin' )->job(
			'myplugin_sync',
			static function ( $id, $attempt ) use ( &$ran ): void {
				$ran[] = [ $id, $attempt ];
			}
		);

		do_action( 'myplugin_sync', 12, 3 );

		$this->assertSame( [ [ 12, 3 ] ], $ran );
	}

	/**
	 * A failure worth retrying is retried after the stated wait.
	 */
	public function test_a_transient_failure_is_retried_after_the_wait(): void {
		$queue = Scheduler::group( 'myplugin', [ 'retry' => [ 60, 300, 900 ] ] );

		$this->assertTrue( $queue->failed( 'myplugin_sync', 12, 2, $this->error( 503 ) ) );

		$this->assertSame( [ 12, 3 ], $this->queued()[0]['args'] );
		$this->assertEqualsWithDelta( time() + 300, $this->queued()[0]['timestamp'], 2 );
	}

	/**
	 * Which failures are worth another go, by default.
	 *
	 * @param int  $status   The status.
	 * @param bool $expected Whether it is retried.
	 */
	#[DataProvider( 'statuses' )]
	public function test_which_failures_are_retried( int $status, bool $expected ): void {
		$queue = Scheduler::group( 'myplugin' );

		$this->assertSame( $expected, $queue->failed( 'myplugin_sync', 12, 1, $this->error( $status ) ) );
		$this->assertSame( $expected, Retry::transient( $status ) );
	}

	/**
	 * Statuses and whether each is worth another go.
	 *
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public static function statuses(): array {
		return [
			'no answer'    => [ 0, true ],
			'rate limited' => [ 429, true ],
			'server error' => [ 500, true ],
			'unavailable'  => [ 503, true ],
			'bad request'  => [ 400, false ],
			'not found'    => [ 404, false ],
			'unprocessed'  => [ 422, false ],
			'just below'   => [ 499, false ],
		];
	}

	/**
	 * Something that is not a failure with a status is no answer at all.
	 */
	public function test_a_failure_without_a_status_is_retried(): void {
		$this->assertTrue( Retry::transient( 'timed out' ) );
		$this->assertTrue( Retry::transient( new \stdClass() ) );
	}

	/**
	 * The last attempt is the last.
	 */
	public function test_retries_stop_at_the_attempt_limit(): void {
		$gave_up = [];
		$queue   = Scheduler::group(
			'myplugin',
			[
				'retry'    => [ 60, 300 ],
				'attempts' => 3,
				'gave_up'  => static function ( $error, $id, $attempt, $context, $hook ) use ( &$gave_up ): void {
					$gave_up[] = [ $id, $attempt, $context, $hook ];
				},
			]
		);

		$this->assertTrue( $queue->failed( 'myplugin_sync', 12, 2, $this->error( 0 ) ) );
		$this->assertFalse( $queue->failed( 'myplugin_sync', 12, 3, $this->error( 0 ), [ 'label' => 'Kit' ] ) );

		$this->assertCount( 1, $this->queued() );
		$this->assertSame( [ [ 12, 3, [ 'label' => 'Kit' ], 'myplugin_sync' ] ], $gave_up );
	}

	/**
	 * A refusal is given up on at once, and reported once.
	 */
	public function test_a_refusal_is_given_up_at_once(): void {
		$told  = 0;
		$queue = Scheduler::group(
			'myplugin',
			[
				'gave_up' => static function () use ( &$told ): void {
					++$told;
				},
			]
		);

		$this->assertFalse( $queue->failed( 'myplugin_sync', 12, 1, $this->error( 400 ) ) );

		$this->assertSame( [], $this->queued() );
		$this->assertSame( 1, $told );
	}

	/**
	 * A job's own gave_up is told in place of the group's; a job that names
	 * none is the group's.
	 */
	public function test_a_jobs_own_gave_up_wins(): void {
		$told  = [];
		$queue = Scheduler::group(
			'myplugin',
			[
				'gave_up' => static function () use ( &$told ): void {
					$told[] = 'group';
				},
			]
		);

		$queue->job(
			'myplugin_own',
			static fn() => null,
			[
				'gave_up' => static function () use ( &$told ): void {
					$told[] = 'own';
				},
			]
		);
		$queue->job( 'myplugin_plain', static fn() => null );

		$queue->failed( 'myplugin_own', 12, 1, $this->error( 400 ) );
		$queue->failed( 'myplugin_plain', 12, 1, $this->error( 400 ) );

		$this->assertSame( [ 'own', 'group' ], $told );
	}

	/**
	 * Retried attempts are not reported: only the end is.
	 */
	public function test_a_retried_failure_is_not_reported(): void {
		$told  = 0;
		$queue = Scheduler::group(
			'myplugin',
			[
				'gave_up' => static function () use ( &$told ): void {
					++$told;
				},
			]
		);

		$queue->failed( 'myplugin_sync', 12, 1, $this->error( 500 ) );

		$this->assertSame( 0, $told );
	}

	/**
	 * A stated schedule is waited exactly, its last wait standing after it.
	 */
	public function test_a_stated_schedule_is_waited_exactly(): void {
		$queue = Scheduler::group( 'myplugin', [ 'retry' => [ 60, 300, 900, 3600, 21600 ], 'attempts' => 5 ] );

		$this->assertSame( 60, $queue->retry_delay( 'myplugin_sync', 1 ) );
		$this->assertSame( 300, $queue->retry_delay( 'myplugin_sync', 2 ) );
		$this->assertSame( 900, $queue->retry_delay( 'myplugin_sync', 3 ) );
		$this->assertSame( 3600, $queue->retry_delay( 'myplugin_sync', 4 ) );
		$this->assertSame( 21600, $queue->retry_delay( 'myplugin_sync', 5 ) );
		$this->assertSame( 21600, $queue->retry_delay( 'myplugin_sync', 9 ) );

		$this->assertFalse( $queue->exhausted( 'myplugin_sync', 4 ) );
		$this->assertTrue( $queue->exhausted( 'myplugin_sync', 5 ) );
		$this->assertTrue( $queue->exhausted( 'myplugin_sync', 6 ) );
	}

	/**
	 * Without a count, one more attempt than there are waits.
	 */
	public function test_attempts_default_to_one_more_than_the_waits(): void {
		$queue = Scheduler::group( 'myplugin', [ 'retry' => [ 60, 300 ] ] );

		$this->assertFalse( $queue->exhausted( 'myplugin_sync', 2 ) );
		$this->assertTrue( $queue->exhausted( 'myplugin_sync', 3 ) );
	}

	/**
	 * Waits that are not whole positive seconds are dropped.
	 */
	public function test_only_real_waits_are_kept(): void {
		$queue = Scheduler::group( 'myplugin', [ 'retry' => [ 60, 0, -5, 'soon', '120', 1.5 ] ] );

		$this->assertSame( 60, $queue->retry_delay( 'myplugin_sync', 1 ) );
		$this->assertSame( 120, $queue->retry_delay( 'myplugin_sync', 2 ) );
		$this->assertTrue( $queue->exhausted( 'myplugin_sync', 3 ) );
	}

	/**
	 * A job states its own schedule over the group's.
	 */
	public function test_a_job_overrides_the_group(): void {
		$queue = Scheduler::group( 'myplugin', [ 'retry' => [ 60 ], 'attempts' => 5 ] );

		$queue->job( 'myplugin_push', static fn() => null, [ 'retry' => [ 30, 120, 600 ] ] );

		$this->assertSame( 120, $queue->retry_delay( 'myplugin_push', 2 ) );
		$this->assertTrue( $queue->exhausted( 'myplugin_push', 4 ), 'Its own waits, so its own count.' );
		$this->assertFalse( $queue->exhausted( 'myplugin_sync', 4 ), 'Other jobs keep the group count.' );
	}

	/**
	 * Which failures pass is the job's to say.
	 */
	public function test_a_job_decides_what_is_worth_retrying(): void {
		$queue = Scheduler::group( 'myplugin' );

		$queue->job( 'myplugin_sync', static fn() => null, [ 'retry_if' => static fn( $error ): bool => 'later' === $error ] );

		$this->assertFalse( $queue->failed( 'myplugin_sync', 12, 1, 'never' ) );
		$this->assertTrue( $queue->failed( 'myplugin_sync', 12, 1, 'later' ) );
	}

	/**
	 * A retry policy object is asked instead of a list.
	 */
	public function test_a_policy_object_is_asked(): void {
		$policy = new class() {
			/**
			 * Whether the attempts are used.
			 *
			 * @param int $attempt Attempts so far.
			 *
			 * @return bool
			 */
			public function exhausted( int $attempt ): bool {
				return $attempt >= 2;
			}

			/**
			 * The wait.
			 *
			 * @param int $attempt Attempts so far.
			 *
			 * @return int
			 */
			public function delay( int $attempt ): int {
				return 42 * $attempt;
			}
		};

		$queue = Scheduler::group( 'myplugin', [ 'retry' => $policy ] );

		$this->assertSame( 42, $queue->retry_delay( 'myplugin_sync', 1 ) );
		$this->assertTrue( $queue->failed( 'myplugin_sync', 12, 1, 0 ) );
		$this->assertFalse( $queue->failed( 'myplugin_sync', 12, 2, 0 ) );
	}

	/**
	 * later() queues every time: each one is its own piece of work.
	 */
	public function test_later_does_not_deduplicate(): void {
		$queue = Scheduler::group( 'myplugin' );

		$this->assertTrue( $queue->later( 'myplugin_deliver', [ 'a', 1 ], 30 ) );
		$this->assertTrue( $queue->later( 'myplugin_deliver', [ 'a', 1 ], 30 ) );

		$this->assertCount( 2, $this->queued() );
		$this->assertEqualsWithDelta( time() + 30, $this->queued()[0]['timestamp'], 2 );
	}

	/**
	 * once() queues one, whatever happens in the meantime.
	 */
	public function test_once_deduplicates_on_the_arguments(): void {
		$queue = Scheduler::group( 'myplugin' );

		$queue->once( 'myplugin_changed', [], 60 );
		$queue->once( 'myplugin_changed', [], 60 );
		$queue->once( 'myplugin_changed', [ 'other' ], 60 );

		$this->assertCount( 2, $this->queued() );
	}

	/**
	 * A recurring job is put in the calendar on `init`, and once.
	 */
	public function test_a_recurring_job_is_scheduled_once_on_init(): void {
		$queue = Scheduler::group( 'myplugin' );

		$this->assertTrue( $queue->recurring( 'myplugin_sweep', DAY_IN_SECONDS, static fn() => null ) );
		$this->assertSame( [], $this->queued(), 'Scheduled before the store is ready.' );

		jobs_fire_init();

		$queued = $this->queued();

		$this->assertCount( 1, $queued );
		$this->assertSame( DAY_IN_SECONDS, $queued[0]['interval'] );
		$this->assertSame( [], $queued[0]['args'] );
		$this->assertSame( 'myplugin', $queued[0]['group'] );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $queued[0]['timestamp'], 2 );

		// The next request.
		$queue->schedule_recurring( 'myplugin_sweep' );

		$this->assertCount( 1, $this->queued() );
	}

	/**
	 * Declared after `init`, it is scheduled at once.
	 */
	public function test_a_recurring_job_declared_late_is_scheduled_at_once(): void {
		$GLOBALS['jobs_did']['init'] = 1;

		Scheduler::group( 'myplugin' )->recurring( 'myplugin_sweep', DAY_IN_SECONDS, static fn() => null, [ 'first' => 0 ] );

		$this->assertCount( 1, $this->queued() );
		$this->assertEqualsWithDelta( time(), $this->queued()[0]['timestamp'], 2 );
	}

	/**
	 * An interval of nothing is refused.
	 */
	public function test_an_impossible_interval_is_refused(): void {
		$this->assertFalse( Scheduler::group( 'myplugin' )->recurring( 'myplugin_sweep', 0, static fn() => null ) );
		$this->assertFalse( Scheduler::group( 'myplugin' )->recurring( '', 60, static fn() => null ) );
	}

	/**
	 * The recurring job's callback is attached.
	 */
	public function test_a_recurring_job_is_attached(): void {
		$ran = 0;

		Scheduler::group( 'myplugin' )->recurring(
			'myplugin_sweep',
			DAY_IN_SECONDS,
			static function () use ( &$ran ): void {
				++$ran;
			}
		);

		do_action( 'myplugin_sweep' );

		$this->assertSame( 1, $ran );
	}

	/**
	 * Uninstall takes the whole group and nothing else.
	 */
	public function test_uninstall_clears_the_group_only(): void {
		Scheduler::group( 'myplugin' )->defer( 'myplugin_sync', 12 );
		Scheduler::group( 'myplugin' )->later( 'myplugin_deliver', [ 'x' ] );
		Scheduler::group( 'other' )->defer( 'other_sync', 12 );

		$this->assertTrue( Scheduler::group( 'myplugin' )->unschedule_all() );

		$this->assertCount( 1, $this->queued() );
		$this->assertSame( 'other', $this->queued()[0]['group'] );
	}

	/**
	 * What is next, and whether it is there.
	 */
	public function test_next_run_and_scheduled(): void {
		$queue = Scheduler::group( 'myplugin' );

		$this->assertNull( $queue->next_run( 'myplugin_sync' ) );
		$this->assertFalse( $queue->scheduled( 'myplugin_sync' ) );

		$queue->defer( 'myplugin_sync', 12, 1, 600 );

		$this->assertEqualsWithDelta( time() + 600, $queue->next_run( 'myplugin_sync' ), 2 );
		$this->assertTrue( $queue->scheduled( 'myplugin_sync' ) );
		$this->assertTrue( $queue->scheduled( 'myplugin_sync', [ 12, 1 ] ) );
		$this->assertFalse( $queue->scheduled( 'myplugin_sync', [ 13, 1 ] ) );
	}

	/**
	 * Unscheduling matches arguments as written.
	 */
	public function test_unschedule_matches_the_arguments(): void {
		$queue = Scheduler::group( 'myplugin' );

		$queue->defer( 'myplugin_sync', 12 );
		$queue->defer( 'myplugin_sync', 13 );

		$queue->unschedule( 'myplugin_sync', [ 12, 1 ] );

		$this->assertCount( 1, $this->queued() );
		$this->assertSame( [ 13, 1 ], $this->queued()[0]['args'] );
	}

	/**
	 * With a copy already running, load() requires nothing.
	 */
	public function test_load_leaves_a_running_copy_alone(): void {
		$this->assertTrue( Scheduler::load( __DIR__ . '/does-not-exist.php' ) );
	}

	/**
	 * The hooks each kind of job was declared under.
	 */
	public function test_hooks_lists_what_was_declared(): void {
		$queue = Scheduler::group( 'myplugin' );

		$queue->job( 'myplugin_sync', static fn() => null );
		$queue->recurring( 'myplugin_sweep', DAY_IN_SECONDS, static fn() => null );
		$queue->at( 'myplugin_summary', static fn() => null, [ 'time' => '08:00', 'every' => 'day' ] );

		$this->assertSame(
			[
				'jobs'      => [ 'myplugin_sync' ],
				'recurring' => [ 'myplugin_sweep' ],
				'local'     => [ 'myplugin_summary' ],
			],
			$queue->hooks()
		);
	}
}
