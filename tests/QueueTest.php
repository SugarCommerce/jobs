<?php
/**
 * Queue table tests.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Budget;
use SugarCommerce\Jobs\Outcome;
use SugarCommerce\Jobs\Queue;
use SugarCommerce\Jobs\Retry;
use SugarCommerce\Jobs\Table;

/**
 * A row is sent by one worker, a crashed worker's rows come back, and only
 * the claim's holder may say how a row went.
 *
 * On SQLite, so every claim is a real conditional UPDATE and every "who
 * won" is a real affected-row count.
 */
final class QueueTest extends TestCase {

	/**
	 * Now, for every test.
	 */
	private const NOW = 1_800_000_000;

	/**
	 * A fresh table and a stopped clock.
	 */
	protected function setUp(): void {
		jobs_reset();

		$GLOBALS['wpdb']->pdo->exec(
			'CREATE TABLE wp_outbox (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				url TEXT,
				status TEXT NOT NULL DEFAULT "pending",
				attempts INTEGER NOT NULL DEFAULT 0,
				next_attempt_at INTEGER NOT NULL DEFAULT 0,
				claimed_until INTEGER,
				last_error TEXT,
				last_status INTEGER NOT NULL DEFAULT 0,
				date_created INTEGER NOT NULL DEFAULT 0,
				date_sent INTEGER
			)'
		);

		Clock::freeze( self::NOW );
	}

	/**
	 * The clock runs again.
	 */
	protected function tearDown(): void {
		Clock::reset();
	}

	/**
	 * A queue on the test table.
	 *
	 * @param Retry|null $retry How it retries.
	 *
	 * @return Queue
	 */
	private function queue( ?Retry $retry = null ): Queue {
		return new Queue( new Table( 'wp_outbox', status_column: 'last_status' ), $retry );
	}

	/**
	 * A row as it stands.
	 *
	 * @param int $id The row.
	 *
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		return (array) $GLOBALS['wpdb']->get_row( "SELECT * FROM wp_outbox WHERE id = {$id}", ARRAY_A );
	}

	/**
	 * A pushed row is pending, due when asked, and stamped.
	 */
	public function test_a_pushed_row_is_pending_and_due(): void {
		$id = $this->queue()->push( [ 'url' => 'https://example.test/hook' ], 30 );

		$row = $this->row( $id );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 0, (int) $row['attempts'] );
		$this->assertSame( self::NOW + 30, (int) $row['next_attempt_at'] );
		$this->assertSame( self::NOW, (int) $row['date_created'] );
	}

	/**
	 * A caller cannot push a row that is already sending, claimed or failed.
	 */
	public function test_a_push_cannot_set_the_queue_columns(): void {
		$id = $this->queue()->push(
			[
				'url'           => 'x',
				'status'        => 'sending',
				'attempts'      => 9,
				'claimed_until' => self::NOW + 999,
				'last_error'    => 'made up',
			]
		);

		$row = $this->row( $id );

		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 0, (int) $row['attempts'] );
		$this->assertNull( $row['claimed_until'] );
		$this->assertNull( $row['last_error'] );
	}

	/**
	 * A row not yet due is not claimed.
	 */
	public function test_a_row_not_due_is_not_claimed(): void {
		$this->queue()->push( [ 'url' => 'x' ], 60 );

		$this->assertSame( [], $this->queue()->claim() );
	}

	/**
	 * Two workers never get the same row.
	 */
	public function test_two_claims_never_share_a_row(): void {
		$queue = $this->queue();

		$queue->push( [ 'url' => 'a' ] );
		$queue->push( [ 'url' => 'b' ] );

		$first  = $queue->claim( 1 );
		$second = $queue->claim( 10 );
		$third  = $queue->claim( 10 );

		$this->assertCount( 1, $first );
		$this->assertCount( 1, $second );
		$this->assertNotSame( $first[0]['id'], $second[0]['id'] );
		$this->assertSame( [], $third );
		$this->assertSame( 'sending', $this->row( (int) $first[0]['id'] )['status'] );
		$this->assertSame( self::NOW + Queue::LEASE, (int) $first[0]['claimed_until'] );
	}

	/**
	 * Two workers that both read the same due row: the claim is what one of
	 * them wins, and the loser goes without.
	 *
	 * The race, made to happen: a second worker claims everything between
	 * the first worker's read and its UPDATE.
	 */
	public function test_a_row_read_by_two_workers_is_won_by_one(): void {
		$db = new class() extends \Jobs_Test_Wpdb {
			/**
			 * Runs once, just before the first claiming UPDATE.
			 *
			 * @var callable|null
			 */
			public $between = null;

			/**
			 * The query, after the other worker has had its go.
			 *
			 * @param string $sql The query.
			 *
			 * @return int|false
			 */
			public function query( string $sql ) {
				if ( null !== $this->between && str_contains( $sql, "SET status = 'sending'" ) ) {
					$between       = $this->between;
					$this->between = null;
					$between();
				}

				return parent::query( $sql );
			}
		};

		$db->pdo->exec( (string) $GLOBALS['wpdb']->pdo->query( "SELECT sql FROM sqlite_master WHERE name = 'wp_outbox'" )->fetchColumn() );

		$table = new Table( 'wp_outbox', status_column: 'last_status' );
		$first = new Queue( $table, null, $db );
		$other = new Queue( $table, null, $db );

		$first->push( [ 'url' => 'a' ] );

		$theirs      = [];
		$db->between = static function () use ( $other, &$theirs ): void {
			$theirs = $other->claim();
		};

		$mine = $first->claim();

		$this->assertCount( 1, $theirs );
		$this->assertSame( [], $mine, 'Both read it; only one may have it.' );
	}

	/**
	 * Less than one row claims nothing, rather than every row.
	 */
	public function test_a_limit_under_one_claims_nothing(): void {
		$this->queue()->push( [ 'url' => 'a' ] );

		$this->assertSame( [], $this->queue()->claim( 0 ) );
		$this->assertSame( [], $this->queue()->claim( -1 ) );
	}

	/**
	 * A worker that crashed loses its rows when the lease runs out.
	 */
	public function test_a_crashed_workers_row_comes_back_after_the_lease(): void {
		$queue = $this->queue();
		$id    = $queue->push( [ 'url' => 'a' ] );

		$queue->claim();

		Clock::advance( Queue::LEASE );
		$this->assertSame( [], $queue->claim(), 'Taken back the moment the lease ends, not after.' );

		Clock::advance( 1 );
		$again = $queue->claim();

		$this->assertCount( 1, $again );
		$this->assertSame( $id, (int) $again[0]['id'] );
	}

	/**
	 * A worker that ran past its lease cannot overwrite the next worker's
	 * result.
	 */
	public function test_a_late_worker_cannot_settle_a_row_it_lost(): void {
		$queue = $this->queue();
		$id    = $queue->push( [ 'url' => 'a' ] );

		$slow = $queue->claim()[0];

		Clock::advance( Queue::LEASE + 1 );

		$fast = $queue->claim()[0];
		$queue->settle( $fast, Outcome::sent( 200 ) );

		$queue->settle( $slow, Outcome::failed( 'timed out' ) );

		$row = $this->row( $id );

		$this->assertSame( 'sent', $row['status'] );
		$this->assertNull( $row['last_error'] );
		$this->assertSame( 1, (int) $row['attempts'] );
	}

	/**
	 * While the next worker still holds the row, a late worker's failure is
	 * not written over its claim -- or the row would be due again and sent
	 * twice.
	 */
	public function test_a_late_worker_cannot_settle_a_row_still_being_sent(): void {
		$queue = $this->queue();
		$id    = $queue->push( [ 'url' => 'a' ] );

		$slow = $queue->claim()[0];

		Clock::advance( Queue::LEASE + 1 );

		$fast = $queue->claim()[0];

		$queue->settle( $slow, Outcome::failed( 'timed out' ) );

		$row = $this->row( $id );

		$this->assertSame( 'sending', $row['status'] );
		$this->assertSame( (int) $fast['claimed_until'], (int) $row['claimed_until'] );
		$this->assertSame( 0, (int) $row['attempts'] );
		$this->assertSame( [], $queue->claim(), 'Still the second worker\'s.' );
	}

	/**
	 * A sent row is finished, stamped, and keeps the receiver's status.
	 */
	public function test_a_sent_row_is_finished(): void {
		$queue = $this->queue();
		$id    = $queue->push( [ 'url' => 'a' ] );

		$counts = $queue->drain( static fn(): Outcome => Outcome::sent( 204 ) );

		$row = $this->row( $id );

		$this->assertSame(
			[
				'attempted' => 1,
				'sent'      => 1,
				'failed'    => 0,
			],
			$counts
		);
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( self::NOW, (int) $row['date_sent'] );
		$this->assertSame( 204, (int) $row['last_status'] );
		$this->assertNull( $row['claimed_until'] );
	}

	/**
	 * What the other end's status means for the row.
	 *
	 * @param int    $status   The receiver's answer.
	 * @param string $expected What the row becomes after one failure.
	 */
	#[DataProvider( 'statuses' )]
	public function test_a_failure_is_retried_only_when_it_might_pass( int $status, string $expected ): void {
		$queue = $this->queue();
		$id    = $queue->push( [ 'url' => 'a' ] );

		$queue->drain( static fn(): Outcome => Outcome::failed( 'no', $status ) );

		$this->assertSame( $expected, $this->row( $id )['status'] );
	}

	/**
	 * Statuses and what they lead to.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function statuses(): array {
		return [
			'no answer'   => [ 0, 'pending' ],
			'timeout'     => [ 408, 'pending' ],
			'rate limit'  => [ 429, 'pending' ],
			'server'      => [ 503, 'pending' ],
			'bad request' => [ 400, 'failed' ],
			'not found'   => [ 404, 'failed' ],
			'gone'        => [ 410, 'failed' ],
		];
	}

	/**
	 * A retry waits as the schedule says, or as the receiver asked.
	 */
	public function test_a_retry_waits_as_scheduled_or_as_asked(): void {
		$queue = $this->queue( new Retry( [ 60, 300 ] ) );
		$one   = $queue->push( [ 'url' => 'a' ] );
		$two   = $queue->push( [ 'url' => 'b' ] );

		$queue->drain(
			static fn( array $row ): Outcome => 'a' === $row['url']
				? Outcome::failed( 'down', 503 )
				: Outcome::failed( 'slow down', 429, 120 )
		);

		$this->assertSame( self::NOW + 60, (int) $this->row( $one )['next_attempt_at'] );
		$this->assertSame( self::NOW + 120, (int) $this->row( $two )['next_attempt_at'] );
		$this->assertSame( 'down', $this->row( $one )['last_error'] );
	}

	/**
	 * A row gives up when its attempts are spent.
	 */
	public function test_a_row_gives_up_after_its_last_attempt(): void {
		$queue = $this->queue( new Retry( [ 60 ] ) );
		$id    = $queue->push( [ 'url' => 'a' ] );

		$queue->drain( static fn(): Outcome => Outcome::failed( 'down', 503 ) );
		$this->assertSame( 'pending', $this->row( $id )['status'] );

		Clock::advance( 60 );
		$queue->drain( static fn(): Outcome => Outcome::failed( 'down', 503 ) );

		$this->assertSame( 'failed', $this->row( $id )['status'] );
		$this->assertSame( 2, (int) $this->row( $id )['attempts'] );
		$this->assertSame( 1, $queue->failed_count() );
	}

	/**
	 * A dropped row is given up on at once, whatever the schedule.
	 */
	public function test_a_dropped_row_is_not_retried(): void {
		$queue = $this->queue();
		$id    = $queue->push( [ 'url' => 'a' ] );

		$queue->drain( static fn(): Outcome => Outcome::dropped( 'endpoint deleted' ) );

		$this->assertSame( 'failed', $this->row( $id )['status'] );
		$this->assertSame( 'endpoint deleted', $this->row( $id )['last_error'] );
	}

	/**
	 * A sender that throws fails its row, and the run goes on.
	 */
	public function test_a_throwing_sender_fails_one_row_only(): void {
		$queue = $this->queue();
		$bad   = $queue->push( [ 'url' => 'bad' ] );
		$good  = $queue->push( [ 'url' => 'good' ] );

		$counts = $queue->drain(
			static function ( array $row ): Outcome {
				if ( 'bad' === $row['url'] ) {
					throw new RuntimeException( 'surprised' );
				}

				return Outcome::sent();
			}
		);

		$this->assertSame( 1, $counts['sent'] );
		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( 'pending', $this->row( $bad )['status'], 'A throw is a transport failure: retried.' );
		$this->assertSame( 'surprised', $this->row( $bad )['last_error'] );
		$this->assertSame( 'sent', $this->row( $good )['status'] );
	}

	/**
	 * A sender that answers nothing useful has failed.
	 */
	public function test_a_sender_without_an_outcome_has_failed(): void {
		$queue = $this->queue();
		$id    = $queue->push( [ 'url' => 'a' ] );

		$queue->drain( static fn() => true );

		$this->assertSame( 'pending', $this->row( $id )['status'] );
		$this->assertSame( 1, (int) $this->row( $id )['attempts'] );
	}

	/**
	 * A run out of time lets go of what it claimed and did not try.
	 */
	public function test_a_spent_budget_lets_the_rest_go_at_once(): void {
		$queue = $this->queue();
		$tried = $queue->push( [ 'url' => 'a' ] );
		$left  = $queue->push( [ 'url' => 'b' ] );
		$spent = new Budget( 0.000001 );

		usleep( 10 );

		$counts = $queue->drain( static fn(): Outcome => Outcome::sent(), 10, $spent );

		$this->assertSame( 0, $counts['attempted'] );
		$this->assertSame( 'pending', $this->row( $tried )['status'] );
		$this->assertSame( 'pending', $this->row( $left )['status'] );
		$this->assertNull( $this->row( $left )['claimed_until'] );
		$this->assertCount( 2, $queue->claim(), 'Due again now, not after the lease.' );
	}

	/**
	 * Overdue counts rows that should have gone and have not.
	 */
	public function test_overdue_counts_rows_past_due_and_grace(): void {
		$queue = $this->queue();

		$queue->push( [ 'url' => 'a' ] );
		$queue->push( [ 'url' => 'b' ], 3600 );

		$this->assertSame( 0, $queue->overdue( 900 ) );

		Clock::advance( 901 );

		$this->assertSame( 1, $queue->overdue( 900 ) );
	}

	/**
	 * A person can put a failed row back; nobody can reset one in flight.
	 */
	public function test_only_a_failed_row_can_be_retried_by_hand(): void {
		$queue  = $this->queue();
		$failed = $queue->push( [ 'url' => 'a' ] );

		$queue->drain( static fn(): Outcome => Outcome::dropped( 'no' ) );

		$sending = $queue->push( [ 'url' => 'b' ] );
		$queue->claim();

		$this->assertSame( 1, $queue->retry( $failed ) );
		$this->assertSame( 0, $queue->retry( $sending ) );

		$row = $this->row( $failed );

		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 0, (int) $row['attempts'] );
		$this->assertSame( 'sending', $this->row( $sending )['status'] );
	}

	/**
	 * Purging removes finished rows only, and a dry run counts them.
	 */
	public function test_purge_removes_only_finished_rows(): void {
		$queue = $this->queue();
		$sent  = $queue->push( [ 'url' => 'a' ] );
		$fail  = $queue->push( [ 'url' => 'b' ] );

		$queue->drain( static fn( array $row ): Outcome => 'a' === $row['url'] ? Outcome::sent() : Outcome::dropped( 'no' ) );

		$waiting = $queue->push( [ 'url' => 'c' ] );

		Clock::advance( 10 );

		$this->assertSame( 2, $queue->purge( Clock::time(), true ) );
		$this->assertNotSame( [], $this->row( $sent ), 'A dry run removes nothing.' );

		$this->assertSame( 2, $queue->purge( Clock::time() ) );
		$this->assertSame( [], $this->row( $sent ) );
		$this->assertSame( [], $this->row( $fail ) );
		$this->assertSame( 'pending', $this->row( $waiting )['status'] );
	}

	/**
	 * A name that is not a plain identifier is refused before it reaches SQL.
	 *
	 * @param array<string, string|null> $args What the table is given.
	 */
	#[DataProvider( 'bad_tables' )]
	public function test_a_table_refuses_what_it_cannot_use( array $args ): void {
		$this->expectException( InvalidArgumentException::class );

		new Table( ...$args );
	}

	/**
	 * Tables that cannot work.
	 *
	 * @return array<string, array{0: array<string, string|null>}>
	 */
	public static function bad_tables(): array {
		return [
			'injected name'         => [ [ 'name' => 'wp_outbox; DROP TABLE wp_users' ] ],
			'trailing newline'      => [ [ 'name' => "wp_outbox\n" ] ],
			'sent status in use'    => [
				[
					'name'        => 'wp_outbox',
					'sent_status' => 'pending',
				],
			],
			'sent column in use'    => [
				[
					'name'        => 'wp_outbox',
					'sent_column' => 'claimed_until',
				],
			],
			'status column in use'  => [
				[
					'name'          => 'wp_outbox',
					'status_column' => 'attempts',
				],
			],
			'created column in use' => [
				[
					'name'           => 'wp_outbox',
					'created_column' => 'status',
				],
			],
		];
	}

	/**
	 * The schema is one dbDelta() can read, with the queue's columns and
	 * the caller's.
	 */
	public function test_the_schema_is_for_dbdelta(): void {
		$sql = ( new Table( 'wp_mail', 'delivered', 'date_delivered', 'http_status' ) )->schema( "\tpayload longtext NOT NULL,\n", 'DEFAULT CHARSET=utf8mb4' );

		$this->assertStringStartsWith( "CREATE TABLE wp_mail (\n", $sql );
		$this->assertStringContainsString( "\tpayload longtext NOT NULL,\n", $sql );
		$this->assertStringContainsString( 'claimed_until bigint(20) unsigned DEFAULT NULL', $sql );
		$this->assertStringContainsString( 'http_status smallint(5)', $sql );
		$this->assertStringContainsString( 'date_delivered bigint(20)', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql, 'dbDelta() wants two spaces.' );
		$this->assertStringContainsString( 'KEY due (status,next_attempt_at)', $sql );
		$this->assertStringEndsWith( ') DEFAULT CHARSET=utf8mb4;', $sql );
	}
}
