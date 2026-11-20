<?php
/**
 * The REST endpoints a sync's button talks to.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use SugarCommerce\Jobs\Utils\Runtime;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class SyncRoutes
 *
 * Two phases, two endpoints, for every sync in this build:
 *
 * - **fetch** calls the sync's data callback for a page, keeps the page in a
 *   transient (one per sync and user, so two people syncing at once do not
 *   share one), and answers how many items it holds, so the bar has a real
 *   size before the first item is processed;
 * - **process** takes the next few items off the kept page, processes each
 *   and answers what each came to. The script calls it until the page is
 *   done, then fetches the next page if there is one.
 *
 * Separate, so the bar moves visibly even when every item fits in one page
 * of the other end's API -- twenty-seven prices in one call is otherwise
 * one request, and a bar that goes from nothing to done.
 *
 * @since 1.0.0
 */
final class SyncRoutes {

	/**
	 * Seconds a fetched page is kept for processing.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const int TRANSIENT_TTL = 600;

	/**
	 * Whether the routes are hooked.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * The REST namespace, derived per build.
	 *
	 * Not a constant: a constant is the same in every prefixed copy, which
	 * is the collision this avoids.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		return Runtime::rest_namespace();
	}

	/**
	 * Hook the routes. Once, however often it is asked.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Register the routes. The `rest_api_init` action.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$sync_id = [
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
		];

		register_rest_route(
			self::rest_namespace(),
			'/fetch',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle_fetch' ],
				'permission_callback' => [ self::class, 'check_permission' ],
				'args'                => [
					'sync_id' => $sync_id,
					'cursor'  => [
						'default' => '',
						'type'    => 'string',
					],
				],
			]
		);

		register_rest_route(
			self::rest_namespace(),
			'/process',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle_process' ],
				'permission_callback' => [ self::class, 'check_permission' ],
				'args'                => [
					'sync_id' => $sync_id,
				],
			]
		);
	}

	/**
	 * Whether the user may run the sync asked for.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ): bool|WP_Error {
		$sync       = Sync::get( (string) $request->get_param( 'sync_id' ) );
		$capability = null !== $sync ? (string) $sync->config( 'capability', 'manage_options' ) : 'manage_options';

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'sugarcommerce' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Fetch a page and keep it for processing.
	 *
	 * A sync that resumes starts from wherever it had got to when asked for
	 * the start, and one that has finished has nothing to do until its
	 * progress is reset.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_fetch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sync = self::sync( $request );

		if ( $sync instanceof WP_Error ) {
			return $sync;
		}

		$data_callback = $sync->data_callback();

		if ( null === $data_callback ) {
			return new WP_Error( 'no_data_callback', __( 'No data callback defined.', 'sugarcommerce' ), [ 'status' => 500 ] );
		}

		$cursor   = (string) $request->get_param( 'cursor' );
		$progress = $sync->progress();

		if ( null !== $progress && '' === $cursor ) {
			if ( $progress->is_done() ) {
				return new WP_REST_Response(
					[
						'success'  => true,
						'fetched'  => 0,
						'has_more' => false,
						'cursor'   => '',
						'total'    => 0,
					],
					200
				);
			}

			$cursor = $progress->cursor();
		}

		// Written before it is read: a page that never finishes is the one
		// to start again from.
		$progress?->begin_page( $cursor );

		// Throwable, not Exception: a TypeError in the callback is just as
		// much a failed fetch, and uncaught it is a blank 500 the script can
		// only report as "Sync failed".
		try {
			$page = $data_callback( $cursor );
		} catch ( Throwable $e ) {
			return new WP_Error( 'fetch_error', $e->getMessage(), [ 'status' => 500 ] );
		}

		if ( ! is_array( $page ) || ! is_array( $page['items'] ?? null ) ) {
			return new WP_Error( 'invalid_fetch_result', __( 'Data callback returned an invalid result.', 'sugarcommerce' ), [ 'status' => 500 ] );
		}

		$items    = array_values( $page['items'] );
		$has_more = ! empty( $page['has_more'] );
		$next     = (string) ( $page['cursor'] ?? '' );
		$named    = [];

		foreach ( $items as $item ) {
			$named[] = [
				'data' => $item,
				'name' => $sync->item_name( $item ),
			];
		}

		set_transient(
			self::transient_key( $sync->id() ),
			[
				'items'  => $named,
				'offset' => 0,
				'next'   => $next,
				'last'   => ! $has_more,
			],
			self::TRANSIENT_TTL
		);

		// An empty page is never processed, so it is finished here.
		if ( [] === $items ) {
			$progress?->end_page( $next, ! $has_more );
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'fetched'  => count( $items ),
				'has_more' => $has_more,
				'cursor'   => $next,
				'total'    => $page['total'] ?? null,
			],
			200
		);
	}

	/**
	 * Process the next few items of the kept page.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_process( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sync = self::sync( $request );

		if ( $sync instanceof WP_Error ) {
			return $sync;
		}

		if ( null === $sync->process_callback() ) {
			return new WP_Error( 'no_process_callback', __( 'No process callback defined.', 'sugarcommerce' ), [ 'status' => 500 ] );
		}

		$key   = self::transient_key( $sync->id() );
		$cache = get_transient( $key );

		if ( ! is_array( $cache ) || empty( $cache['items'] ) ) {
			return new WP_Error( 'no_cached_items', __( 'No items to process. Fetch first.', 'sugarcommerce' ), [ 'status' => 400 ] );
		}

		$offset  = (int) ( $cache['offset'] ?? 0 );
		$size    = $sync->chunk_size();
		$chunk   = array_slice( $cache['items'], $offset, $size );
		$results = [
			'success'   => true,
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'items'     => [],
		];

		foreach ( $chunk as $entry ) {
			$outcome = $sync->process_item( $entry['data'], (string) $entry['name'] );

			++$results['processed'];
			++$results[ $outcome['status'] ];

			$results['items'][] = [ 'name' => $entry['name'] ] + $outcome;
		}

		$remaining = max( 0, count( $cache['items'] ) - ( $offset + $size ) );

		if ( $remaining > 0 ) {
			// More of this page: keep where the sync goes after it.
			set_transient( $key, array_merge( $cache, [ 'offset' => $offset + $size ] ), self::TRANSIENT_TTL );
		} else {
			delete_transient( $key );

			$sync->progress()?->end_page( (string) ( $cache['next'] ?? '' ), ! empty( $cache['last'] ) );
		}

		return new WP_REST_Response(
			$results + [
				'page_done' => 0 === $remaining,
				'remaining' => $remaining,
			],
			200
		);
	}

	/**
	 * The sync a request names, or why not.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return Sync|WP_Error
	 */
	private static function sync( WP_REST_Request $request ): Sync|WP_Error {
		return Sync::get( (string) $request->get_param( 'sync_id' ) )
			?? new WP_Error( 'invalid_sync', __( 'Sync registration not found.', 'sugarcommerce' ), [ 'status' => 400 ] );
	}

	/**
	 * Where a fetched page is kept: one per sync and user, so two people
	 * syncing at once do not share one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sync_id The sync.
	 *
	 * @return string
	 */
	private static function transient_key( string $sync_id ): string {
		return Runtime::key( 'sync_' . $sync_id . '_' . get_current_user_id() );
	}
}
