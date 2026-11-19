<?php
/**
 * Retry schedule tests.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Jobs\Retry;
use WP_Error;

/**
 * One answer to "try again, and when?" for the scheduler and the queue.
 */
final class RetryTest extends TestCase {

	/**
	 * The default: five waits, six tries.
	 */
	public function test_the_default_is_five_waits_and_six_tries(): void {
		$retry = new Retry();

		$this->assertSame( Retry::WAITS, $retry->waits() );
		$this->assertFalse( $retry->exhausted( 5 ) );
		$this->assertTrue( $retry->exhausted( 6 ) );
		$this->assertEqualsWithDelta( 60, $retry->delay( 1 ), 6 );
		$this->assertEqualsWithDelta( 21600, $retry->delay( 5 ), 2160 );
	}

	/**
	 * A stated wait is spread a tenth either way, so a thousand jobs that
	 * failed in one outage do not all come back in the same second.
	 */
	public function test_a_stated_wait_is_spread_by_default(): void {
		$retry = new Retry( [ 600 ] );
		$waits = [];

		for ( $i = 0; $i < 200; $i++ ) {
			$waits[] = $retry->delay( 1 );
		}

		$this->assertSame( Retry::JITTER, $retry->jitter() );
		$this->assertGreaterThanOrEqual( 540, min( $waits ) );
		$this->assertLessThanOrEqual( 660, max( $waits ) );
		$this->assertGreaterThan( 20, count( array_unique( $waits ) ), 'Spread, not one second for all of them.' );
	}

	/**
	 * Nought waits exactly; more than a hundred is a hundred; a spread
	 * never makes a wait of nought.
	 */
	public function test_jitter_is_bounded(): void {
		$this->assertSame( 600, ( new Retry( [ 600 ], jitter: 0 ) )->delay( 1 ) );
		$this->assertSame( 0, ( new Retry( [ 600 ], jitter: -5 ) )->jitter() );
		$this->assertSame( 100, ( new Retry( [ 600 ], jitter: 250 ) )->jitter() );
		$this->assertSame( 9, ( new Retry( [ 9 ] ) )->delay( 1 ), 'Too short to spread by a tenth.' );

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertGreaterThanOrEqual( 1, ( new Retry( [ 2 ], jitter: 100 ) )->delay( 1 ) );
		}
	}

	/**
	 * Configuration states the spread, and a job keeps its group's.
	 */
	public function test_jitter_comes_from_configuration(): void {
		$base = Retry::from( [ 'jitter' => 0 ] );

		$this->assertSame( 0, $base->jitter() );
		$this->assertSame( 0, Retry::from( [ 'retry' => [ 30 ] ], $base )->jitter(), 'Inherited.' );
		$this->assertSame( 25, Retry::from( [ 'jitter' => 25 ], $base )->jitter() );
		$this->assertSame( 5, Retry::from( [ 'retry' => new Retry( [ 60 ] ), 'jitter' => 5 ] )->jitter() );
	}

	/**
	 * Past the last wait, the last wait again.
	 */
	public function test_the_last_wait_stands_for_any_after_it(): void {
		$retry = new Retry( [ 60, 300 ], 10, jitter: 0 );

		$this->assertSame( 300, $retry->delay( 2 ) );
		$this->assertSame( 300, $retry->delay( 9 ) );
		$this->assertFalse( $retry->exhausted( 9 ) );
		$this->assertTrue( $retry->exhausted( 10 ) );
	}

	/**
	 * Only whole positive seconds are waits.
	 */
	public function test_waits_are_cleaned(): void {
		$this->assertSame( [ 60, 300 ], ( new Retry( [ 60, -5, 0, '300', 'soon', 1.5 ] ) )->waits() );
		$this->assertSame( 0, ( new Retry( [] ) )->delay( 1 ) );
		$this->assertTrue( ( new Retry( [] ) )->exhausted( 1 ), 'No waits is one try.' );
	}

	/**
	 * A Retry-After wins, up to a day.
	 */
	public function test_a_retry_after_is_honoured_up_to_a_day(): void {
		$retry = new Retry( [ 60 ] );

		$this->assertSame( 120, $retry->delay( 1, 120 ), 'Exactly: the other end knows, so it is not spread.' );
		$this->assertSame( Retry::MAX_RETRY_AFTER, $retry->delay( 1, 7 * Retry::MAX_RETRY_AFTER ) );
		$this->assertSame( 60, ( new Retry( [ 60 ], jitter: 0 ) )->delay( 1, 0 ) );
	}

	/**
	 * The status a failure carries, however it is carried.
	 */
	public function test_a_status_is_read_from_any_shape(): void {
		$this->assertSame( 429, Retry::status( 429 ) );
		$this->assertSame( 503, Retry::status( new WP_Error( 'http', 'down', [ 'status' => 503 ] ) ) );
		$this->assertSame( 0, Retry::status( new WP_Error( 'http', 'down', 'not an array' ) ) );
		$this->assertSame( 400, Retry::status( [ 'status' => 400 ] ) );
		$this->assertSame( 0, Retry::status( 'timed out' ) );
	}

	/**
	 * A refusal is not retried, however many attempts are left.
	 */
	public function test_again_needs_a_transient_failure_and_attempts_left(): void {
		$retry = new Retry( [ 60, 60 ] );

		$this->assertTrue( $retry->again( 503, 1 ) );
		$this->assertTrue( $retry->again( 408, 2 ) );
		$this->assertFalse( $retry->again( 503, 3 ) );
		$this->assertFalse( $retry->again( 422, 1 ) );
		$this->assertFalse( $retry->again( 410, 1 ) );
	}

	/**
	 * A retry_if of one's own decides instead.
	 */
	public function test_retry_if_decides_what_is_worth_retrying(): void {
		$retry = new Retry( [ 60 ], null, static fn( $error ): bool => 'flaky' === $error );

		$this->assertTrue( $retry->again( 'flaky', 1 ) );
		$this->assertFalse( $retry->again( 503, 1 ) );
	}

	/**
	 * A policy object is asked for the count and the wait, and its own
	 * should_retry() decides when nothing else was given.
	 */
	public function test_a_policy_object_is_asked(): void {
		$policy = new class() {
			/**
			 * Spent after three.
			 *
			 * @param int $attempt The attempt.
			 *
			 * @return bool
			 */
			public function exhausted( int $attempt ): bool {
				return $attempt >= 3;
			}

			/**
			 * Ten seconds a go.
			 *
			 * @param int $attempt The attempt.
			 *
			 * @return int
			 */
			public function delay( int $attempt ): int {
				return 10 * $attempt;
			}

			/**
			 * Only a 418, to show it is the one asked.
			 *
			 * @param int $status  The status.
			 * @param int $attempt The attempt.
			 *
			 * @return bool
			 */
			public function should_retry( int $status, int $attempt ): bool {
				return 418 === $status && $attempt < 3;
			}
		};

		$retry = new Retry( $policy );

		$this->assertSame( 20, $retry->delay( 2 ) );
		$this->assertTrue( $retry->exhausted( 3 ) );
		$this->assertTrue( $retry->again( 418, 1 ) );
		$this->assertFalse( $retry->again( 503, 1 ) );
		$this->assertSame( [], $retry->waits() );

		$own = new Retry( $policy, null, static fn(): bool => true );

		$this->assertTrue( $own->again( 503, 1 ), 'retry_if wins over should_retry().' );
		$this->assertFalse( $own->again( 503, 3 ) );
	}

	/**
	 * Configuration inherits what it leaves out.
	 */
	public function test_from_inherits_what_is_left_out(): void {
		$group = Retry::from(
			[
				'retry'    => [ 30, 90 ],
				'attempts' => 5,
			]
		);

		$this->assertSame( [ 30, 90 ], $group->waits() );
		$this->assertTrue( $group->exhausted( 5 ) );

		// Waits of its own, no count: one more than its waits.
		$own = Retry::from( [ 'retry' => [ 10 ] ], $group );
		$this->assertTrue( $own->exhausted( 2 ) );

		// A count of its own: the group's waits.
		$count = Retry::from( [ 'attempts' => 2 ], $group );
		$this->assertSame( [ 30, 90 ], $count->waits() );
		$this->assertTrue( $count->exhausted( 2 ) );

		// Nothing: the group's all.
		$same = Retry::from( [], $group );
		$this->assertFalse( $same->exhausted( 4 ) );

		// A Retry given whole is used whole.
		$given = new Retry( [ 5 ] );
		$this->assertSame( $given, Retry::from( [ 'retry' => $given ], $group ) );
	}
}
