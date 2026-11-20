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

use SugarCommerce\Jobs\Traits\Sync\Items;
use SugarCommerce\Jobs\Traits\Sync\Screen;
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

	use Items;
	use Screen;

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
	private const array DEFAULTS = [
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
	private const array RESULTS = [ 'created', 'updated', 'skipped' ];

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

	// =========================================================================
	// The work
	// =========================================================================

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
