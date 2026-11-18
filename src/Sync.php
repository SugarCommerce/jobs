<?php
/**
 * A batch sync run from a button on an admin screen.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

use SugarCommerce\Jobs\Utils\Runtime;
use SugarCommerce\Plugin\Assets;
use Throwable;
use WP_Error;

/**
 * Class Sync
 *
 * Importing anything sizeable means batching: fetch a page, process it,
 * report progress, fetch the next -- and doing that from an admin screen
 * means a REST endpoint, a nonce, a JavaScript loop, a progress bar and
 * somewhere to keep the cursor between calls. This is that loop, written
 * once. A sync supplies two callbacks, one that fetches a page and one that
 * handles a single item; the batching, the progress bar above the list
 * table and the error reporting are done here.
 *
 *     Sync::register( 'stripe_prices', [
 *         'hook_suffix'      => 'toplevel_page_myplugin',
 *         'title'            => __( 'Prices', 'myplugin' ),
 *         'button_label'     => __( 'Sync from Stripe', 'myplugin' ),
 *         'data_callback'    => [ Prices::class, 'page' ],    // ( string $cursor ): array
 *         'process_callback' => [ Prices::class, 'import' ],  // ( $item ): 'created'|'updated'|'skipped'|WP_Error
 *     ] );
 *
 *     Sync::get( 'stripe_prices' )?->render_button();
 *
 * Register on `init`, not `admin_init`: the button is an admin thing, but
 * the work happens over REST, and `admin_init` does not run on a REST
 * request -- a sync registered there has a button and no endpoint behind it.
 *
 * Each sync is one operation with one ID; several can share a screen. With
 * `resume`, a sync writes down where it has got to as it goes, so a closed
 * browser carries on where it stopped ({@see Progress}), and
 * {@see SyncRetry} adds a button that tries every remembered failure again.
 *
 * @since 1.0.0
 */
final class Sync {

	/**
	 * Every sync registered with this copy, by ID.
	 *
	 * @since 1.0.0
	 * @var array<string, self>
	 */
	private static array $syncs = [];

	/**
	 * What a sync gets unless it says otherwise.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = [
		'hook_suffix'        => '',
		'capability'         => 'manage_options',
		'title'              => '',
		'button_label'       => '',
		'button_class'       => 'button',
		'container'          => '.wp-list-table',
		'reload_on_complete' => true,
		'chunk_size'         => 5,
		'data_callback'      => null,
		'process_callback'   => null,
		'name_callback'      => null,
		'resume'             => false,
		'progress_option'    => '',
		'max_failures'       => Progress::MAX_FAILURES,
		'id_callback'        => null,
		'retry_callback'     => null,
	];

	/**
	 * What an item can come to, besides failing.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const RESULTS = [ 'created', 'updated', 'skipped' ];

	/**
	 * The sync's ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $id;

	/**
	 * Its configuration.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Its progress, once asked for.
	 *
	 * @since 1.0.0
	 * @var Progress|null
	 */
	private ?Progress $progress = null;

	/**
	 * Use self::register().
	 *
	 * @since 1.0.0
	 *
	 * @param string               $id     The sync's ID.
	 * @param array<string, mixed> $config Its configuration.
	 */
	private function __construct( string $id, array $config ) {
		$this->id     = $id;
		$this->config = array_merge( self::DEFAULTS, $config );
	}

	/**
	 * Register a sync.
	 *
	 * Registering an ID again replaces the sync that had it.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $id     A unique ID, such as `stripe_prices`.
	 * @param array<string, mixed> $config {
	 *     @type string|string[] $hook_suffix        The admin screen(s) the
	 *                                               button is on, as
	 *                                               `admin_enqueue_scripts`
	 *                                               names them.
	 *     @type string          $capability         Who may run it. Default
	 *                                               `manage_options`.
	 *     @type string          $title              Shown in the progress bar.
	 *     @type string          $button_label       The button's text.
	 *                                               Default "Sync".
	 *     @type string          $button_class       The button's classes.
	 *                                               Default `button`.
	 *     @type string          $container          CSS selector the bar is
	 *                                               put in front of. Default
	 *                                               `.wp-list-table`.
	 *     @type bool            $reload_on_complete Reload the page when it
	 *                                               finishes. Default true.
	 *     @type int             $chunk_size         Items processed a
	 *                                               request. Default 5.
	 *     @type callable        $data_callback      Fetches a page:
	 *                                               ( string $cursor ):
	 *                                               array{items, has_more,
	 *                                               cursor, total}.
	 *     @type callable        $process_callback   Handles one item:
	 *                                               `created`, `updated` or
	 *                                               `skipped`; a WP_Error or
	 *                                               false for a failure;
	 *                                               anything else counts as
	 *                                               created.
	 *     @type callable        $name_callback      What to call an item on
	 *                                               screen. Default its name,
	 *                                               title, label, email or ID.
	 *     @type bool            $resume             Remember the page reached,
	 *                                               what each item came to
	 *                                               and what failed. Default
	 *                                               false.
	 *     @type string          $progress_option    The option that is kept
	 *                                               in. Default derived from
	 *                                               the build and the ID.
	 *     @type int             $max_failures       The most failures
	 *                                               remembered. Default 500.
	 *     @type callable        $id_callback        An item's ID, to key its
	 *                                               failure by. Default its
	 *                                               `id`, else its name.
	 *     @type callable        $retry_callback     A fresh copy of a failed
	 *                                               item to try again:
	 *                                               ( array $failure ) as
	 *                                               Progress recorded it,
	 *                                               returning the item or a
	 *                                               WP_Error. Without one the
	 *                                               item is kept with its
	 *                                               failure and replayed.
	 * }
	 *
	 * @return self
	 */
	public static function register( string $id, array $config ): self {
		$sync = new self( sanitize_key( $id ), $config );

		self::unregister( $sync->id );

		self::$syncs[ $sync->id ] = $sync;

		SyncRoutes::register();

		add_action( 'admin_enqueue_scripts', [ $sync, 'maybe_enqueue_assets' ] );

		return $sync;
	}

	/**
	 * A registered sync.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The sync's ID.
	 *
	 * @return self|null
	 */
	public static function get( string $id ): ?self {
		return self::$syncs[ $id ] ?? null;
	}

	/**
	 * Whether a sync is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The sync's ID.
	 *
	 * @return bool
	 */
	public static function has( string $id ): bool {
		return isset( self::$syncs[ $id ] );
	}

	/**
	 * Every sync registered with this copy.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, self>
	 */
	public static function all(): array {
		return self::$syncs;
	}

	/**
	 * The syncs whose button is on a screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The screen, as `admin_enqueue_scripts`
	 *                            names it.
	 *
	 * @return array<string, self>
	 */
	public static function on_screen( string $hook_suffix ): array {
		return array_filter( self::$syncs, static fn( self $sync ): bool => $sync->is_target_screen( $hook_suffix ) );
	}

	/**
	 * Forget a sync.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The sync's ID.
	 *
	 * @return bool False when there was none.
	 */
	public static function unregister( string $id ): bool {
		if ( ! isset( self::$syncs[ $id ] ) ) {
			return false;
		}

		remove_action( 'admin_enqueue_scripts', [ self::$syncs[ $id ], 'maybe_enqueue_assets' ] );

		unset( self::$syncs[ $id ] );

		return true;
	}

	// =========================================================================
	// The screen
	// =========================================================================

	/**
	 * Load the script and styles on the sync's screens, for those allowed
	 * to run it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The screen.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets( $hook_suffix ): void {
		if ( ! $this->is_target_screen( (string) $hook_suffix ) || ! current_user_can( (string) $this->config['capability'] ) ) {
			return;
		}

		$this->enqueue();
	}

	/**
	 * Whether the button is on a screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The screen.
	 *
	 * @return bool
	 */
	public function is_target_screen( string $hook_suffix ): bool {
		return in_array( $hook_suffix, array_map( 'strval', (array) $this->config['hook_suffix'] ), true );
	}

	/**
	 * Load the script and styles, and hand the script this sync.
	 *
	 * Done on the sync's screens by itself; call it for a button anywhere
	 * else -- in a flyout, on a screen of another plugin's.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$handle = Runtime::handle( 'sync' );

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			Assets::register_style( $handle, __FILE__, 'css/sync.css' );
			Assets::register_script( $handle, __FILE__, 'js/sync.js' );

			// Merged into rather than assigned over. Strauss gives each
			// prefixed copy its own handle, so two plugins bundling this
			// library both enqueue the script and both add their syncs; the
			// script that runs first serves them all. Assigning the object
			// would leave whichever plugin ran first with no syncs at all.
			wp_add_inline_script( $handle, 'window.SugarSyncConfig = window.SugarSyncConfig || { syncs: {} };', 'before' );
		}

		wp_enqueue_style( $handle );
		wp_enqueue_script( $handle );

		// The endpoint is the sync's own and not the page's: every build has
		// its own REST namespace.
		wp_add_inline_script(
			$handle,
			sprintf(
				'window.SugarSyncConfig.syncs[%s] = %s;',
				wp_json_encode( $this->id ),
				wp_json_encode(
					[
						'title'              => (string) $this->config['title'],
						'container'          => (string) $this->config['container'],
						'reload_on_complete' => (bool) $this->config['reload_on_complete'],
						'restUrl'            => rest_url( SyncRoutes::rest_namespace() . '/' ),
						'restNonce'          => wp_create_nonce( 'wp_rest' ),
						'i18n'               => self::strings(),
					]
				)
			),
			'before'
		);
	}

	/**
	 * What the script says, translated.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private static function strings(): array {
		return [
			'fetching'      => __( 'Fetching…', 'sugarcommerce' ),
			'syncing'       => __( 'Syncing…', 'sugarcommerce' ),
			'cancel'        => __( 'Cancel', 'sugarcommerce' ),
			'confirmCancel' => __( 'Cancel the current sync operation?', 'sugarcommerce' ),
			'cancelled'     => __( 'Sync cancelled.', 'sugarcommerce' ),
			'complete'      => __( 'Sync complete!', 'sugarcommerce' ),
			'created'       => __( 'created', 'sugarcommerce' ),
			'updated'       => __( 'updated', 'sugarcommerce' ),
			'skipped'       => __( 'skipped', 'sugarcommerce' ),
			'failed'        => __( 'failed', 'sugarcommerce' ),
			'items'         => __( 'items', 'sugarcommerce' ),
			'synced'        => __( 'synced', 'sugarcommerce' ),
			'of'            => __( 'of', 'sugarcommerce' ),
			'syncFailed'    => __( 'Sync failed. Please try again.', 'sugarcommerce' ),
			'dismiss'       => __( 'Dismiss', 'sugarcommerce' ),
			'reloading'     => __( 'Reloading…', 'sugarcommerce' ),
		];
	}

	/**
	 * Print the button that starts the sync.
	 *
	 * The script binds to it by itself. Nothing for a user who may not run
	 * the sync.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $args {
	 *     Optional.
	 *
	 *     @type string $label     The text. Default `button_label`.
	 *     @type string $class     The classes. Default `button_class`.
	 *     @type string $container Where the bar goes. Default `container`.
	 * }
	 *
	 * @return void
	 */
	public function render_button( array $args = [] ): void {
		echo $this->button( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as built, below.
	}

	/**
	 * The button that starts the sync, as markup.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $args See self::render_button().
	 *
	 * @return string Empty for a user who may not run the sync.
	 */
	public function button( array $args = [] ): string {
		if ( ! current_user_can( (string) $this->config['capability'] ) ) {
			return '';
		}

		$label = (string) ( $args['label'] ?? $this->config['button_label'] );

		// No glyph in front of the label: core draws none on its own
		// buttons, and the spinning state is core's .updating-message.
		return sprintf(
			'<button type="button" class="%s sugar-sync-trigger" data-sync-id="%s" data-container="%s">%s</button>',
			esc_attr( (string) ( $args['class'] ?? $this->config['button_class'] ) ),
			esc_attr( $this->id ),
			esc_attr( (string) ( $args['container'] ?? $this->config['container'] ) ),
			esc_html( '' !== $label ? $label : __( 'Sync', 'sugarcommerce' ) )
		);
	}

	// =========================================================================
	// The work
	// =========================================================================

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

	// =========================================================================
	// Accessors
	// =========================================================================

	/**
	 * The sync's ID.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * One setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key      The setting.
	 * @param mixed  $fallback What to answer when it is not set.
	 *
	 * @return mixed
	 */
	public function config( string $key, mixed $fallback = null ): mixed {
		return $this->config[ $key ] ?? $fallback;
	}

	/**
	 * Items processed a request.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function chunk_size(): int {
		return max( 1, (int) $this->config['chunk_size'] );
	}

	/**
	 * What fetches a page, if it can be called.
	 *
	 * @since 1.0.0
	 *
	 * @return callable|null
	 */
	public function data_callback(): ?callable {
		return is_callable( $this->config['data_callback'] ) ? $this->config['data_callback'] : null;
	}

	/**
	 * What processes an item, if it can be called.
	 *
	 * @since 1.0.0
	 *
	 * @return callable|null
	 */
	public function process_callback(): ?callable {
		return is_callable( $this->config['process_callback'] ) ? $this->config['process_callback'] : null;
	}

	/**
	 * What names an item, if it can be called.
	 *
	 * @since 1.0.0
	 *
	 * @return callable|null
	 */
	public function name_callback(): ?callable {
		return is_callable( $this->config['name_callback'] ) ? $this->config['name_callback'] : null;
	}

	/**
	 * What fetches a fresh copy of a failed item, if it can be called.
	 *
	 * @since 1.0.0
	 *
	 * @return callable|null
	 */
	public function retry_callback(): ?callable {
		return is_callable( $this->config['retry_callback'] ) ? $this->config['retry_callback'] : null;
	}
}
