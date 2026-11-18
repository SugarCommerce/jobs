<?php
/**
 * Steps of a run that cannot stop each other.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use Closure;
use Throwable;

/**
 * Class Steps
 *
 * A nightly job that drains the mail, then the webhooks, then sweeps a few
 * tables is one uncaught exception away from doing none of the later ones:
 * a deadlock in the first step, or a client surprised by an answer, and
 * every night after that the work behind it waits too. Each step here runs
 * on its own; one that throws is reported, counts as its fallback, and the
 * next step runs.
 *
 *     $steps = Steps::logging( 'myplugin nightly' );
 *
 *     $sent  = $steps->run( 'mail', fn() => $mail->drain( $send ), [] );
 *     $swept = $steps->run( 'links', fn() => Links::sweep( new Pass( limit: 500 ) ), 0 );
 *
 * @since 1.0.0
 */
final readonly class Steps {

	/**
	 * Told about a step that threw.
	 *
	 * @since 1.0.0
	 * @var Closure
	 */
	private Closure $failed;

	/**
	 * Report failures to a function of your own.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $failed Told about a step that threw:
	 *                         ( string $name, Throwable $error ).
	 */
	public function __construct( callable $failed ) {
		$this->failed = Closure::fromCallable( $failed );
	}

	/**
	 * Steps whose failures go to the PHP error log, named for the run.
	 *
	 * @since 1.0.0
	 *
	 * @param string $run What to call the run in the log.
	 *
	 * @return self
	 */
	public static function logging( string $run ): self {
		return new self(
			static function ( string $name, Throwable $e ) use ( $run ): void {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the point of this one.
				error_log( sprintf( '%s: %s failed: %s: %s', $run, $name, $e::class, $e->getMessage() ) );
			}
		);
	}

	/**
	 * Run one step, and keep going whatever it does.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $name     What to call it when it fails.
	 * @param callable $work     The step.
	 * @param mixed    $fallback What it counts as when it throws.
	 *
	 * @return mixed What the step returned, or the fallback.
	 */
	public function run( string $name, callable $work, mixed $fallback = null ): mixed {
		try {
			return $work();
		} catch ( Throwable $e ) {
			( $this->failed )( $name, $e );

			return $fallback;
		}
	}
}
