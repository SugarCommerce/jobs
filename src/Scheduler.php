<?php
/**
 * Jobs on Action Scheduler.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Traits\Scheduler\Failures;
use SugarCommerce\Jobs\Traits\Scheduler\Scheduling;

/**
 * Class Scheduler
 *
 * Jobs on Action Scheduler, in one group per plugin.
 *
 * WP-Cron is fine for "once a day, roughly". It is the wrong tool for work
 * that must not happen during a save -- telling a payment provider that a
 * product changed, posting a webhook, sending a receipt again -- because it
 * keeps no record of what ran, retries nothing, and loses a job the moment
 * two requests race to schedule it. Action Scheduler keeps a table of every
 * action, runs them in the background and has a screen under Tools for
 * seeing what ran and what failed. What it does not have is the half every
 * plugin writes for itself, seven times over in one plugin alone:
 *
 * - **Deferred jobs**, deduplicated: saving a product three times in a
 *   minute queues one job, not three, and the one that runs reads whatever
 *   the database says by then. See {@see self::defer()}.
 * - **Retries** on a stated schedule, for the failures worth retrying, and a
 *   callback when it is given up on. See {@see self::failed()}.
 * - **Recurring jobs** put in the calendar once. See {@see self::recurring()}.
 * - **Wall-clock jobs**: "at eight in the morning where the site is". See
 *   {@see self::at()}.
 * - **Everything gone at uninstall**, by group. See
 *   {@see self::unschedule_all()}.
 *
 * And a fallback for every one of them when Action Scheduler is not there:
 * running a deferred job where it stands is worse than deferring it, and far
 * better than dropping it.
 *
 * One instance per group, named for the plugin. The group is what keeps one
 * plugin's actions apart from another's on the same site, and what uninstall
 * clears, so it must never change once a plugin has shipped: actions queued
 * under the old name are orphaned.
 *
 *     $queue = Scheduler::group( 'myplugin' );
 *
 *     $queue->job( 'myplugin_sync_product', [ Sync::class, 'product' ] );
 *     $queue->defer( 'myplugin_sync_product', $product_id );
 *
 * @since 1.0.0
 */
final class Scheduler {

	use Failures;
	use Scheduling;

	/**
	 * One instance per group.
	 *
	 * @since 1.0.0
	 * @var array<string, self>
	 */
	private static array $groups = [];

	/**
	 * Whether a copy of Action Scheduler has been required by load().
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * The group's name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $group;

	/**
	 * How every job in the group retries unless it says otherwise.
	 *
	 * @since 1.0.0
	 * @var Retry
	 */
	private Retry $retry;

	/**
	 * Told when a job in the group is given up on, unless it says otherwise.
	 *
	 * @since 1.0.0
	 * @var callable|null
	 */
	private $gave_up = null;

	/**
	 * Every deferred job: what runs it, how it retries, who is told.
	 *
	 * @since 1.0.0
	 * @var array<string, array{callback: callable, retry: Retry, gave_up: callable|null}>
	 */
	private array $jobs = [];

	/**
	 * Every recurring job, and its schedule.
	 *
	 * @since 1.0.0
	 * @var array<string, array{interval: int, first: int, args: array<int, mixed>}>
	 */
	private array $recurring = [];

	/**
	 * Every wall-clock job.
	 *
	 * @since 1.0.0
	 * @var array<string, LocalJob>
	 */
	private array $local = [];

	/**
	 * A group of jobs.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $group    The Action Scheduler group.
	 * @param array<string, mixed> $defaults What every job gets: retry,
	 *                                       attempts, retry_if, jitter,
	 *                                       gave_up.
	 *                                       See self::job().
	 */
	public function __construct( string $group, array $defaults = [] ) {
		$this->group = $group;

		$this->defaults( $defaults );
	}

	/**
	 * The instance for a group, made on first use.
	 *
	 * Defaults given here replace any given before, so the plugin that owns
	 * the group states them once, where it boots.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $group    The Action Scheduler group.
	 * @param array<string, mixed>|null $defaults What every job gets.
	 *
	 * @return self
	 */
	public static function group( string $group, ?array $defaults = null ): self {
		if ( ! isset( self::$groups[ $group ] ) ) {
			self::$groups[ $group ] = new self( $group, $defaults ?? [] );
		} elseif ( null !== $defaults ) {
			self::$groups[ $group ]->defaults( $defaults );
		}

		return self::$groups[ $group ];
	}

	/**
	 * The group's name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->group;
	}

	/**
	 * Require a bundled copy of Action Scheduler.
	 *
	 * Call it from the plugin's main file, as that file is read -- not from
	 * a hook. Action Scheduler registers itself on `plugins_loaded` at
	 * priority 0 and starts at priority 1, and a plugin usually boots on
	 * that same action at priority 10: required from there, both moments
	 * have already gone by and it never starts. There is no error. Its
	 * functions simply do not exist, and every queued job quietly is not
	 * queued.
	 *
	 * Required by hand rather than autoloaded, and never prefixed by
	 * Strauss: its entry file negotiates which of several bundled copies
	 * wins, and a renamed copy could not see the others, leaving every
	 * plugin on the site running a scheduler of its own.
	 *
	 *     Scheduler::load(
	 *         __DIR__ . '/libraries/woocommerce/action-scheduler/action-scheduler.php',
	 *         __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php'
	 *     );
	 *
	 * @since 1.0.0
	 *
	 * @param string ...$files Where a copy may be, first found wins.
	 *
	 * @return bool Whether Action Scheduler is, or will be, available.
	 */
	public static function load( string ...$files ): bool {
		// Already running: its functions only exist once a copy has
		// started, which this early means something stood in for it -- a
		// test suite does, with functions that record what was queued.
		if ( self::$loaded || self::available() ) {
			return true;
		}

		foreach ( $files as $file ) {
			if ( is_readable( $file ) ) {
				self::$loaded = true;

				require_once $file;

				return true;
			}
		}

		return false;
	}

	/**
	 * Whether Action Scheduler is here to schedule with.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Declare a deferred job and attach what runs it.
	 *
	 * Call it on every request, where the plugin boots: Action Scheduler
	 * fires the hook on a later request than the one that queued it, and a
	 * callback attached only sometimes is an action that runs and does
	 * nothing. Declared, too, so that self::defer() can run the job where
	 * it stands when there is no scheduler.
	 *
	 * The callback is handed what the job concerns and which attempt this
	 * is. It reports a failure with self::failed(), which decides whether
	 * there is another.
	 *
	 *     $queue->job( 'myplugin_sync_product', [ Sync::class, 'product' ], [
	 *         'retry'    => [ 60, 300, 900, 3600, 21600 ],
	 *         'attempts' => 5,
	 *         'retry_if' => fn( WP_Error $error ): bool => $error->get_error_code() !== 'invalid_request',
	 *         'gave_up'  => fn( $error, $id, $attempt, array $context ) => log( ... ),
	 *     ] );
	 *
	 * @since 1.0.0
	 *
	 * @param string               $hook     The action.
	 * @param callable             $callback Runs it: ( $id, int $attempt ).
	 * @param array<string, mixed> $config   {
	 *     Optional. Anything left out is the group's default.
	 *
	 *     @type int[]|object $retry    Seconds to wait after each failed
	 *                                  attempt, in order, the last standing
	 *                                  for any after it. Or a Retry, or any
	 *                                  object with exhausted( int ) and
	 *                                  delay( int ).
	 *     @type int|null     $attempts Total attempts including the first.
	 *                                  Null for one more than there are
	 *                                  waits. Ignored with an object.
	 *     @type callable     $retry_if Whether a failure is worth another
	 *                                  go: ( $error ): bool. By default no
	 *                                  answer at all, a timeout, a 429 and
	 *                                  anything in the five hundreds are.
	 *     @type int          $jitter   How far each wait is spread, as a
	 *                                  percentage either way. A tenth by
	 *                                  default ({@see Retry::JITTER}); 0
	 *                                  waits exactly.
	 *     @type callable     $gave_up  Told when a job is given up on:
	 *                                  ( $error, $id, int $attempt, array
	 *                                  $context, string $hook ).
	 * }
	 *
	 * @return self
	 */
	public function job( string $hook, callable $callback, array $config = [] ): self {
		$this->jobs[ $hook ] = [
			'callback' => $callback,
			'retry'    => Retry::from( $config, $this->retry ),
			'gave_up'  => array_key_exists( 'gave_up', $config ) ? self::callable_or_null( $config['gave_up'] ) : $this->gave_up,
		];

		add_action( $hook, $callback, 10, 2 );

		return $this;
	}

	/**
	 * Run a job after this request has finished.
	 *
	 * Deduplicated on the first attempt: saving a product three times in a
	 * minute queues one job, not three, and the one that runs reads
	 * whatever the database says by then. The other side is being told the
	 * current state, not a history of edits. A retry is never deduplicated,
	 * because it is the one job and already counted.
	 *
	 * The action's arguments are `[ $id, $attempt ]`, and that shape is
	 * what deduplication matches on, so it is part of the contract with
	 * actions already in somebody's queue.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $hook    The job, declared with self::job().
	 * @param int|string $id      What it concerns: a local id, or a remote
	 *                            reference when the local row is about to
	 *                            go -- archiving a deleted product happens
	 *                            after the product is gone, so the job has
	 *                            to carry what it needs.
	 * @param int        $attempt Which attempt this will be.
	 * @param int        $delay   Seconds to wait first.
	 *
	 * @return void
	 */
	public function defer( string $hook, int|string $id, int $attempt = 1, int $delay = 0 ): void {
		if ( is_string( $id ) ? '' === $id : $id < 1 ) {
			return;
		}

		// No scheduler, no queue. Running it here is worse than deferring
		// but far better than dropping it, and it keeps a plugin working on
		// an install where Action Scheduler could not load.
		if ( ! self::available() ) {
			$callback = $this->jobs[ $hook ]['callback'] ?? null;

			if ( is_callable( $callback ) ) {
				$callback( $id, $attempt );
			}

			return;
		}

		if ( 1 === $attempt && as_has_scheduled_action( $hook, [ $id, 1 ], $this->group ) ) {
			return;
		}

		as_schedule_single_action( Clock::time() + max( 0, $delay ), $hook, [ $id, $attempt ], $this->group );
	}

	/**
	 * Every hook declared in this group, by kind.
	 *
	 * @since 1.0.0
	 *
	 * @return array{jobs: string[], recurring: string[], local: string[]}
	 */
	public function hooks(): array {
		return [
			'jobs'      => array_keys( $this->jobs ),
			'recurring' => array_keys( $this->recurring ),
			'local'     => array_keys( $this->local ),
		];
	}

	/**
	 * Run something once Action Scheduler can take it.
	 *
	 * On `init`, at a priority after Action Scheduler's own start at 1;
	 * at once, when `init` has already finished.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $callback What to run.
	 * @param int      $priority When on `init`.
	 *
	 * @return void
	 */
	private static function when_ready( callable $callback, int $priority ): void {
		if ( did_action( 'init' ) && ! doing_action( 'init' ) ) {
			$callback();

			return;
		}

		add_action( 'init', static fn() => $callback(), $priority, 0 );
	}

	/**
	 * Take the group's defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $defaults retry, attempts, retry_if, jitter,
	 *                                       gave_up.
	 *
	 * @return void
	 */
	private function defaults( array $defaults ): void {
		$this->retry   = Retry::from( $defaults );
		$this->gave_up = self::callable_or_null( $defaults['gave_up'] ?? null );
	}

	/**
	 * A callable, or null for anything else.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value What was given.
	 *
	 * @return callable|null
	 */
	private static function callable_or_null( mixed $value ): ?callable {
		return is_callable( $value ) ? $value : null;
	}
}
