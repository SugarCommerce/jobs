<?php
/**
 * PHPUnit bootstrap.
 *
 * A library, not a plugin. Enough of WordPress is stubbed for each half:
 *
 * - WP-Cron's queue, held in a global, so a test can schedule, look and
 *   unschedule exactly as the real functions would -- including the part
 *   that matters most, which is that a hook may be in the queue more than
 *   once;
 * - Action Scheduler, as a list of pending actions, unless the run is the
 *   one without it (`composer test` makes both);
 * - options, transients, capabilities, scripts and REST registration;
 * - `$wpdb`, on an in-memory SQLite database, so the queue's claims, the
 *   inbox's insert and the lock's lease are real SQL answering real
 *   affected-row counts, not a stub's say-so.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 5 ) . '/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', dirname( __DIR__, 4 ) );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * Classes.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = '' ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( private array $params = [] ) {}
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
		public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public $data = null, public int $status = 200 ) {}
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
	}
}

/**
 * `$wpdb` on SQLite: the handful of methods this library calls, with the
 * same answers -- affected rows from query(), false on an error, an
 * insert_id, NULL handled in update()'s data and where.
 */
class Jobs_Test_Wpdb {

	public string $prefix  = 'wp_';
	public string $options = 'wp_options';
	public int $insert_id  = 0;
	public string $last_error = '';
	public array $queries  = [];
	public PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_options ( option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL DEFAULT "on" )' );
	}

	public function prepare( string $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$index = 0;

		return preg_replace_callback(
			'/%(%|d|s|f)/',
			function ( array $match ) use ( &$args, &$index ) {
				if ( '%' === $match[1] ) {
					return '%';
				}

				$value = $args[ $index++ ] ?? null;

				return match ( $match[1] ) {
					'd'     => (string) (int) $value,
					'f'     => (string) (float) $value,
					default => $this->pdo->quote( (string) $value ),
				};
			},
			$query
		);
	}

	private function sqlite( string $sql ): string {
		return str_replace( 'INSERT IGNORE', 'INSERT OR IGNORE', $sql );
	}

	public function query( string $sql ) {
		$this->queries[] = $sql;

		try {
			return $this->pdo->exec( $this->sqlite( $sql ) );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	public function get_col( string $sql ) {
		return $this->pdo->query( $this->sqlite( $sql ) )->fetchAll( PDO::FETCH_COLUMN );
	}

	public function get_var( string $sql ) {
		$value = $this->pdo->query( $this->sqlite( $sql ) )->fetchColumn();

		return false === $value ? null : $value;
	}

	public function get_row( string $sql, $output = 'OBJECT' ) {
		$row = $this->pdo->query( $this->sqlite( $sql ) )->fetch( PDO::FETCH_ASSOC );

		if ( false === $row ) {
			return null;
		}

		return ARRAY_A === $output ? $row : (object) $row;
	}

	public function insert( string $table, array $data ) {
		$columns = implode( ', ', array_keys( $data ) );
		$marks   = implode( ', ', array_fill( 0, count( $data ), '?' ) );

		try {
			$this->pdo->prepare( "INSERT INTO {$table} ({$columns}) VALUES ({$marks})" )->execute( array_values( $data ) );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}

		$this->insert_id = (int) $this->pdo->lastInsertId();

		return 1;
	}

	public function update( string $table, array $data, array $where ) {
		$set    = [];
		$values = [];

		foreach ( $data as $column => $value ) {
			if ( null === $value ) {
				$set[] = "{$column} = NULL";
			} else {
				$set[]    = "{$column} = ?";
				$values[] = $value;
			}
		}

		$conditions = [];

		foreach ( $where as $column => $value ) {
			if ( null === $value ) {
				$conditions[] = "{$column} IS NULL";
			} else {
				$conditions[] = "{$column} = ?";
				$values[]     = $value;
			}
		}

		$statement = $this->pdo->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) );
		$statement->execute( $values );

		return $statement->rowCount();
	}
}

/*
 * Hooks, request kind, options, transients and the site's timezone. Each
 * reads a global a test can set.
 */
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['jobs_hooks'][ $hook ][] = $callback;
		$GLOBALS['jobs_hook_args'][ $hook ][] = $args;

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		return add_action( $hook, $callback, $priority, $args );
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		foreach ( $GLOBALS['jobs_hooks'][ $hook ] ?? [] as $index => $attached ) {
			if ( $attached === $callback ) {
				unset( $GLOBALS['jobs_hooks'][ $hook ][ $index ] );

				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['jobs_hooks'][ $hook ] ?? [] as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return (int) ( $GLOBALS['jobs_did'][ $hook ] ?? 0 );
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = null ) {
		return ( $GLOBALS['jobs_doing'] ?? '' ) === $hook;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return (bool) ( $GLOBALS['jobs_is_admin'] ?? false );
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() {
		return (bool) ( $GLOBALS['jobs_doing_cron'] ?? false );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $fallback = false ) {
		return array_key_exists( $name, $GLOBALS['jobs_options'] ?? [] ) ? $GLOBALS['jobs_options'][ $name ] : $fallback;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['jobs_options'][ $name ]  = $value;
		$GLOBALS['jobs_autoload'][ $name ] = $autoload;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['jobs_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['jobs_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['jobs_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['jobs_transients'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( $GLOBALS['jobs_timezone'] ?? 'UTC' );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['jobs_user_id'] ?? 1 );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['jobs_caps'][ $capability ] );
	}
}

/*
 * WP-Cron. Each entry is a hook, its arguments and when it next runs --
 * which is all wp_next_scheduled() and wp_clear_scheduled_hook() look at.
 */
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [], $wp_error = false ) {
		if ( ! is_numeric( $timestamp ) || $timestamp <= 0 ) {
			return false;
		}

		$GLOBALS['cron_queue'][] = [
			'hook'       => $hook,
			'args'       => $args,
			'timestamp'  => (int) $timestamp,
			'recurrence' => $recurrence,
		];

		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = [], $wp_error = false ) {
		return wp_schedule_event( $timestamp, '', $hook, $args );
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = [] ) {
		$times = [];

		foreach ( $GLOBALS['cron_queue'] ?? [] as $event ) {
			if ( $event['hook'] === $hook && $event['args'] === $args ) {
				$times[] = $event['timestamp'];
			}
		}

		return $times ? min( $times ) : false;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = [], $wp_error = false ) {
		$before = count( $GLOBALS['cron_queue'] ?? [] );

		$GLOBALS['cron_queue'] = array_values(
			array_filter(
				$GLOBALS['cron_queue'] ?? [],
				static fn( array $event ): bool => ! ( $event['hook'] === $hook && $event['args'] === $args )
			)
		);

		return $before - count( $GLOBALS['cron_queue'] );
	}
}

if ( ! function_exists( '_get_cron_array' ) ) {
	/**
	 * Core's shape: timestamp => hook => md5( args ) => { schedule, args }.
	 */
	function _get_cron_array() {
		$crons = [];

		foreach ( $GLOBALS['cron_queue'] ?? [] as $event ) {
			$crons[ $event['timestamp'] ][ $event['hook'] ][ md5( serialize( $event['args'] ) ) ] = [
				'schedule' => $event['recurrence'] ?: false,
				'args'     => $event['args'],
			];
		}

		ksort( $crons );

		return $crons;
	}
}

if ( ! function_exists( 'wp_get_schedules' ) ) {
	function wp_get_schedules() {
		// Core's own four, plus whatever the filter adds.
		$schedules = [
			'hourly'     => [ 'interval' => HOUR_IN_SECONDS, 'display' => 'Once Hourly' ],
			'twicedaily' => [ 'interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Twice Daily' ],
			'daily'      => [ 'interval' => DAY_IN_SECONDS, 'display' => 'Once Daily' ],
			'weekly'     => [ 'interval' => 7 * DAY_IN_SECONDS, 'display' => 'Once Weekly' ],
		];

		foreach ( $GLOBALS['jobs_hooks']['cron_schedules'] ?? [] as $callback ) {
			$schedules = (array) $callback( $schedules );
		}

		return $schedules;
	}
}

/*
 * Scripts, REST and escaping.
 */
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = [] ) {
		$GLOBALS['jobs_routes'][ $namespace . $route ] = $args;

		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = [], $ver = false, $args = [] ) {
		$GLOBALS['jobs_registered'][ $handle ]['script'] = $src;

		return true;
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {
		$GLOBALS['jobs_registered'][ $handle ]['style'] = $src;

		return true;
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $list = 'enqueued' ) {
		return 'registered' === $list ? isset( $GLOBALS['jobs_registered'][ $handle ]['script'] ) : isset( $GLOBALS['jobs_enqueued'][ $handle ] );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, ...$rest ) {
		$GLOBALS['jobs_enqueued'][ $handle ] = true;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, ...$rest ) {
		$GLOBALS['jobs_enqueued_styles'][ $handle ] = true;
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		$GLOBALS['jobs_inline'][ $handle ][] = $data;

		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		return (string) preg_replace( '|(?<=.)/+|', '/', $path );
	}
}

if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ) {
		return 'https://example.test/wp-content' . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '', $scheme = null ) {
		return 'https://example.test/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.test/wp-content/plugins/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

/*
 * Action Scheduler, as a list of pending actions -- unless the run is the
 * one without it, where none of its functions exist, exactly as on a site
 * where it could not load.
 *
 * Arguments are matched the way its database store matches them: exactly,
 * as written, with null meaning any. That includes the part that is easy to
 * get wrong -- unscheduling a hook with an empty list takes out only the
 * actions queued with an empty list.
 */
if ( ! getenv( 'WITHOUT_ACTION_SCHEDULER' ) && ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( $timestamp, $hook, $args = [], $group = '', $unique = false, $priority = 10 ) {
		$GLOBALS['as_actions'][] = [
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
			'timestamp' => (int) $timestamp,
			'interval'  => 0,
		];

		return count( $GLOBALS['as_actions'] );
	}

	function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = [], $group = '', $unique = false, $priority = 10 ) {
		$GLOBALS['as_actions'][] = [
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
			'timestamp' => (int) $timestamp,
			'interval'  => (int) $interval,
		];

		return count( $GLOBALS['as_actions'] );
	}

	function as_test_matches( array $action, $hook, $args, $group ): bool {
		return ( '' === $hook || $action['hook'] === $hook )
			&& ( null === $args || $action['args'] === $args )
			&& ( '' === $group || $action['group'] === $group );
	}

	function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
		foreach ( $GLOBALS['as_actions'] ?? [] as $action ) {
			if ( as_test_matches( $action, $hook, $args, $group ) ) {
				return true;
			}
		}

		return false;
	}

	function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
		$times = [];

		foreach ( $GLOBALS['as_actions'] ?? [] as $action ) {
			if ( as_test_matches( $action, $hook, $args, $group ) ) {
				$times[] = $action['timestamp'];
			}
		}

		return $times ? min( $times ) : false;
	}

	function as_unschedule_all_actions( $hook, $args = [], $group = '' ) {
		// The store's shortcuts: a whole hook, or a whole group.
		if ( empty( $args ) && ( '' === $hook ) !== ( '' === $group ) ) {
			$args = null;
		}

		$GLOBALS['as_actions'] = array_values(
			array_filter(
				$GLOBALS['as_actions'] ?? [],
				static fn( array $action ): bool => ! as_test_matches( $action, $hook, $args, $group )
			)
		);
	}
}

/**
 * Fire `init`, as WordPress does: counted as done from its start.
 *
 * @return void
 */
function jobs_fire_init(): void {
	$GLOBALS['jobs_did']['init'] = 1;
	$GLOBALS['jobs_doing']       = 'init';

	do_action( 'init' );

	$GLOBALS['jobs_doing'] = '';
}

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function jobs_reset(): void {
	$GLOBALS['jobs_hooks']           = [];
	$GLOBALS['jobs_hook_args']       = [];
	$GLOBALS['jobs_did']             = [];
	$GLOBALS['jobs_doing']           = '';
	$GLOBALS['jobs_is_admin']        = false;
	$GLOBALS['jobs_doing_cron']      = false;
	$GLOBALS['jobs_options']         = [];
	$GLOBALS['jobs_autoload']        = [];
	$GLOBALS['jobs_transients']      = [];
	$GLOBALS['jobs_timezone']        = 'UTC';
	$GLOBALS['jobs_user_id']         = 1;
	$GLOBALS['jobs_caps']            = [ 'manage_options' => true ];
	$GLOBALS['jobs_routes']          = [];
	$GLOBALS['jobs_registered']      = [];
	$GLOBALS['jobs_enqueued']        = [];
	$GLOBALS['jobs_enqueued_styles'] = [];
	$GLOBALS['jobs_inline']          = [];
	$GLOBALS['cron_queue']           = [];
	$GLOBALS['as_actions']           = [];
	$GLOBALS['wpdb']                 = new Jobs_Test_Wpdb();

	\SugarCommerce\Dates\Clock::reset();
	\SugarCommerce\Jobs\Utils\Runtime::use_prefix( '' );

	$statics = [
		'SugarCommerce\Jobs\Scheduler'  => [ 'groups' => [] ],
		'SugarCommerce\Jobs\Sync'       => [ 'syncs' => [] ],
		'SugarCommerce\Jobs\SyncRoutes' => [ 'registered' => false ],
	];

	foreach ( $statics as $class => $properties ) {
		foreach ( $properties as $property => $value ) {
			( new ReflectionProperty( $class, $property ) )->setValue( null, $value );
		}
	}
}

jobs_reset();
