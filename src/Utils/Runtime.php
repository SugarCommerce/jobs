<?php
/**
 * The names this library gives what it registers, derived from its own
 * namespace.
 *
 * @package   SugarCommerce\Jobs
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Utils;

/**
 * Class Runtime
 *
 * Strauss rewrites class namespaces and leaves string literals alone. Two
 * plugins each bundling a prefixed copy of this library get distinct
 * classes, but a script handle, an option or a REST namespace named in a
 * string would be one for both -- and that is not merely untidy:
 *
 * - `wp_enqueue_script()` ignores a handle already registered, so the
 *   plugin that enqueued second gets the other plugin's script, from a
 *   directory its own build may not even contain;
 * - two copies registering one REST namespace do not collide loudly: the
 *   second registration wins, and the first plugin's syncs resolve to the
 *   second plugin's registry, which has never heard of them;
 * - one option for both means one plugin's sync carries on from the other
 *   plugin's cursor.
 *
 * The prefix comes from the one thing Strauss does rewrite, this file's
 * namespace. In a prefixed build `__NAMESPACE__` begins with the plugin's
 * prefix ("MyPlugin\SugarCommerce\Jobs\Utils"), so every name comes out
 * distinct with nothing to configure. A plugin that wants a name of its own
 * choosing says so with {@see self::use_prefix()}.
 *
 * @since 1.0.0
 */
final class Runtime {

	/**
	 * This library's own identifier, used when running unprefixed.
	 *
	 * @since 1.0.0
	 */
	private const LIBRARY = 'sugarcommerce_jobs';

	/**
	 * A prefix set by the plugin, in place of the derived one.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private static ?string $chosen = null;

	/**
	 * Name everything this build registers from a prefix of the plugin's
	 * own, rather than from the namespace.
	 *
	 * Call it where the plugin boots, before any sync is registered, and
	 * never change it once shipped: a sync's saved progress is kept under
	 * this name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Letters, digits and underscores: `myplugin`.
	 *                       Empty to go back to the derived prefix.
	 *
	 * @return void
	 */
	public static function use_prefix( string $prefix ): void {
		$prefix       = self::slug( $prefix );
		self::$chosen = '' === $prefix ? null : $prefix;
	}

	/**
	 * The prefix for this build.
	 *
	 * `sugarcommerce_jobs` for a plain Composer install,
	 * `{prefix}_sugarcommerce_jobs` for a prefixed build, or what
	 * {@see self::use_prefix()} was told.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function prefix(): string {
		return self::$chosen ?? self::prefix_for( __NAMESPACE__ );
	}

	/**
	 * The prefix a given namespace would produce.
	 *
	 * Split out so the rule can be tested: prefix() reads __NAMESPACE__,
	 * which cannot be changed at runtime, so a test reaches the prefixed
	 * case by asking the rule directly.
	 *
	 * @since 1.0.0
	 *
	 * @param string $under The namespace this class is compiled under,
	 *                      prefixed or not.
	 *
	 * @return string
	 */
	public static function prefix_for( string $under ): string {
		$root = explode( '\\', ltrim( $under, '\\' ) )[0];

		if ( '' === $root || 'SugarCommerce' === $root ) {
			return self::LIBRARY;
		}

		$slug = self::slug( $root );

		return '' === $slug ? self::LIBRARY : $slug . '_' . self::LIBRARY;
	}

	/**
	 * An option or transient key for this build.
	 *
	 * @since 1.0.0
	 *
	 * @param string $suffix What the key is for.
	 *
	 * @return string
	 */
	public static function key( string $suffix = '' ): string {
		return '' === $suffix ? self::prefix() : self::prefix() . '_' . $suffix;
	}

	/**
	 * A script or style handle for this build.
	 *
	 * @since 1.0.0
	 *
	 * @param string $suffix What the handle is for.
	 *
	 * @return string
	 */
	public static function handle( string $suffix = '' ): string {
		$base = str_replace( '_', '-', self::prefix() );

		return '' === $suffix ? $base : $base . '-' . $suffix;
	}

	/**
	 * The REST namespace for this build.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		return self::handle() . '/v1';
	}

	/**
	 * Reduce a name to lowercase letters, digits and underscores.
	 *
	 * Not sanitize_key(): this can run from `__NAMESPACE__` before
	 * WordPress has loaded.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value The name.
	 *
	 * @return string
	 */
	private static function slug( string $value ): string {
		return strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', $value ), '_' ) );
	}
}
