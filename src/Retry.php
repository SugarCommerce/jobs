<?php
/**
 * When a failed job gets another go.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use Closure;

/**
 * Class Retry
 *
 * One answer to "try again, and when?", shared by everything in this
 * library that retries -- a deferred Action Scheduler job
 * ({@see Scheduler::failed()}) -- and by webhooks' sender.
 *
 * Two rules do most of the work.
 *
 * **Retry the other end's problems, not your own.** No answer at all --
 * offline, DNS, a timeout -- a rate limit, and a fault at the other end
 * might not happen again. A refusal will: an invalid request is refused
 * just as firmly in six hours, and retrying it four more times only delays
 * somebody being told. See {@see self::transient()}.
 *
 * **Wait as stated, give or take.** A list of waits, in order, the last
 * standing for any after it -- the schedule a plugin has usually promised
 * somebody in its documentation -- each spread by a tenth either way
 * ({@see self::JITTER}). Without the spread, every job that failed in one
 * outage comes back in the same second and knocks the other end over
 * again as it recovers. A `Retry-After` the other end sent wins, exactly,
 * because it knows more about its own recovery than any schedule does.
 *
 *     new Retry( [ 60, 300, 900, 3600, 21600 ] );   // five waits, six tries
 *     new Retry( [ 60, 300 ], attempts: 10 );        // 60, 300, 300, 300 ...
 *     new Retry( [ 60, 300 ], jitter: 0 );           // exactly 60, then 300
 *
 * Or hand it any object with `exhausted( int )` and `delay( int )` -- a
 * webhook library's backoff curve with jitter -- and it is asked instead. An
 * object that also has `should_retry( int $status, int $attempt )` decides
 * which failures are worth another go too, unless `retry_if` is given.
 *
 * @since 1.0.0
 */
final class Retry {

	/**
	 * The waits, by default: a minute, five, fifteen, an hour, six hours.
	 *
	 * @since 1.0.0
	 * @var int[]
	 */
	public const WAITS = [ 60, 300, 900, 3600, 21600 ];

	/**
	 * The longest a `Retry-After` is honoured for.
	 *
	 * A receiver asking for a week has said something closer to "no".
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const MAX_RETRY_AFTER = 86400;

	/**
	 * How far a stated wait is spread, by default: a percentage either way.
	 *
	 * A tenth, so a minute is 54 to 66 seconds and six hours is within
	 * thirty-six minutes of six hours: close enough to the schedule a
	 * plugin promised, far enough apart that a thousand jobs do not land in
	 * one second. The retired SaaS curve spread by a quarter; a stated
	 * schedule is held closer.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const JITTER = 10;

	/**
	 * Seconds to wait after each failure, in order.
	 *
	 * @since 1.0.0
	 * @var int[]
	 */
	private array $waits;

	/**
	 * A policy object asked in place of the waits, or null.
	 *
	 * @since 1.0.0
	 * @var object|null
	 */
	private ?object $policy;

	/**
	 * Total attempts including the first, or null for one more than there
	 * are waits.
	 *
	 * @since 1.0.0
	 * @var int|null
	 */
	private ?int $attempts;

	/**
	 * Whether a failure is worth another go, or null for the default.
	 *
	 * @since 1.0.0
	 * @var Closure|null
	 */
	private ?Closure $retry_if;

	/**
	 * How far each stated wait is spread, as a percentage either way.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private int $jitter;

	/**
	 * A retry schedule.
	 *
	 * @since 1.0.0
	 *
	 * @param int[]|object  $waits    Seconds to wait after each failed
	 *                                attempt, the last standing for any
	 *                                after it. Or a policy object with
	 *                                exhausted( int ) and delay( int ).
	 * @param int|null      $attempts Total attempts including the first.
	 *                                Null for one more than there are
	 *                                waits. Ignored with a policy object.
	 * @param callable|null $retry_if Whether a failure is worth another
	 *                                go: ( $error ): bool. Null for
	 *                                self::transient().
	 * @param int           $jitter   How far each stated wait is spread,
	 *                                as a percentage either way, 0 to
	 *                                100. 0 waits exactly. A policy object
	 *                                spreads its own.
	 */
	public function __construct( array|object $waits = self::WAITS, ?int $attempts = null, ?callable $retry_if = null, int $jitter = self::JITTER ) {
		$this->policy   = is_object( $waits ) && ! $waits instanceof self ? $waits : null;
		$this->waits    = is_array( $waits ) ? self::clean( $waits ) : [];
		$this->attempts = $attempts;
		$this->retry_if = null === $retry_if ? null : Closure::fromCallable( $retry_if );
		$this->jitter   = max( 0, min( 100, $jitter ) );
	}

	/**
	 * A schedule from configuration: `retry`, `attempts`, `retry_if`, `jitter`.
	 *
	 * What is left out comes from `$base`. A configuration that states its
	 * own waits and not how many attempts means one more than it has waits,
	 * not the base's count.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $config What was given.
	 * @param Retry|null           $base   Where the rest comes from.
	 *
	 * @return self
	 */
	public static function from( array $config, ?self $base = null ): self {
		$retry = $config['retry'] ?? null;

		if ( $retry instanceof self && ! isset( $config['attempts'] ) && ! isset( $config['retry_if'] ) && ! isset( $config['jitter'] ) ) {
			return $retry;
		}

		$base ??= new self();

		if ( $retry instanceof self ) {
			$base  = $retry;
			$retry = null;
		}

		$waits    = $retry ?? ( $base->policy ?? $base->waits );
		$attempts = array_key_exists( 'attempts', $config )
			? ( null === $config['attempts'] ? null : (int) $config['attempts'] )
			: ( null !== $retry ? null : $base->attempts );
		$retry_if = is_callable( $config['retry_if'] ?? null ) ? $config['retry_if'] : $base->retry_if;
		$jitter   = isset( $config['jitter'] ) ? (int) $config['jitter'] : $base->jitter;

		return new self( is_object( $waits ) ? $waits : (array) $waits, $attempts, $retry_if, $jitter );
	}

	/**
	 * Whether a failure gets another attempt.
	 *
	 * Worth retrying, and attempts left.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $error   Why it failed: a WP_Error with a `status` in its
	 *                       data, a status code -- whatever `retry_if` reads.
	 * @param int   $attempt Which attempt just failed, counting from one.
	 *
	 * @return bool
	 */
	public function again( mixed $error, int $attempt ): bool {
		if ( null === $this->retry_if && null !== $this->policy && method_exists( $this->policy, 'should_retry' ) ) {
			return (bool) $this->policy->should_retry( self::status( $error ), $attempt );
		}

		return $this->worth( $error ) && ! $this->exhausted( $attempt );
	}

	/**
	 * Whether a failure might not happen again.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $error Why it failed.
	 *
	 * @return bool
	 */
	public function worth( mixed $error ): bool {
		return null === $this->retry_if ? self::transient( $error ) : (bool) ( $this->retry_if )( $error );
	}

	/**
	 * Whether a job has had every attempt it gets.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attempt Which attempt just failed, counting from one.
	 *
	 * @return bool
	 */
	public function exhausted( int $attempt ): bool {
		if ( null !== $this->policy ) {
			return (bool) $this->policy->exhausted( $attempt );
		}

		$attempts = $this->attempts ?? count( $this->waits ) + 1;

		return $attempt >= max( 1, $attempts );
	}

	/**
	 * Seconds to wait after a failed attempt before the next.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $attempt     Which attempt just failed, counting from
	 *                              one.
	 * @param int|null $retry_after Seconds the other end asked for, when it
	 *                              said. Honoured up to a day, exactly.
	 *
	 * @return int A stated wait spread by the jitter, never below a second.
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public function delay( int $attempt, ?int $retry_after = null ): int {
		if ( null !== $retry_after && $retry_after > 0 ) {
			return min( $retry_after, self::MAX_RETRY_AFTER );
		}

		if ( null !== $this->policy ) {
			return max( 0, (int) $this->policy->delay( $attempt ) );
		}

		if ( [] === $this->waits ) {
			return 0;
		}

		$wait = $this->waits[ max( 1, $attempt ) - 1 ] ?? $this->waits[ count( $this->waits ) - 1 ];

		// Spread, so a recovering receiver is not met by every job that
		// failed with it in the same second.
		$spread = intdiv( $wait * $this->jitter, 100 );

		return 0 === $spread ? $wait : max( 1, $wait - $spread + random_int( 0, $spread * 2 ) );
	}

	/**
	 * How far each stated wait is spread, as a percentage either way.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function jitter(): int {
		return $this->jitter;
	}

	/**
	 * The waits, as cleaned. Empty for a policy object.
	 *
	 * @since 1.0.0
	 *
	 * @return int[]
	 */
	public function waits(): array {
		return $this->waits;
	}

	/**
	 * Whether a failure might not happen again, by default.
	 *
	 * No answer at all, a request timeout, a rate limit, and anything in
	 * the five hundreds are worth another go. Anything else the other end
	 * said -- a 400, a 404, a 410 asking to be forgotten -- is a considered
	 * refusal.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $error A WP_Error with a `status` in its data, or a
	 *                     status code.
	 *
	 * @return bool
	 */
	public static function transient( mixed $error ): bool {
		$status = self::status( $error );

		return 0 === $status || 408 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * The status code a failure carries, 0 for none.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $error A status code; a WP_Error, or anything with
	 *                     get_error_data(), carrying `status`; an array with
	 *                     `status`.
	 *
	 * @return int
	 */
	public static function status( mixed $error ): int {
		if ( is_int( $error ) ) {
			return $error;
		}

		if ( is_object( $error ) && method_exists( $error, 'get_error_data' ) ) {
			$data = $error->get_error_data();

			return (int) ( is_array( $data ) ? ( $data['status'] ?? 0 ) : 0 );
		}

		if ( is_array( $error ) ) {
			return (int) ( $error['status'] ?? 0 );
		}

		return 0;
	}

	/**
	 * A list of waits, keeping only whole positive numbers of seconds.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $waits What was given.
	 *
	 * @return int[]
	 */
	private static function clean( array $waits ): array {
		$clean = [];

		foreach ( $waits as $wait ) {
			if ( ( is_int( $wait ) || ( is_string( $wait ) && ctype_digit( $wait ) ) ) && (int) $wait > 0 ) {
				$clean[] = (int) $wait;
			}
		}

		return $clean;
	}
}
