<?php
/**
 * Run lock tests.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Budget;
use SugarCommerce\Jobs\Lock;
use SugarCommerce\Jobs\Pass;
use SugarCommerce\Jobs\Steps;

/**
 * One run at a time, a dead run's lock taken over when its lease ends, and
 * the pieces that bound a run: a budget, a pass and steps that cannot stop
 * each other.
 */
final class LockTest extends TestCase {

	/**
	 * Now, for every test.
	 */
	private const NOW = 1_800_000_000;

	/**
	 * A fresh options table and a stopped clock.
	 */
	protected function setUp(): void {
		jobs_reset();

		Clock::freeze( self::NOW );
	}

	/**
	 * The clock runs again.
	 */
	protected function tearDown(): void {
		Clock::reset();
	}

	/**
	 * The lock's row, as stored.
	 *
	 * @return string|null
	 */
	private function stored(): ?string {
		return $GLOBALS['wpdb']->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'myplugin_drain'" );
	}

	/**
	 * One holder at a time.
	 */
	public function test_a_held_lock_cannot_be_taken(): void {
		$lock = Lock::acquire( 'myplugin_drain' );

		$this->assertInstanceOf( Lock::class, $lock );
		$this->assertNull( Lock::acquire( 'myplugin_drain' ) );
		$this->assertStringStartsWith( (string) ( self::NOW + Lock::TTL ), (string) $this->stored() );
	}

	/**
	 * Released, it can be taken again.
	 */
	public function test_a_released_lock_is_free(): void {
		$this->assertTrue( Lock::acquire( 'myplugin_drain' )->release() );

		$this->assertNotNull( Lock::acquire( 'myplugin_drain' ) );
	}

	/**
	 * A dead run's lock is taken over when its lease ends, and not before.
	 */
	public function test_an_expired_lock_is_taken_over_once(): void {
		Lock::acquire( 'myplugin_drain', 60 );

		Clock::advance( 60 );
		$this->assertNull( Lock::acquire( 'myplugin_drain' ) );

		Clock::advance( 1 );
		$this->assertNotNull( Lock::acquire( 'myplugin_drain' ) );
		$this->assertNull( Lock::acquire( 'myplugin_drain' ), 'One owner, however many race.' );
	}

	/**
	 * A run that outlived its lease cannot release the next run's lock.
	 */
	public function test_a_late_holder_cannot_release_the_next_ones_lock(): void {
		$late = Lock::acquire( 'myplugin_drain', 60 );

		Clock::advance( 61 );

		$next = Lock::acquire( 'myplugin_drain', 60 );

		$this->assertFalse( $late->release() );
		$this->assertFalse( $late->extend() );
		$this->assertNull( Lock::acquire( 'myplugin_drain' ) );
		$this->assertTrue( $next->release() );
	}

	/**
	 * A holder can keep it longer.
	 */
	public function test_a_holder_can_extend(): void {
		$lock = Lock::acquire( 'myplugin_drain', 60 );

		Clock::advance( 50 );
		$this->assertTrue( $lock->extend( 60 ) );

		Clock::advance( 50 );
		$this->assertNull( Lock::acquire( 'myplugin_drain' ), 'Still held: the lease was moved on.' );
		$this->assertTrue( $lock->release() );
	}

	/**
	 * Work under the lock runs, or is skipped while it is held; the lock is
	 * let go even when the work throws.
	 */
	public function test_with_runs_skips_and_always_releases(): void {
		$this->assertSame( 'ran', Lock::with( 'myplugin_drain', static fn(): string => 'ran' ) );

		$held = Lock::acquire( 'myplugin_drain' );
		$this->assertSame( 'skipped', Lock::with( 'myplugin_drain', static fn(): string => 'ran', 'skipped' ) );
		$held->release();

		try {
			Lock::with(
				'myplugin_drain',
				static function (): void {
					throw new RuntimeException( 'boom' );
				}
			);
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertNull( $this->stored(), 'A throw does not keep the lock.' );
	}

	/**
	 * A budget is unlimited at nothing, and spent once its time has gone.
	 */
	public function test_a_budget_runs_out(): void {
		$this->assertFalse( ( new Budget() )->spent() );
		$this->assertSame( INF, ( new Budget() )->left() );

		$budget = new Budget( 0.000001 );

		usleep( 10 );

		$this->assertTrue( $budget->spent() );
		$this->assertSame( 0.0, $budget->left() );
		$this->assertFalse( ( new Budget( 60 ) )->spent() );
	}

	/**
	 * A pass shares one count down a sweep, and stops at its budget.
	 */
	public function test_a_pass_counts_across_the_whole_sweep(): void {
		$pass  = new Pass( due: self::NOW, limit: 3 );
		$taken = 0;

		foreach ( [ 'a', 'b' ] as $customer ) {
			foreach ( [ 1, 2 ] as $row ) {
				if ( ! $pass->take() ) {
					break 2;
				}

				++$taken;
			}
		}

		$this->assertSame( 3, $taken );
		$this->assertTrue( $pass->over() );
		$this->assertSame( 0, $pass->left() );
		$this->assertSame( self::NOW, $pass->due );

		$spent = new Budget( 0.000001 );
		usleep( 10 );

		$this->assertFalse( ( new Pass( budget: $spent ) )->take() );
	}

	/**
	 * A step that throws is reported, counts as its fallback, and the next
	 * step runs.
	 */
	public function test_a_failing_step_does_not_stop_the_next(): void {
		$told  = [];
		$steps = new Steps(
			static function ( string $name, \Throwable $e ) use ( &$told ): void {
				$told[] = $name . ': ' . $e->getMessage();
			}
		);

		$first  = $steps->run(
			'mail',
			static function (): int {
				throw new RuntimeException( 'deadlock' );
			},
			-1
		);
		$second = $steps->run( 'links', static fn(): int => 7, 0 );

		$this->assertSame( -1, $first );
		$this->assertSame( 7, $second );
		$this->assertSame( [ 'mail: deadlock' ], $told );
	}
}
