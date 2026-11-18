<?php
/**
 * Resumable syncs and retrying their failures.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use SugarCommerce\Jobs\Progress;
use SugarCommerce\Jobs\SyncRoutes;
use SugarCommerce\Jobs\SyncRetry;
use SugarCommerce\Jobs\Sync;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;
use WP_REST_Request;

/**
 * A sync registered with `resume` carries on where a closed browser left it,
 * and remembers what failed so a retry sync can try it again.
 */
final class ResumeTest extends TestCase {

	/**
	 * Items processed a request, by default.
	 */
	private const CHUNK = 5;

	/**
	 * Every cursor the data callback was asked for.
	 *
	 * @var string[]
	 */
	private array $asked = array();

	/**
	 * Empty the registry and the stubbed WordPress.
	 */
	protected function setUp(): void {
		jobs_reset();

		foreach ( array_keys( Sync::all() ) as $id ) {
			Sync::unregister( (string) $id );
		}

		$this->asked = array();
	}

	/**
	 * Register a resumable sync over three pages of two items: page N
	 * (cursor '' or 'N') holds items N-a and N-b.
	 *
	 * @param array $config Overrides.
	 *
	 * @return Sync
	 */
	private function sync( array $config = array() ): Sync {
		$sync = Sync::register(
			'import',
			array_merge(
				array(
					'resume'           => true,
					'data_callback'    => function ( $cursor ) {
						$this->asked[] = (string) $cursor;
						$page          = '' === (string) $cursor ? 1 : (int) $cursor;

						return array(
							'items'    => array(
								array( 'id' => $page . '-a', 'name' => 'Item ' . $page . 'a' ),
								array( 'id' => $page . '-b', 'name' => 'Item ' . $page . 'b' ),
							),
							'has_more' => $page < 3,
							'cursor'   => (string) ( $page + 1 ),
							'total'    => 6,
						);
					},
					'process_callback' => static fn( $item ) => 'created',
				),
				$config
			)
		);

		return $sync;
	}

	/**
	 * Fetch a page.
	 *
	 * @param string $cursor The cursor the browser sends.
	 * @param string $id     The sync.
	 *
	 * @return array
	 */
	private function fetch( string $cursor = '', string $id = 'import' ): array {
		return SyncRoutes::handle_fetch(
			new WP_REST_Request(
				array(
					'sync_id' => $id,
					'cursor'  => $cursor,
				)
			)
		)->get_data();
	}

	/**
	 * Process the staged page to the end.
	 *
	 * @param string $id The sync.
	 *
	 * @return array The last response.
	 */
	private function process( string $id = 'import' ): array {
		do {
			$data = SyncRoutes::handle_process( new WP_REST_Request( array( 'sync_id' => $id ) ) )->get_data();
		} while ( empty( $data['page_done'] ) );

		return $data;
	}

	/**
	 * The page being read is written down before it is read.
	 */
	public function test_the_cursor_is_written_before_the_page_is_read(): void {
		$sync = $this->sync(
			array(
				'data_callback' => function ( $cursor ) use ( &$sync ) {
					$this->asked[] = $sync->progress()->cursor();

					throw new RuntimeException( 'The other end is down.' );
				},
			)
		);

		$error = SyncRoutes::handle_fetch(
			new WP_REST_Request(
				array(
					'sync_id' => 'import',
					'cursor'  => '4',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( array( '4' ), $this->asked );
		$this->assertSame( '4', $sync->progress()->cursor() );
	}

	/**
	 * A browser closed half-way carries on from the page it was on.
	 */
	public function test_an_empty_cursor_carries_on_from_the_page_reached(): void {
		$sync = $this->sync();

		$this->fetch();
		$this->process();
		$this->fetch( '2' );
		// The browser closes before page 2 is processed.

		$this->fetch();

		$this->assertSame( array( '', '2', '2' ), $this->asked );
		$this->assertFalse( $sync->progress()->is_done() );
	}

	/**
	 * A processed page moves the cursor on, so it is not read again.
	 */
	public function test_a_processed_page_moves_the_cursor_on(): void {
		$sync = $this->sync();

		$this->fetch();
		$this->process();

		$this->assertSame( '2', $sync->progress()->cursor() );

		$this->fetch();

		$this->assertSame( array( '', '2' ), $this->asked );
	}

	/**
	 * After the last page is processed the sync is done, and starting it
	 * again does nothing until it is reset.
	 */
	public function test_a_finished_sync_does_nothing_until_reset(): void {
		$sync = $this->sync();

		foreach ( array( '', '2', '3' ) as $cursor ) {
			$this->fetch( $cursor );
			$this->assertFalse( $sync->progress()->is_done(), 'Done before the last page was processed.' );
			$this->process();
		}

		$this->assertTrue( $sync->progress()->is_done() );
		$this->assertSame( '', $sync->progress()->cursor() );

		$again = $this->fetch();

		$this->assertSame( 0, $again['fetched'] );
		$this->assertFalse( $again['has_more'] );
		$this->assertCount( 3, $this->asked, 'A finished sync read its source again.' );

		$sync->reset_progress();
		$this->fetch();

		$this->assertSame( '', end( $this->asked ) );
		$this->assertFalse( $sync->progress()->is_done() );
		$this->assertSame( 0, $sync->progress()->get()['created'] );
	}

	/**
	 * A page bigger than one chunk still moves the cursor on, and the last
	 * one still finishes the sync: the staged page keeps where it goes next
	 * between chunks.
	 */
	public function test_a_page_of_several_chunks_moves_on_and_finishes(): void {
		$per_page = self::CHUNK * 2 + 1;
		$sync     = $this->sync(
			array(
				'data_callback' => static function ( $cursor ) use ( $per_page ) {
					$page = '' === (string) $cursor ? 1 : (int) $cursor;

					return array(
						'items'    => array_map(
							static fn( int $n ): array => array(
								'id'   => $page . '-' . $n,
								'name' => 'Item ' . $page . '-' . $n,
							),
							range( 1, $per_page )
						),
						'has_more' => $page < 2,
						'cursor'   => (string) ( $page + 1 ),
					);
				},
			)
		);

		$this->fetch();
		$this->process();

		$this->assertSame( '2', $sync->progress()->cursor() );
		$this->assertFalse( $sync->progress()->is_done() );

		$this->fetch( '2' );
		$this->process();

		$this->assertTrue( $sync->progress()->is_done() );
		$this->assertSame( $per_page * 2, $sync->progress()->get()['created'] );
	}

	/**
	 * A cursor the browser sends is used as sent, even when one is saved.
	 */
	public function test_an_explicit_cursor_wins(): void {
		$this->sync();

		$this->fetch();
		$this->process();
		$this->fetch( '3' );

		$this->assertSame( array( '', '3' ), $this->asked );
	}

	/**
	 * An empty last page finishes the sync at the fetch.
	 */
	public function test_an_empty_last_page_finishes(): void {
		$sync = $this->sync(
			array(
				'data_callback' => static fn( $cursor ) => array(
					'items'    => array(),
					'has_more' => false,
					'cursor'   => '',
				),
			)
		);

		$this->fetch();

		$this->assertTrue( $sync->progress()->is_done() );
	}

	/**
	 * What each item came to is counted, and the progress is not autoloaded.
	 */
	public function test_results_are_counted(): void {
		$results = array( 'created', 'updated', 'skipped', 'something else', false, new WP_Error( 'x', 'No.' ) );
		$sync    = $this->sync(
			array(
				'data_callback'    => static fn( $cursor ) => array(
					'items'    => array_map( static fn( int $n ) => array( 'id' => (string) $n ), range( 0, 5 ) ),
					'has_more' => false,
				),
				'process_callback' => static fn( $item ) => $results[ (int) $item['id'] ],
			)
		);

		$this->fetch();
		$this->process();

		$progress = $sync->progress()->get();

		$this->assertSame( array( 2, 1, 1, 2 ), array( $progress['created'], $progress['updated'], $progress['skipped'], $progress['failed'] ) );
		$this->assertFalse( $GLOBALS['jobs_autoload']['sugarcommerce_jobs_sync_progress_import'] );
	}

	/**
	 * A failure is remembered with its reason, by item id, and struck off
	 * when the item succeeds later.
	 */
	public function test_failures_are_remembered_and_struck_off(): void {
		$fail = true;
		$sync = $this->sync(
			array(
				'process_callback' => static function ( $item ) use ( &$fail ) {
					if ( $fail && '1-b' === $item['id'] ) {
						throw new RuntimeException( 'The file link expired.' );
					}

					return 'created';
				},
			)
		);

		$this->fetch();
		$this->process();

		$failures = $sync->progress()->failures();

		$this->assertCount( 1, $failures );
		$this->assertSame( 'import', $failures[0]['sync'] );
		$this->assertSame( '1-b', $failures[0]['id'] );
		$this->assertSame( 'Item 1b', $failures[0]['name'] );
		$this->assertSame( 'The file link expired.', $failures[0]['message'] );
		$this->assertSame(
			array(
				'id'   => '1-b',
				'name' => 'Item 1b',
			),
			$failures[0]['item'],
			'Without a retry callback the item is kept to replay.'
		);

		$fail = false;
		$sync->reset_progress();
		$this->fetch();
		$this->process();

		$this->assertSame( array(), $sync->progress()->failures() );
	}

	/**
	 * With a retry callback the item is not kept: a fresh copy is fetched.
	 */
	public function test_a_retry_callback_means_the_item_is_not_kept(): void {
		$sync = $this->sync(
			array(
				'process_callback' => static fn( $item ) => new WP_Error( 'x', 'No.' ),
				'retry_callback'   => static fn( array $failure ) => array( 'id' => $failure['id'] ),
				'id_callback'      => static fn( $item ) => 'ls-' . $item['id'],
			)
		);

		$this->fetch();
		$this->process();

		$failures = $sync->progress()->failures();

		$this->assertArrayNotHasKey( 'item', $failures[0] );
		$this->assertSame( 'ls-1-a', $failures[0]['id'] );
	}

	/**
	 * The failure list is capped, keeping the newest.
	 */
	public function test_the_failure_list_is_capped(): void {
		$progress = new Progress( 'capped', 2 );

		$progress->record( 's', 'a', 'A', new WP_Error( 'x', 'one' ) );
		$progress->record( 's', 'b', 'B', new WP_Error( 'x', 'two' ) );
		$progress->record( 's', 'a', 'A', new WP_Error( 'x', 'three' ) );
		$progress->record( 's', 'c', 'C', new WP_Error( 'x', 'four' ) );

		$this->assertSame( array( 'three', 'four' ), array_column( $progress->failures(), 'message' ) );
		$this->assertSame( 4, $progress->get()['failed'] );
	}

	/**
	 * An item with no id is keyed by its name.
	 */
	public function test_an_item_without_an_id_is_keyed_by_name(): void {
		$progress = new Progress( 'nameless' );

		$progress->record( 's', '', 'A', new WP_Error( 'x', 'one' ) );
		$progress->record( 's', '', 'B', new WP_Error( 'x', 'two' ) );
		$progress->record( 's', '', 'A', 'updated' );

		$this->assertSame( array( 'B' ), array_column( $progress->failures(), 'name' ) );
	}

	/**
	 * A sync without `resume` writes nothing down.
	 */
	public function test_a_sync_without_resume_keeps_nothing(): void {
		$sync = $this->sync( array( 'resume' => false ) );

		$this->fetch();
		$this->process();
		$this->fetch();

		$this->assertNull( $sync->progress() );
		$this->assertSame( array(), $GLOBALS['jobs_options'] );
		$this->assertSame( array( '', '' ), $this->asked );
	}

	/**
	 * The option can be named.
	 */
	public function test_the_option_can_be_named(): void {
		$this->sync( array( 'progress_option' => 'my_import_progress' ) );

		$this->fetch();

		$this->assertArrayHasKey( 'my_import_progress', $GLOBALS['jobs_options'] );
	}

	/**
	 * A retry sync replays every failure of the syncs it covers, through
	 * the sync each came from; what succeeds is struck off.
	 */
	public function test_a_retry_sync_replays_failures(): void {
		$broken = true;
		$seen   = array();

		$first = $this->sync(
			array(
				'process_callback' => static function ( $item ) use ( &$broken, &$seen ) {
					$seen[] = $item['id'];

					return $broken && str_ends_with( $item['id'], '-b' ) ? false : 'created';
				},
			)
		);

		$this->fetch();
		$this->process();

		$this->assertCount( 1, $first->progress()->failures() );

		$retry = SyncRetry::register( 'import_retry', array( 'import', 'not_registered' ), array( 'title' => 'Trying again' ) );

		$this->assertSame( 'Trying again', $retry->config( 'title' ) );

		$broken = true;
		$page   = $this->fetch( '', 'import_retry' );
		$this->assertSame( 1, $page['fetched'] );

		$still = $this->process( 'import_retry' );
		$this->assertSame( 1, $still['failed'] );
		$this->assertCount( 1, $first->progress()->failures(), 'A failure that failed again was lost.' );

		$broken = false;
		$seen   = array();
		$this->fetch( '', 'import_retry' );
		$done = $this->process( 'import_retry' );

		$this->assertSame( 1, $done['created'] );
		$this->assertSame( array( '1-b' ), $seen );
		$this->assertSame( array(), $first->progress()->failures() );
	}

	/**
	 * A retry callback supplies the fresh copy; when it cannot, the failure
	 * stays with the new reason.
	 */
	public function test_a_retry_uses_the_fresh_copy(): void {
		$fresh = new WP_Error( 'gone', 'Not found at the source.' );
		$seen  = array();

		$sync = $this->sync(
			array(
				'process_callback' => static function ( $item ) use ( &$seen ) {
					$seen[] = $item;

					return isset( $item['fresh'] ) ? 'updated' : new WP_Error( 'x', 'Link expired.' );
				},
				'retry_callback'   => static function ( array $failure ) use ( &$fresh ) {
					return $fresh;
				},
			)
		);

		$this->fetch();
		$this->process();

		SyncRetry::register( 'import_retry', array( 'import' ) );

		$this->fetch( '', 'import_retry' );
		$this->process( 'import_retry' );

		$messages = array_column( $sync->progress()->failures(), 'message' );
		$this->assertSame( array( 'Not found at the source.', 'Not found at the source.' ), $messages );

		$fresh = array(
			'id'    => '1-a',
			'fresh' => true,
		);
		$this->fetch( '', 'import_retry' );
		$this->process( 'import_retry' );

		$this->assertSame( array( '1-b' ), array_column( $sync->progress()->failures(), 'id' ), 'The fresh copy keyed by its own id struck off 1-a.' );
		$this->assertSame( $fresh, end( $seen ) );
	}

	/**
	 * A failure whose sync is gone is skipped, not an error.
	 */
	public function test_a_failure_from_a_missing_sync_is_skipped(): void {
		$this->assertSame( 'skipped', SyncRetry::process( array( 'sync' => 'gone', 'id' => '1' ) ) );
	}
}
