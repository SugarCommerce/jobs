<?php
/**
 * Items: one item of a batch processed, and the run's progress.
 *
 * @package     SugarCommerce\Jobs
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Traits\Sync;

use SugarCommerce\Jobs\Progress;
use SugarCommerce\Jobs\Utils\Runtime;
use Throwable;
use WP_Error;

/**
 * Items: one item of a batch processed, and the run's progress.
 *
 * Each item's own id and name come from its callbacks, or are worked out
 * from the item.
 *
 * @since 1.0.0
 */
trait Items {

	/**
	 * Process one item, and write down what came of it when resuming.
	 *
	 * A process callback that throws is a failure with the exception's
	 * message; one that answers false is a failure too.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $item The item, as the data callback listed it.
	 * @param string $name What to call it.
	 *
	 * @return array{status: string, error?: string}
	 */
	public function process_item( mixed $item, string $name ): array {
		$callback = $this->process_callback();

		try {
			$result = null === $callback
				? new WP_Error( 'no_process_callback', __( 'No process callback defined.', 'sugarcommerce' ) )
				: $callback( $item );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'process_error', $e->getMessage() );
		}

		if ( false === $result ) {
			// The plainest way a callback has of saying no.
			$result = new WP_Error( 'process_failed', __( 'The item could not be processed.', 'sugarcommerce' ) );
		}

		$status = in_array( $result, self::RESULTS, true ) ? $result : 'created';

		// Without a way to fetch a fresh copy, a retry replays the item
		// itself, so it is kept with its failure.
		$this->progress()?->record(
			$this->id,
			$this->item_id( $item ),
			$name,
			$result instanceof WP_Error ? $result : $status,
			$result instanceof WP_Error && null === $this->retry_callback() ? [ 'item' => $item ] : []
		);

		if ( $result instanceof WP_Error ) {
			return [
				'status' => 'failed',
				'error'  => $result->get_error_message(),
			];
		}

		return [ 'status' => $status ];
	}

	/**
	 * The sync's progress, when it resumes.
	 *
	 * @since 1.0.0
	 *
	 * @return Progress|null Null for a sync registered without `resume`.
	 */
	public function progress(): ?Progress {
		if ( empty( $this->config['resume'] ) ) {
			return null;
		}

		if ( null === $this->progress ) {
			$option = (string) $this->config['progress_option'];

			$this->progress = new Progress(
				'' !== $option ? $option : Runtime::key( 'sync_progress_' . $this->id ),
				(int) $this->config['max_failures']
			);
		}

		return $this->progress;
	}

	/**
	 * Start the sync again from its first page, forgetting what it counted
	 * and what failed. Nothing for a sync that does not resume.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reset_progress(): void {
		$this->progress()?->reset();
	}

	/**
	 * An item's ID, to key its failure by.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $item The item.
	 *
	 * @return string Empty when it has none.
	 */
	public function item_id( mixed $item ): string {
		$callback = $this->config['id_callback'];

		if ( is_callable( $callback ) ) {
			try {
				return (string) $callback( $item );
			} catch ( Throwable $e ) {
				return '';
			}
		}

		$id = is_array( $item ) ? ( $item['id'] ?? '' ) : ( is_object( $item ) ? ( $item->id ?? '' ) : '' );

		return is_scalar( $id ) ? (string) $id : '';
	}

	/**
	 * What to call an item on screen.
	 *
	 * A name callback that throws is not worth abandoning a page over; the
	 * name is decoration on a progress bar.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $item The item.
	 *
	 * @return string
	 */
	public function item_name( mixed $item ): string {
		$callback = $this->name_callback();

		if ( null !== $callback ) {
			try {
				return (string) $callback( $item );
			} catch ( Throwable $e ) {
				// Guessed below instead.
				unset( $e );
			}
		}

		foreach ( [ 'name', 'title', 'label', 'product_name', 'email', 'id' ] as $field ) {
			$value = is_array( $item ) ? ( $item[ $field ] ?? null ) : ( is_object( $item ) ? ( $item->$field ?? null ) : null );

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return (string) $value;
			}
		}

		return '';
	}
}
