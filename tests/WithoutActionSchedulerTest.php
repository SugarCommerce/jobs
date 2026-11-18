<?php
/**
 * Tests for a site where Action Scheduler could not load.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use SugarCommerce\Jobs\Scheduler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * No scheduler: work runs where it stands, or is left for the caller.
 *
 * Run on their own, with WITHOUT_ACTION_SCHEDULER set, so that none of its
 * functions exist -- `composer test` does both runs. A function cannot be
 * undefined once it is, so the two cannot share a process.
 */
#[Group( 'without-action-scheduler' )]
final class WithoutActionSchedulerTest extends TestCase {

	/**
	 * Empty the registries; skip in the run that has a scheduler.
	 */
	protected function setUp(): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Runs without Action Scheduler: WITHOUT_ACTION_SCHEDULER=1.' );
		}

		jobs_reset();
	}

	/**
	 * It says so.
	 */
	public function test_it_is_not_available(): void {
		$this->assertFalse( Scheduler::available() );
	}

	/**
	 * A deferred job runs now, with its id and attempt.
	 */
	public function test_a_deferred_job_runs_now(): void {
		$ran   = [];
		$queue = Scheduler::group( 'myplugin' );

		$queue->job(
			'myplugin_sync',
			static function ( $id, $attempt ) use ( &$ran ): void {
				$ran[] = [ $id, $attempt ];
			}
		);

		$queue->defer( 'myplugin_sync', 12 );

		$this->assertSame( [ [ 12, 1 ] ], $ran );
	}

	/**
	 * A job nobody declared has nothing to run, and nothing breaks.
	 */
	public function test_an_undeclared_job_does_nothing(): void {
		Scheduler::group( 'myplugin' )->defer( 'myplugin_unknown', 12 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A failure is given up on at once: the same second fails the same way.
	 */
	public function test_a_failure_is_not_retried_in_the_same_request(): void {
		$runs    = 0;
		$gave_up = 0;
		$queue   = Scheduler::group(
			'myplugin',
			[
				'gave_up' => static function () use ( &$gave_up ): void {
					++$gave_up;
				},
			]
		);

		$queue->job(
			'myplugin_sync',
			static function ( $id, $attempt ) use ( &$runs, $queue ): void {
				++$runs;

				$queue->failed( 'myplugin_sync', $id, $attempt, 0 );
			}
		);

		$queue->defer( 'myplugin_sync', 12 );

		$this->assertSame( 1, $runs );
		$this->assertSame( 1, $gave_up );
	}

	/**
	 * The caller's own actions are its to run.
	 */
	public function test_single_actions_are_refused(): void {
		$queue = Scheduler::group( 'myplugin' );

		$this->assertFalse( $queue->later( 'myplugin_deliver', [ 'x' ] ) );
		$this->assertFalse( $queue->once( 'myplugin_changed' ) );
		$this->assertFalse( $queue->scheduled( 'myplugin_deliver' ) );
		$this->assertNull( $queue->next_run( 'myplugin_deliver' ) );
		$this->assertFalse( $queue->unschedule_all() );
	}

	/**
	 * Nothing recurring is put anywhere, and nothing breaks.
	 */
	public function test_a_recurring_job_waits_for_a_scheduler(): void {
		$GLOBALS['jobs_did']['init'] = 1;

		$this->assertTrue( Scheduler::group( 'myplugin' )->recurring( 'myplugin_sweep', DAY_IN_SECONDS, static fn() => null ) );
		$this->assertFalse( Scheduler::group( 'myplugin' )->schedule_recurring( 'myplugin_sweep' ) );
	}

	/**
	 * A wall-clock job is not booked, and the note of it is not written.
	 */
	public function test_a_wall_clock_job_is_not_booked(): void {
		$job = Scheduler::group( 'myplugin' )->at( 'myplugin_digest', static fn() => null, [ 'time' => '08:00', 'every' => 'day' ] );

		$job->ensure( true );

		$this->assertFalse( $job->book( 'day' ) );
		$this->assertSame( '', $job->booked() );
	}

	/**
	 * load() requires the first copy it can read, once.
	 */
	public function test_load_requires_the_first_readable_copy(): void {
		$dir  = sys_get_temp_dir() . '/cron-load-' . bin2hex( random_bytes( 4 ) );
		$file = $dir . '/action-scheduler.php';

		mkdir( $dir );
		file_put_contents( $file, '<?php $GLOBALS["cron_loaded"] = ( $GLOBALS["cron_loaded"] ?? 0 ) + 1;' );

		try {
			$this->assertFalse( Scheduler::load( $dir . '/missing.php' ) );
			$this->assertTrue( Scheduler::load( $dir . '/missing.php', $file ) );
			$this->assertTrue( Scheduler::load( $file ) );
			$this->assertSame( 1, $GLOBALS['cron_loaded'] );
		} finally {
			unlink( $file );
			rmdir( $dir );
		}
	}
}
