<?php
/**
 * A sync that tries again what other syncs failed.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use Throwable;
use WP_Error;

/**
 * Class SyncRetry
 *
 * Registers one more sync whose page is every failure the named resumable
 * syncs remember, all at once -- they are few, and capped -- and whose
 * process sends each back through the sync it came from. A failure that
 * succeeds this time is struck off that sync's list; one that fails again
 * stays on it with the new reason.
 *
 * The item tried is a fresh copy when the source sync has a
 * `retry_callback` (what was wrong may have been the other end's, and
 * anything time-limited in the old copy, a download link say, has expired
 * by now), or the item as it failed otherwise.
 *
 * Its button, progress bar and REST calls are a sync's like any other: the
 * configuration is a Sync's, without the callbacks, which are this class's.
 *
 *     SyncRetry::register( 'shop_import_retry', [ 'shop_import_orders', 'shop_import_files' ], [
 *         'hook_suffix'  => $hook,
 *         'title'        => __( 'Trying again', 'myplugin' ),
 *         'button_label' => __( 'Try Again', 'myplugin' ),
 *     ] );
 *
 * @since 1.0.0
 */
final class SyncRetry {

	/**
	 * Register a sync that retries other syncs' failures.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $id       The retry sync's ID.
	 * @param string[]             $sync_ids The resumable syncs whose
	 *                                       failures it retries.
	 * @param array<string, mixed> $config   A Sync's configuration (title,
	 *                                       button, screen, capability...).
	 *                                       Callbacks are this class's and
	 *                                       cannot be overridden.
	 *
	 * @return Sync
	 */
	public static function register( string $id, array $sync_ids, array $config = [] ): Sync {
		$sync_ids = array_values( array_map( 'sanitize_key', $sync_ids ) );

		return Sync::register(
			$id,
			array_merge(
				$config,
				[
					'resume'           => false,
					'data_callback'    => static fn(): array => self::page( $sync_ids ),
					'process_callback' => [ self::class, 'process' ],
					'name_callback'    => static fn( $failure ): string => (string) ( ( (array) $failure )['name'] ?? '' ),
				]
			)
		);
	}

	/**
	 * Every failure the syncs remember, as one page.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $sync_ids The syncs.
	 *
	 * @return array{items: array<int, array<string, mixed>>, has_more: bool, cursor: string, total: int}
	 */
	public static function page( array $sync_ids ): array {
		$items = [];

		foreach ( $sync_ids as $sync_id ) {
			$progress = Sync::get( (string) $sync_id )?->progress();

			if ( null !== $progress ) {
				array_push( $items, ...$progress->failures() );
			}
		}

		return [
			'items'    => $items,
			'has_more' => false,
			'cursor'   => '',
			'total'    => count( $items ),
		];
	}

	/**
	 * Try one failure again, through the sync it came from.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $failure A failure, as Progress recorded it.
	 *
	 * @return string|WP_Error
	 */
	public static function process( $failure ): string|WP_Error {
		$failure  = (array) $failure;
		$source   = Sync::get( (string) ( $failure['sync'] ?? '' ) );
		$progress = $source?->progress();

		// The sync it came from is no longer registered, or no longer
		// resumes: nothing to send it through, and nothing that could
		// succeed.
		if ( null === $source || null === $progress ) {
			return 'skipped';
		}

		$name     = (string) ( $failure['name'] ?? '' );
		$callback = $source->retry_callback();

		if ( null === $callback ) {
			$item = $failure['item'] ?? null;
		} else {
			try {
				$item = $callback( $failure );
			} catch ( Throwable $e ) {
				$item = new WP_Error( 'retry_error', $e->getMessage() );
			}
		}

		if ( null === $item ) {
			$item = new WP_Error( 'retry_no_item', __( 'There is nothing to try again with.', 'sugarcommerce' ) );
		}

		// The fresh copy could not be had: still failed, now for this reason.
		if ( $item instanceof WP_Error ) {
			$progress->record( $source->id(), (string) ( $failure['id'] ?? '' ), $name, $item, array_intersect_key( $failure, [ 'item' => true ] ) );

			return $item;
		}

		$outcome = $source->process_item( $item, $name );

		return 'failed' === $outcome['status']
			? new WP_Error( 'retry_failed', (string) ( $outcome['error'] ?? '' ) )
			: $outcome['status'];
	}
}
