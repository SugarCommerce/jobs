<?php
/**
 * Runtime key tests.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use SugarCommerce\Jobs\SyncRoutes;
use SugarCommerce\Jobs\Utils\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Strauss renames classes and namespaces. It does not rename strings.
 *
 * So two plugins bundling this library get two isolated sets of classes and
 * one shared REST namespace, one shared script handle and one shared transient
 * key -- and none of the three collides loudly. The second REST registration
 * wins and the first plugin's routes resolve to the second plugin's callbacks,
 * against a registry that has never heard of the first plugin's syncs.
 *
 * Every one of those keys is derived from this file's namespace, which is the
 * one thing Strauss does rewrite.
 */
final class RuntimeTest extends TestCase {

	/**
	 * Back to the derived prefix.
	 */
	protected function setUp(): void {
		jobs_reset();
	}

	/**
	 * Unprefixed, the keys are the library's own.
	 */
	public function test_an_unprefixed_build_uses_the_library_name(): void {
		$this->assertSame( 'sugarcommerce_jobs', Runtime::prefix_for( 'SugarCommerce\\Jobs\\Utils' ) );
		$this->assertSame( 'sugarcommerce_jobs', Runtime::prefix() );
	}

	/**
	 * A plugin can name things itself, and go back.
	 */
	public function test_a_chosen_prefix_wins_until_cleared(): void {
		Runtime::use_prefix( 'My-Shop' );

		$this->assertSame( 'my_shop', Runtime::prefix() );
		$this->assertSame( 'my_shop_sync_progress_orders', Runtime::key( 'sync_progress_orders' ) );
		$this->assertSame( 'my-shop/v1', SyncRoutes::rest_namespace() );

		Runtime::use_prefix( '' );

		$this->assertSame( 'sugarcommerce_jobs', Runtime::prefix() );
	}

	/**
	 * Prefixed, every key carries the consumer's prefix.
	 *
	 * @param string $namespace What the class is compiled under.
	 * @param string $expected  The prefix it should produce.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'namespaceProvider' )]
	public function test_a_prefixed_build_is_namespaced( string $namespace, string $expected ): void {
		$this->assertSame( $expected, Runtime::prefix_for( $namespace ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function namespaceProvider(): array {
		return array(
			'unprefixed'       => array( 'SugarCommerce\\Jobs\\Utils', 'sugarcommerce_jobs' ),
			'a plugin prefix'  => array( 'MyPlugin\\SugarCommerce\\Jobs\\Utils', 'myplugin_sugarcommerce_jobs' ),
			'with underscores' => array( 'EDD_Fraud_Filter\\SugarCommerce\\Jobs\\Utils', 'edd_fraud_filter_sugarcommerce_jobs' ),
			'leading slash'    => array( '\\SugarCart\\Vendor\\SugarCommerce\\Jobs\\Utils', 'sugarcart_sugarcommerce_jobs' ),
			'empty'            => array( '', 'sugarcommerce_jobs' ),
		);
	}

	/**
	 * Two prefixed builds share no key at all.
	 *
	 * The assertion that matters: not that the keys look right, but that they
	 * differ. A shared key is the whole failure.
	 */
	public function test_two_builds_share_nothing(): void {
		$one = Runtime::prefix_for( 'PluginOne\\SugarCommerce\\Jobs\\Utils' );
		$two = Runtime::prefix_for( 'PluginTwo\\SugarCommerce\\Jobs\\Utils' );

		$this->assertNotSame( $one, $two );
	}

	/**
	 * The REST namespace is derived, not a constant.
	 *
	 * A constant is fixed at compile time and identical in every prefixed
	 * copy, which is precisely the collision being avoided. It used to be one.
	 */
	public function test_the_rest_namespace_is_derived(): void {
		$this->assertSame( 'sugarcommerce-jobs/v1', SyncRoutes::rest_namespace() );
		$this->assertStringEndsWith( '/v1', SyncRoutes::rest_namespace() );

		$reflection = new \ReflectionClass( SyncRoutes::class );

		$this->assertArrayNotHasKey(
			'NAMESPACE',
			$reflection->getConstants(),
			'The REST namespace is a constant again, so every prefixed copy shares it.'
		);
	}

	/**
	 * A transient key carries the prefix and the sync it belongs to.
	 */
	public function test_a_transient_key_is_scoped(): void {
		$key = Runtime::key( 'stripe_prices_1' );

		$this->assertStringStartsWith( Runtime::prefix(), $key );
		$this->assertStringContainsString( 'stripe_prices', $key );

		// Underscores, not hyphens: this is an option key, not a handle.
		$this->assertStringNotContainsString( '-', $key );
	}

	/**
	 * A script handle is a handle, not an option key.
	 */
	public function test_a_handle_is_hyphenated(): void {
		$this->assertSame( 'sugarcommerce-jobs', Runtime::handle() );
		$this->assertSame( 'sugarcommerce-jobs-sync', Runtime::handle( 'sync' ) );
	}

	/**
	 * No runtime key is written as a literal in src/.
	 *
	 * The assertion the value-based tests cannot make. In an unprefixed build
	 * Runtime::prefix() returns "sugarcommerce_jobs", so a hardcoded
	 * "sugarcommerce-jobs/v1"
	 * is indistinguishable from a derived one -- every test passes and the
	 * collision comes back the moment two plugins bundle this.
	 *
	 * So the check is on the source: these strings may appear in the CSS class
	 * names and in the shared JavaScript object, both of which are shared on
	 * purpose, but never as a REST namespace or a script handle.
	 */
	public function test_no_runtime_key_is_hardcoded(): void {
		$forbidden = array(
			"'sugarcommerce-jobs/v1'" => 'a REST namespace',
			"'sugarcommerce_jobs_'"   => 'an option or transient key',
			"'sugarcommerce-jobs'"    => 'a script handle',
		);

		$files = glob( dirname( __DIR__ ) . '/src/{,*/}*.php', GLOB_BRACE );

		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			$name   = basename( $file );

			// Runtime.php names the library once, which is where the
			// derivation starts.
			if ( 'Runtime.php' === $name ) {
				continue;
			}

			foreach ( $forbidden as $literal => $what ) {
				$this->assertStringNotContainsString(
					$literal,
					$source,
					sprintf( '%s hardcodes %s; every prefixed copy would share it.', $name, $what )
				);
			}

			// A script handle assigned from a literal rather than Runtime.
			$this->assertDoesNotMatchRegularExpression(
				"/\\\$handle\\s*=\\s*'/",
				$source,
				sprintf( '%s assigns a script handle from a literal.', $name )
			);
		}
	}
}
