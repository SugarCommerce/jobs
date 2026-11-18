<?php
/**
 * Inbox tests.
 *
 * @package SugarCommerce\Jobs
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Claim;
use SugarCommerce\Jobs\Inbox;

/**
 * Each incoming event is handled once: a repeat of a finished one is done,
 * a repeat of a running one is busy, and a failed or abandoned one is taken
 * over by the next delivery.
 */
final class InboxTest extends TestCase {

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
			'CREATE TABLE wp_events (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				event_id TEXT NOT NULL UNIQUE,
				type TEXT,
				status TEXT NOT NULL DEFAULT "pending",
				attempts INTEGER NOT NULL DEFAULT 1,
				last_error TEXT,
				date_created INTEGER NOT NULL,
				date_claimed INTEGER,
				date_processed INTEGER
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
	 * The inbox on the test table.
	 *
	 * @return Inbox
	 */
	private function inbox(): Inbox {
		return new Inbox( 'wp_events' );
	}

	/**
	 * A row as it stands.
	 *
	 * @param string $id The event.
	 *
	 * @return array<string, mixed>
	 */
	private function row( string $id ): array {
		return (array) $GLOBALS['wpdb']->get_row( "SELECT * FROM wp_events WHERE event_id = '{$id}'", ARRAY_A );
	}

	/**
	 * The first delivery owns the event, and its own columns are written.
	 */
	public function test_the_first_delivery_owns_it(): void {
		$this->assertSame( Claim::Owned, $this->inbox()->claim( 'evt_1', [ 'type' => 'charge.refunded' ] ) );
		$this->assertTrue( Claim::Owned->owned() );
		$this->assertSame( 'charge.refunded', $this->row( 'evt_1' )['type'] );
		$this->assertSame( 'pending', $this->row( 'evt_1' )['status'] );
	}

	/**
	 * A repeat while the first is running is busy.
	 */
	public function test_a_repeat_while_running_is_busy(): void {
		$this->inbox()->claim( 'evt_1' );

		$claim = $this->inbox()->claim( 'evt_1' );

		$this->assertSame( Claim::Busy, $claim );
		$this->assertFalse( $claim->owned() );
	}

	/**
	 * A repeat of a finished event is done, and nothing runs again.
	 */
	public function test_a_repeat_of_a_finished_event_is_done(): void {
		$inbox = $this->inbox();

		$inbox->claim( 'evt_1' );
		$this->assertSame( 1, $inbox->complete( 'evt_1' ) );

		$this->assertSame( Claim::Done, $inbox->claim( 'evt_1' ) );
		$this->assertSame( self::NOW, (int) $this->row( 'evt_1' )['date_processed'] );
	}

	/**
	 * A failed event is taken over by the sender's retry.
	 */
	public function test_a_failed_event_is_taken_over(): void {
		$inbox = $this->inbox();

		$inbox->claim( 'evt_1' );
		$inbox->fail( 'evt_1', 'database away' );

		$this->assertSame( Claim::Owned, $inbox->claim( 'evt_1' ) );

		$row = $this->row( 'evt_1' );

		$this->assertSame( 2, (int) $row['attempts'] );
		$this->assertNull( $row['last_error'] );
	}

	/**
	 * An abandoned claim is taken over once it is stale, and not before.
	 */
	public function test_an_abandoned_claim_is_taken_over_when_stale(): void {
		$inbox = $this->inbox();

		$inbox->claim( 'evt_1' );

		Clock::advance( Inbox::STALE_AFTER );
		$this->assertSame( Claim::Busy, $inbox->claim( 'evt_1' ) );

		Clock::advance( 1 );
		$this->assertSame( Claim::Owned, $inbox->claim( 'evt_1' ) );
		$this->assertSame( Claim::Busy, $inbox->claim( 'evt_1' ), 'One owner, however many race.' );
	}

	/**
	 * A late delivery cannot mark a finished event failed.
	 */
	public function test_done_is_final(): void {
		$inbox = $this->inbox();

		$inbox->claim( 'evt_1' );
		$inbox->complete( 'evt_1' );

		$this->assertSame( 0, $inbox->fail( 'evt_1', 'late' ) );
		$this->assertSame( 0, $inbox->complete( 'evt_1' ) );
		$this->assertSame( 'done', $this->row( 'evt_1' )['status'] );
	}

	/**
	 * A field name that could not be put in SQL is refused.
	 */
	public function test_a_field_name_must_be_plain(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->inbox()->claim( 'evt_1', [ 'type) VALUES (1); --' => 'x' ] );
	}

	/**
	 * A claim that holds for less than a second is refused.
	 */
	public function test_a_stale_time_must_be_a_second_or_more(): void {
		$this->expectException( InvalidArgumentException::class );

		new Inbox( 'wp_events', 'event_id', 0 );
	}

	/**
	 * The schema has the unique index the guarantee rests on.
	 */
	public function test_the_schema_has_the_unique_key(): void {
		$sql = ( new Inbox( 'wp_stripe_events', 'stripe_event_id' ) )->schema( "\ttype varchar(64) NOT NULL,\n" );

		$this->assertStringContainsString( 'stripe_event_id varchar(191) NOT NULL', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY stripe_event_id (stripe_event_id)', $sql );
		$this->assertStringContainsString( "\ttype varchar(64) NOT NULL,\n", $sql );
	}
}
