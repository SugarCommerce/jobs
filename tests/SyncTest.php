<?php
/**
 * Sync engine tests.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use SugarCommerce\Jobs\SyncRoutes;
use SugarCommerce\Jobs\Sync;
use SugarCommerce\Jobs\Utils\Runtime;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * A sync fetches a page from somewhere else, processes it, and reports what
 * happened -- repeatedly, until the source says there is no more.
 *
 * The failures worth guarding are the quiet ones. A permission check that
 * reads the wrong capability lets any subscriber re-import a price list. A
 * callback that throws takes the whole admin screen with it. And a
 * registration for a sync that does not exist has to be a refusal rather than
 * a fetch against nothing.
 */
final class SyncTest extends TestCase {

	/**
	 * Items processed a request, by default.
	 */
	private const CHUNK = 5;

	/**
	 * Empty the registry and the stubbed WordPress.
	 */
	protected function setUp(): void {
		jobs_reset();

		foreach ( array_keys( Sync::all() ) as $id ) {
			Sync::unregister( (string) $id );
		}
	}

	/**
	 * And again.
	 */
	protected function tearDown(): void {
		$this->setUp();
	}

	/**
	 * Register a sync that returns one page.
	 *
	 * @param array $config Overrides.
	 *
	 * @return Sync
	 */
	private function sync( array $config = array() ): Sync {
		$sync = Sync::register(
			'stripe_prices',
			array_merge(
				array(
					'hook_suffix'      => 'toplevel_page_shop',
					'title'            => 'Sync Prices',
					'data_callback'    => static fn( $cursor ) => array(
						'items'    => array( (object) array( 'id' => 'price_1', 'name' => 'Small' ) ),
						'has_more' => false,
						'cursor'   => '',
						'total'    => 1,
					),
					'process_callback' => static fn( $item ) => true,
				),
				$config
			)
		);

		return $sync;
	}

	/**
	 * A registered sync can be found again.
	 */
	public function test_a_sync_registers_and_is_found(): void {
		$this->sync();

		$this->assertTrue( Sync::has( 'stripe_prices' ) );
		$this->assertInstanceOf( Sync::class, Sync::get( 'stripe_prices' ) );
		$this->assertNull( Sync::get( 'nothing' ) );
	}

	/**
	 * Syncs are found by the screen they belong to.
	 *
	 * A products table asks for its own syncs, not every sync on the site.
	 */
	public function test_syncs_are_found_by_screen(): void {
		$this->sync();

		$this->assertCount( 1, Sync::on_screen( 'toplevel_page_shop' ) );
		$this->assertCount( 0, Sync::on_screen( 'edit.php' ) );
	}

	/**
	 * The capability is the sync's own, not a fixed one.
	 *
	 * A price import and a customer export are not the same permission, and a
	 * library that hardcodes manage_options makes them so.
	 */
	public function test_the_capability_comes_from_the_sync(): void {
		$this->sync( array( 'capability' => 'manage_shop' ) );

		$request = new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) );

		$GLOBALS['jobs_caps'] = array( 'manage_options' => true );
		$this->assertInstanceOf( WP_Error::class, SyncRoutes::check_permission( $request ) );

		$GLOBALS['jobs_caps'] = array( 'manage_shop' => true );
		$this->assertTrue( SyncRoutes::check_permission( $request ) );
	}

	/**
	 * An unregistered sync is refused rather than defaulted.
	 *
	 * The dangerous alternative is falling back to a permissive capability for
	 * a sync id nobody registered.
	 */
	public function test_an_unknown_sync_is_refused(): void {
		$request = new WP_REST_Request( array( 'sync_id' => 'nothing' ) );

		$GLOBALS['jobs_caps'] = array();

		$this->assertInstanceOf( WP_Error::class, SyncRoutes::check_permission( $request ) );
	}

	/**
	 * Fetching a page returns its items.
	 */
	public function test_a_page_is_fetched(): void {
		$this->sync();

		$response = SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $response );

		$data = $response->get_data();

		// The fetch stages a page and reports how much it staged; the items
		// themselves go into a transient for the process pass to pick up.
		$this->assertSame( 1, $data['fetched'] );
		$this->assertFalse( $data['has_more'] );
		$this->assertSame( 1, $data['total'] );
	}

	/**
	 * A fetch for a sync nobody registered is an error, not a crash.
	 */
	public function test_fetching_an_unknown_sync_errors(): void {
		$response = SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'nothing' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_sync', $response->get_error_code() );
	}

	/**
	 * A callback that throws becomes an error response.
	 *
	 * The source is somebody else's API. It will time out, rate-limit and
	 * return nonsense, and none of those should be an uncaught exception on an
	 * admin screen.
	 */
	public function test_a_throwing_callback_becomes_an_error(): void {
		$this->sync(
			array(
				'data_callback' => static function ( $cursor ) {
					throw new \RuntimeException( 'Stripe is down' );
				},
			)
		);

		$response = SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'fetch_error', $response->get_error_code() );
		$this->assertSame( 'Stripe is down', $response->get_error_message() );
	}

	/**
	 * A callback returning the wrong shape is an error, not a silent success.
	 *
	 * Returning a bare array of items rather than the documented envelope is
	 * the obvious mistake, and without this it looks like a sync of nothing.
	 */
	public function test_a_malformed_callback_result_is_refused(): void {
		$this->sync( array( 'data_callback' => static fn( $cursor ) => array( 'nope' ) ) );

		$response = SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_fetch_result', $response->get_error_code() );
	}

	/**
	 * The button carries the sync it belongs to.
	 */
	public function test_the_button_names_its_sync(): void {
		$html = $this->sync()->button();

		$this->assertStringContainsString( 'data-sync-id="stripe_prices"', $html );
		$this->assertStringContainsString( 'data-sync-build="sugarcommerce-jobs-sync"', $html );
		$this->assertStringContainsString( 'sugar-sync-trigger', $html );
	}

	/**
	 * Assets load on the sync's own screen and nowhere else.
	 */
	public function test_assets_load_on_the_right_screen_only(): void {
		$sync = $this->sync();

		$sync->maybe_enqueue_assets( 'edit.php' );
		$this->assertSame( array(), $GLOBALS['jobs_enqueued'] );

		$sync->maybe_enqueue_assets( 'toplevel_page_shop' );
		$this->assertArrayHasKey( 'sugarcommerce-jobs-sync', $GLOBALS['jobs_enqueued'] );
	}

	/**
	 * The configuration is this build's entry in a registry keyed by its
	 * script handle, merged into rather than assigned over.
	 *
	 * Two prefixed copies both enqueue and both add their syncs. A bare
	 * global would hand every plugin's syncs to whichever copy of the script
	 * ran first; assigning the entry would leave the build's earlier syncs
	 * out of it.
	 */
	public function test_the_javascript_config_is_this_builds_own_entry(): void {
		$sync = $this->sync();
		$sync->maybe_enqueue_assets( 'toplevel_page_shop' );

		$inline = implode( "\n", $GLOBALS['jobs_inline']['sugarcommerce-jobs-sync'] ?? array() );

		$this->assertSame( 'sugarcommerce-jobs-sync', Sync::script_handle() );
		$this->assertStringContainsString( 'window.SugarCommerceJobs=window.SugarCommerceJobs||{};', $inline );
		$this->assertStringContainsString( 'window.SugarCommerceJobs["sugarcommerce-jobs-sync"]=window.SugarCommerceJobs["sugarcommerce-jobs-sync"]||{syncs:{}};', $inline );
		$this->assertStringContainsString( 'window.SugarCommerceJobs["sugarcommerce-jobs-sync"].syncs["stripe_prices"]=', $inline );
		$this->assertStringNotContainsString( 'SugarSyncConfig', $inline );
	}

	/**
	 * A build named by its plugin keys its entry, and marks its buttons,
	 * with its own handle.
	 */
	public function test_a_prefixed_build_keys_its_entry_and_buttons_by_its_handle(): void {
		Runtime::use_prefix( 'myplugin' );

		try {
			$sync = $this->sync();
			$sync->maybe_enqueue_assets( 'toplevel_page_shop' );

			$inline = implode( "\n", $GLOBALS['jobs_inline']['myplugin-sync'] ?? array() );

			$this->assertSame( 'myplugin-sync', Sync::script_handle() );
			$this->assertStringContainsString( 'window.SugarCommerceJobs["myplugin-sync"].syncs["stripe_prices"]=', $inline );
			$this->assertStringContainsString( 'data-sync-build="myplugin-sync"', $sync->button() );
		} finally {
			Runtime::use_prefix( '' );
		}
	}

	/**
	 * Each copy of the script reads only its own entry and answers only its
	 * own buttons.
	 *
	 * The script used to bind once for every copy on the page -- the first
	 * to run served every plugin's syncs, read from one shared
	 * window.SugarSyncConfig, and published itself as window.SugarSync. A
	 * copy finds its entry from its own <script> element's id instead, and
	 * passes over a button or bar that names another build.
	 */
	public function test_the_script_serves_only_its_own_build(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/sync.js' );

		foreach ( array( 'SugarSyncConfig', 'window.SugarSync ', 'window.SugarSync=', 'window.SugarSync.', '__bound' ) as $shared ) {
			$this->assertStringNotContainsString( $shared, $script, "The shared {$shared} is back." );
		}

		$this->assertStringContainsString( 'document.currentScript', $script );
		$this->assertStringContainsString( "script.id.replace( /-js$/, '' )", $script );
		$this->assertStringContainsString( '( window.SugarCommerceJobs || {} )[ handle ]', $script );
		$this->assertStringContainsString( "return element.dataset.syncBuild === handle;", $script );
		$this->assertStringContainsString( 'button.dataset.syncId === id && ours( button )', $script );
		$this->assertStringContainsString( "if ( ! button || ! ours( button ) ) {", $script );
		$this->assertStringContainsString( 'bar.dataset.syncBuild = handle;', $script );
		$this->assertStringContainsString( 'detail: { ...detail, build: handle }', $script );
		$this->assertStringContainsString( 'entry.start = ', $script );
	}

	/**
	 * Each sync carries its own endpoint.
	 *
	 * Every prefixed build has its own REST namespace, so there is no single
	 * endpoint a shared object could hold.
	 */
	public function test_each_sync_carries_its_own_endpoint(): void {
		$sync = $this->sync();
		$sync->maybe_enqueue_assets( 'toplevel_page_shop' );

		$inline = implode( "\n", $GLOBALS['jobs_inline']['sugarcommerce-jobs-sync'] ?? array() );

		$this->assertStringContainsString( 'restUrl', $inline );
		$this->assertStringContainsString( 'restNonce', $inline );

		// json_encode escapes the slashes, so the namespace is compared in
		// the form it actually reaches the page in.
		$this->assertStringContainsString(
			trim( (string) wp_json_encode( SyncRoutes::rest_namespace() ), '"' ),
			$inline
		);
	}
	/**
	 * A page of records, as the data callback would hand them over.
	 *
	 * @param int $count How many.
	 *
	 * @return array
	 */
	private static function page( int $count ): array {
		$items = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$items[] = (object) array( 'id' => 'price_' . $i, 'name' => 'Item ' . $i );
		}

		return array( 'items' => $items, 'has_more' => false, 'cursor' => '', 'total' => $count );
	}

	/**
	 * Processing walks the staged page in chunks and reports each record.
	 *
	 * The whole point of the two phases: a page of seven is processed five
	 * at a time so the progress bar moves, and the staged page is cleaned
	 * up when it is exhausted.
	 */
	public function test_a_page_is_processed_in_chunks(): void {
		$seen = array();

		$this->sync(
			array(
				'data_callback'    => static fn( $cursor ) => self::page( self::CHUNK + 2 ),
				'process_callback' => static function ( $item ) use ( &$seen ) {
					$seen[] = $item->id;

					return 'updated';
				},
			)
		);

		SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$first = SyncRoutes::handle_process( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) )->get_data();

		$this->assertSame( self::CHUNK, $first['processed'] );
		$this->assertSame( self::CHUNK, $first['updated'] );
		$this->assertFalse( $first['page_done'] );
		$this->assertSame( 2, $first['remaining'] );
		$this->assertSame( 'Item ' . self::CHUNK, end( $first['items'] )['name'] );

		$second = SyncRoutes::handle_process( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) )->get_data();

		$this->assertSame( 2, $second['processed'] );
		$this->assertTrue( $second['page_done'] );
		$this->assertSame( 0, $second['remaining'] );

		$this->assertCount( self::CHUNK + 2, $seen );
		$this->assertSame( 'price_1', $seen[0] );
		$this->assertSame( array(), $GLOBALS['jobs_transients'], 'The staged page was not cleaned up.' );
	}

	/**
	 * Processing before fetching is a refusal, not a loop over nothing.
	 */
	public function test_processing_before_fetching_is_refused(): void {
		$this->sync();

		$response = SyncRoutes::handle_process( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'no_cached_items', $response->get_error_code() );
	}

	/**
	 * One record failing does not abandon the rest of the run.
	 *
	 * Three ways a callback can fail -- a WP_Error, an exception, and plain
	 * false -- and each is one failed record with the others still processed.
	 * False used to be counted as created, which is the opposite of what it
	 * said.
	 */
	public function test_a_failing_record_is_reported_and_the_rest_continue(): void {
		$this->sync(
			array(
				'data_callback'    => static fn( $cursor ) => self::page( 4 ),
				'process_callback' => static fn( $item ) => match ( $item->id ) {
					'price_1' => new WP_Error( 'bad', 'No such price' ),
					'price_2' => throw new \RuntimeException( 'Stripe hiccup' ),
					'price_3' => false,
					default   => 'created',
				},
			)
		);

		SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$data = SyncRoutes::handle_process( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) )->get_data();

		$this->assertSame( 4, $data['processed'] );
		$this->assertSame( 3, $data['failed'] );
		$this->assertSame( 1, $data['created'] );
		$this->assertSame( 'No such price', $data['items'][0]['error'] );
		$this->assertSame( 'Stripe hiccup', $data['items'][1]['error'] );
		$this->assertSame( 'failed', $data['items'][2]['status'] );
		$this->assertSame( 'created', $data['items'][3]['status'] );
	}

	/**
	 * An Error in a callback is an error response, not a blank 500.
	 *
	 * Exception was caught; a TypeError or a DivisionByZeroError is an Error,
	 * which went straight through and left the JavaScript with nothing to
	 * say but "Sync failed".
	 */
	public function test_an_error_in_a_callback_is_caught(): void {
		$this->sync( array( 'data_callback' => static fn( $cursor ) => intdiv( 1, 0 ) ) );

		$response = SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'fetch_error', $response->get_error_code() );
		$this->assertSame( 'Division by zero', $response->get_error_message() );
	}

	/**
	 * A name callback that throws does not abandon the fetch.
	 *
	 * The name is decoration on a progress bar.
	 */
	public function test_a_throwing_name_callback_falls_back(): void {
		$this->sync( array( 'name_callback' => static fn( $item ) => throw new \RuntimeException( 'no name' ) ) );

		$response = SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $response );

		$staged = reset( $GLOBALS['jobs_transients'] );

		$this->assertSame( 'Small', $staged['items'][0]['name'], 'The guessed name was not used.' );
	}

	/**
	 * A data callback returning items that are not a list is refused.
	 */
	public function test_items_that_are_not_a_list_are_refused(): void {
		$this->sync( array( 'data_callback' => static fn( $cursor ) => array( 'items' => 'price_1' ) ) );

		$response = SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_fetch_result', $response->get_error_code() );
	}

	/**
	 * A staged page belongs to the user who fetched it.
	 *
	 * Two administrators syncing at once must not process each other's
	 * pages.
	 */
	public function test_a_staged_page_belongs_to_the_user_who_fetched_it(): void {
		$this->sync();

		SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$GLOBALS['jobs_user_id'] = 2;

		$response = SyncRoutes::handle_process( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'no_cached_items', $response->get_error_code() );
	}

	/**
	 * How many items a request processes is the sync's to say.
	 */
	public function test_the_chunk_size_is_configurable(): void {
		$this->sync(
			array(
				'chunk_size'    => 2,
				'data_callback' => static fn( $cursor ) => self::page( 3 ),
			)
		);

		SyncRoutes::handle_fetch( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) );

		$first = SyncRoutes::handle_process( new WP_REST_Request( array( 'sync_id' => 'stripe_prices' ) ) )->get_data();

		$this->assertSame( 2, $first['processed'] );
		$this->assertSame( 1, $first['remaining'] );
		$this->assertSame( 1, Sync::register( 'x', array( 'chunk_size' => 0 ) )->chunk_size(), 'Never less than one.' );
	}

	/**
	 * A button can say where its bar goes, and has a label without one set.
	 */
	public function test_a_button_carries_its_own_container(): void {
		$html = $this->sync()->button( array( 'container' => '#my-flyout .list' ) );

		$this->assertStringContainsString( 'data-container="#my-flyout .list"', $html );
		$this->assertStringContainsString( '>Sync</button>', $html );
	}

	/**
	 * A user who may not run the sync gets no button.
	 */
	public function test_no_button_without_the_capability(): void {
		$GLOBALS['jobs_caps'] = array();

		$this->assertSame( '', $this->sync()->button() );
	}

	/**
	 * Registering an ID again replaces the sync, and its screen hook.
	 */
	public function test_registering_again_replaces(): void {
		$this->sync( array( 'title' => 'One' ) );
		$this->sync( array( 'title' => 'Two' ) );

		$this->assertSame( 'Two', Sync::get( 'stripe_prices' )->config( 'title' ) );
		$this->assertCount( 1, $GLOBALS['jobs_hooks']['admin_enqueue_scripts'] );
	}

	/**
	 * A failure's name is guessed from the item when there is no callback,
	 * and a name callback that throws falls back to the guess.
	 */
	public function test_an_item_is_named_from_its_fields(): void {
		$sync = $this->sync( array( 'name_callback' => null ) );

		$this->assertSame( 'Small', $sync->item_name( array( 'id' => 'p1', 'name' => 'Small' ) ) );
		$this->assertSame( 'a@example.test', $sync->item_name( (object) array( 'email' => 'a@example.test' ) ) );
		$this->assertSame( 'p1', $sync->item_name( array( 'id' => 'p1' ) ) );
		$this->assertSame( '', $sync->item_name( 'just a string' ) );

		$throws = $this->sync(
			array(
				'name_callback' => static function (): string {
					throw new \RuntimeException( 'no' );
				},
			)
		);

		$this->assertSame( 'Small', $throws->item_name( array( 'name' => 'Small' ) ) );
	}

	/**
	 * The assets are found where the library is, under the content folder.
	 */
	public function test_the_assets_are_served_from_where_the_library_is(): void {
		$this->sync()->enqueue();

		$script = $GLOBALS['jobs_registered']['sugarcommerce-jobs-sync']['script'];

		$this->assertStringStartsWith( 'https://example.test/wp-content/', $script );
		$this->assertStringEndsWith( '/sugarcommerce/jobs/assets/js/sync.js', $script );
	}
}
