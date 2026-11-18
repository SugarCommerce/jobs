<?php
/**
 * Where a resumable sync has got to, and what failed on the way.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use SugarCommerce\Dates\Clock;
use WP_Error;

/**
 * Class Progress
 *
 * A sync's memory between requests, for a sync registered with `resume`.
 *
 * A long sync is hundreds of requests from a browser that can be closed at
 * any of them, so everything it needs to carry on is written down as it
 * goes, in one option per sync (not autoloaded: it is read during the sync
 * and on its own screen, and every other request on the site would carry it
 * otherwise):
 *
 *   - **the cursor of the page being read**, written *before* it is read: a
 *     page that never finishes is the one to start again from. When a page
 *     has been processed the cursor moves on to the next one, so a browser
 *     closed between pages does not read the last page twice. A browser
 *     closed half-way through a page reads that page again, so a process
 *     callback must find what it made last time -- reading a page twice
 *     should cost time and nothing else;
 *   - **whether the sync has finished**, set once the last page has been
 *     processed. A finished sync started again does nothing until it is
 *     {@see self::reset()};
 *   - **what each item came to**, counted;
 *   - **what failed and why**, one entry per item (keyed by its id, so an
 *     item that fails twice is one entry, and one that succeeds later is
 *     struck off), capped so a run where everything failed does not write an
 *     option the size of a book.
 *
 * @since 1.0.0
 */
final class Progress {

	/**
	 * The most failures remembered, by default.
	 *
	 * Enough to retry every one of a bad run.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const MAX_FAILURES = 500;

	/**
	 * The option the progress is kept in.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $option;

	/**
	 * The most failures remembered.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private int $max_failures;

	/**
	 * Constructor.
	 *
	 * @param string $option       The option to keep it in.
	 * @param int    $max_failures The most failures remembered.
	 *
	 * @since 1.0.0
	 */
	public function __construct( string $option, int $max_failures = self::MAX_FAILURES ) {
		$this->option       = $option;
		$this->max_failures = max( 1, $max_failures );
	}

	/**
	 * Everything known about the sync.
	 *
	 * @return array{cursor: string, done: bool, created: int, updated: int, skipped: int, failed: int, failures: array<string, array<string, mixed>>, date_started: string, last_run: string}
	 * @since 1.0.0
	 */
	public function get(): array {
		$stored = get_option( $this->option, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'cursor'       => (string) ( $stored['cursor'] ?? '' ),
			'done'         => ! empty( $stored['done'] ),
			'created'      => (int) ( $stored['created'] ?? 0 ),
			'updated'      => (int) ( $stored['updated'] ?? 0 ),
			'skipped'      => (int) ( $stored['skipped'] ?? 0 ),
			'failed'       => (int) ( $stored['failed'] ?? 0 ),
			'failures'     => is_array( $stored['failures'] ?? null ) ? $stored['failures'] : [],
			'date_started' => (string) ( $stored['date_started'] ?? '' ),
			'last_run'     => (string) ( $stored['last_run'] ?? '' ),
		];
	}

	/**
	 * The cursor to carry on from. Empty for the start.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function cursor(): string {
		return $this->get()['cursor'];
	}

	/**
	 * Whether every page has been processed.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_done(): bool {
		return $this->get()['done'];
	}

	/**
	 * Every failure remembered, oldest first.
	 *
	 * @return array<int, array{sync: string, id: string, name: string, message: string, date: string}>
	 * @since 1.0.0
	 */
	public function failures(): array {
		return array_values( $this->get()['failures'] );
	}

	/**
	 * A page is about to be read: write its cursor down first.
	 *
	 * @param string $cursor The page's cursor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function begin_page( string $cursor ): void {
		$state           = $this->get();
		$state['cursor'] = $cursor;

		if ( '' === $state['date_started'] ) {
			$state['date_started'] = gmdate( 'Y-m-d H:i:s', Clock::time() );
		}

		$this->save( $state );
	}

	/**
	 * A page has been processed: move on, or finish.
	 *
	 * @param string $next The next page's cursor.
	 * @param bool   $last Whether it was the last page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function end_page( string $next, bool $last ): void {
		$state = $this->get();

		if ( $last ) {
			$state['done']   = true;
			$state['cursor'] = '';
		} else {
			$state['cursor'] = $next;
		}

		$this->save( $state );
	}

	/**
	 * Count what one item came to, and remember or clear its failure.
	 *
	 * @param string          $sync   The sync the item belongs to.
	 * @param string          $id     The item's id.
	 * @param string          $name   What to call it on screen.
	 * @param string|WP_Error $result `created`, `updated` or `skipped`, or a
	 *                                failure. Any other string counts as
	 *                                updated.
	 * @param array           $extra  More to keep with a failure (the item
	 *                                itself, for a retry that replays it).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function record( string $sync, string $id, string $name, $result, array $extra = [] ): void {
		$state = $this->get();
		$key   = '' !== $id ? $id : $name;

		if ( $result instanceof WP_Error ) {
			++$state['failed'];

			// Unset first, so an item that fails again moves to the end and
			// is the last to be dropped by the cap.
			unset( $state['failures'][ $key ] );

			$state['failures'][ $key ] = array_merge(
				$extra,
				[
					'sync'    => $sync,
					'id'      => $id,
					'name'    => $name,
					'message' => $result->get_error_message(),
					'date'    => gmdate( 'Y-m-d H:i:s', Clock::time() ),
				]
			);

			$state['failures'] = array_slice( $state['failures'], -$this->max_failures, null, true );
		} else {
			$counted = in_array( $result, [ 'created', 'updated', 'skipped' ], true ) ? $result : 'updated';

			++$state[ $counted ];

			// Done this time, so whatever stopped it before is over.
			unset( $state['failures'][ $key ] );
		}

		$this->save( $state );
	}

	/**
	 * Start again: back to the first page, nothing counted, nothing failed.
	 *
	 * What was synced before stays synced; only the record of the run is
	 * forgotten.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function reset(): void {
		delete_option( $this->option );
	}

	/**
	 * Write it down.
	 *
	 * @param array $state The progress.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function save( array $state ): void {
		$state['last_run'] = gmdate( 'Y-m-d H:i:s', Clock::time() );

		update_option( $this->option, $state, false );
	}
}
