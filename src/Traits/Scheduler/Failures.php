<?php
/**
 * Failures: what happens when a job throws -- another try later, or giving up.
 *
 * @package     SugarCommerce\Jobs
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Traits\Scheduler;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Retry;

/**
 * Failures: what happens when a job throws -- another try later, or giving up.
 *
 * Each job's Retry decides how many tries and how far apart; the last
 * failure is handed to the gave-up callback.
 *
 * @since 1.0.0
 */
trait Failures {

	/**
	 * Decide what to do with a job that failed.
	 *
	 * Retried, when the failure is one that might not happen again and the
	 * job has attempts left. Otherwise handed to `gave_up` -- once, when it
	 * is over, because a line per attempt buries the one that matters under
	 * four that were always going to be retried.
	 *
	 * Never retried without a scheduler. There the job runs where it
	 * stands, and trying again in the same second fails the same way.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $hook    The job.
	 * @param int|string           $id      What it concerns.
	 * @param int                  $attempt Which attempt just failed.
	 * @param mixed                $error   Why: a WP_Error, a status code,
	 *                                      whatever `retry_if` reads.
	 * @param array<string, mixed> $context Passed to `gave_up`, for the
	 *                                      log: what to call the thing.
	 *
	 * @return bool Whether another attempt was queued.
	 */
	public function failed( string $hook, int|string $id, int $attempt, mixed $error, array $context = [] ): bool {
		$retry = $this->retry_for( $hook );

		if ( self::available() && $retry->again( $error, $attempt ) ) {
			$this->defer( $hook, $id, $attempt + 1, $retry->delay( $attempt ) );

			return true;
		}

		$gave_up = isset( $this->jobs[ $hook ] ) ? $this->jobs[ $hook ]['gave_up'] : $this->gave_up;

		if ( is_callable( $gave_up ) ) {
			$gave_up( $error, $id, $attempt, $context, $hook );
		}

		return false;
	}

	/**
	 * How a job retries: its own schedule, or the group's for one not
	 * declared.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The job.
	 *
	 * @return Retry
	 */
	public function retry_for( string $hook ): Retry {
		return $this->jobs[ $hook ]['retry'] ?? $this->retry;
	}

	/**
	 * Whether a job has had every attempt it gets.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook    The job.
	 * @param int    $attempt Which attempt just failed, counting from one.
	 *
	 * @return bool
	 */
	public function exhausted( string $hook, int $attempt ): bool {
		return $this->retry_for( $hook )->exhausted( $attempt );
	}

	/**
	 * Seconds to wait after a failed attempt before the next.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook    The job.
	 * @param int    $attempt Which attempt just failed, counting from one.
	 *
	 * @return int
	 */
	public function retry_delay( string $hook, int $attempt ): int {
		return $this->retry_for( $hook )->delay( $attempt );
	}

	/**
	 * Queue an action, as it is. No deduplication, no retry.
	 *
	 * For a caller that runs its own: one webhook delivery to one
	 * endpoint, one notification to one device, each of which is its own
	 * piece of work even when the arguments match another's.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $hook  The action.
	 * @param array<int, mixed> $args  What it is handed.
	 * @param int               $delay Seconds to wait first.
	 *
	 * @return bool False without a scheduler: the caller decides what
	 *              running it now would mean.
	 */
	public function later( string $hook, array $args = [], int $delay = 0 ): bool {
		return $this->schedule( $hook, $args, Clock::time() + max( 0, $delay ) );
	}
}
