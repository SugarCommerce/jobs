<?php
/**
 * Screen: the button, its script and its words, on the screens a sync is registered for.
 *
 * @package     SugarCommerce\Jobs
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Traits\Sync;

use SugarCommerce\Jobs\SyncRoutes;
use SugarCommerce\Jobs\Utils\Runtime;
use SugarCommerce\Plugin\Assets;

/**
 * Screen: the button, its script and its words, on the screens a sync is registered for.
 *
 * @since 1.0.0
 */
trait Screen {

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
}
