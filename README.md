# Jobs

Background work for a WordPress plugin: scheduled jobs, deferred and retried
jobs, a queue table, and long imports run from a button with a progress bar.

## What it does

Everything a plugin does outside the request that asked for it goes wrong in
the same few ways. A cron event outlives the plugin and fires into nothing for
ever. A job saved three times runs three times. A failed webhook is retried
twelve times when the other end said no, or never when it was only down. Two
cron runs overlap and send the same email twice, or one dies halfway and
strands what it had taken. An import of four thousand orders times out, or
the browser is closed and it starts again from page one.

This is each of those done properly once: declared cron jobs that are cleaned
up on uninstall, an Action Scheduler engine for deferred, deduplicated,
retried, recurring and local-time jobs, a queue table that workers claim under
a lease, and batch syncs that remember where they stopped.

## Features

* Declare a cron job once, and take every one away at uninstall
* Push slow work out of a save, queued once however many times it is saved
* Retry what failed on a stated schedule, and give up at once on a refusal
* Run a job at eight in the morning where the site is, through the clocks changing
* Drain a queue table from overlapping workers without sending a row twice
* Handle an incoming webhook once, however often the sender delivers it
* Keep a run inside a time limit, and let one failing step not stop the rest
* Import thousands of records from a button, carrying on after a closed browser

## Installation

```bash
composer require sugarcommerce/jobs
```

## Quick start

Tell a payment provider about a product after the save, not during it:

```php
use SugarCommerce\Jobs\Scheduler;

$queue = Scheduler::group( 'myplugin' );

// Every request, where the plugin boots.
$queue->job( 'myplugin_push_product', function ( $product_id, int $attempt ) use ( $queue ) {
	$result = Stripe::push_product( $product_id );

	if ( is_wp_error( $result ) ) {
		$queue->failed( 'myplugin_push_product', $product_id, $attempt, $result );
	}
} );

// In the save handler. Saved three times in a minute: one job.
$queue->defer( 'myplugin_push_product', $product_id );
```

## Cron jobs

```php
use SugarCommerce\Jobs\Cron;

add_action( 'init', function () {
	Cron::schedule( 'every_five_minutes', 5 * MINUTE_IN_SECONDS, __( 'Every five minutes', 'myplugin' ) );

	Cron::job( 'myplugin_sync', [
		'schedule' => 'every_five_minutes',   // or hourly, daily...; empty for once
		'callback' => [ Sync::class, 'run' ],
		'args'     => [],
	] );
} );

// uninstall.php: nothing is declared there, so name the prefix.
Cron::unschedule_all( 'myplugin_' );
```

The callback is attached every request and the event is queued only once. A
job naming a recurrence nobody added is refused, rather than run once and
never again. `Cron::next_run()`, `Cron::scheduled()` and `Cron::unschedule()`
take the hook and its arguments.

## Action Scheduler

Bundle `woocommerce/action-scheduler` unprefixed and require it from your main
plugin file as the file is read -- from `plugins_loaded` at 10 it is already
too late, and nothing says so:

```php
Scheduler::load( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' );
```

One group per plugin, named once and never renamed: it keeps your actions
apart and is what uninstall clears.

```php
$queue = Scheduler::group( 'myplugin', [
	'retry'    => [ 60, 300, 900, 3600, 21600 ],   // waits after each failure
	'gave_up'  => fn( $error, $id, $attempt, $context, $hook ) => myplugin_log( $error ),
] );

$queue->later( 'myplugin_deliver', [ $endpoint, $body ], 60 );   // every call its own
$queue->once( 'myplugin_catalogue_changed', [], 60 );           // ten changes, one action
$queue->recurring( 'myplugin_clear_out', DAY_IN_SECONDS, [ Cleanup::class, 'sweep' ] );

$queue->at( 'myplugin_summary', [ Summary::class, 'send' ], [
	'time'   => '08:00',                                        // the site's clock
	'every'  => [ 'weekly' => 'week:mon', 'monthly' => 'month:1' ],
	'wanted' => fn(): string => Summary::frequency(),           // '' is off
] );

$queue->unschedule_all();                                       // uninstall
```

A deferred job's arguments are `[ $id, $attempt ]`; `$id` may be a string.
Without Action Scheduler a deferred job runs where it stands and is never
retried in the same request; `later()`, `once()`, recurring and local-time
jobs do nothing and return false.

## Retrying

`Retry` is the one answer to "again, and when?" for the scheduler and the
queue. No answer, a 408, a 429 and anything in the five hundreds are worth
another go; any other status is a refusal and is given up on at once. A
`Retry-After` the other end sent wins, up to a day.

```php
use SugarCommerce\Jobs\Retry;

new Retry( [ 60, 300, 900 ] );                       // three waits, four tries
new Retry( [ 60, 300 ], attempts: 10 );              // the last wait repeats
new Retry( $webhooks_policy );                       // any object with exhausted() and delay()
new Retry( retry_if: fn( WP_Error $e ) => 'invalid_request' !== $e->get_error_code() );
```

## A queue table

Rows go in a table first and on the wire second. Each is claimed with one
conditional UPDATE, so of two overlapping workers exactly one gets it; the
claim is a lease, so a worker that dies leaves nothing stranded; and a worker
that outlived its lease cannot write over the next one's result.

```php
use SugarCommerce\Jobs\{Budget, Outcome, Queue, Table};

$table  = new Table( $wpdb->prefix . 'myplugin_outbox' );
dbDelta( $table->schema( "\turl text NOT NULL,\n\tbody longtext NOT NULL,\n", $wpdb->get_charset_collate() ) );

$outbox = new Queue( $table );
$outbox->push( [ 'url' => $url, 'body' => wp_json_encode( $event ) ] );

$queue->recurring( 'myplugin_drain', MINUTE_IN_SECONDS, fn() => $outbox->drain( function ( array $row ): Outcome {
	$response = wp_remote_post( $row['url'], [ 'body' => $row['body'] ] );
	$status   = (int) wp_remote_retrieve_response_code( $response );

	return $status >= 200 && $status < 300
		? Outcome::sent( $status )
		: Outcome::failed( is_wp_error( $response ) ? $response->get_error_message() : "HTTP {$status}", $status );
}, 50, new Budget( 20 ) ) );
```

**Pass the status** to `Outcome::failed()`: without one it is a transport
failure, which is always retried. `Outcome::dropped()` gives up now.
`overdue()` and `failed_count()` are for a health check, `retry( $id )` puts a
failed row back, and `purge( $before, dry: true )` counts finished rows before
removing them. It is at least once, not exactly once: give the receiver an id
to deduplicate on.

## Receiving once

```php
use SugarCommerce\Jobs\{Claim, Inbox};

$inbox = new Inbox( $wpdb->prefix . 'myplugin_events' );   // ->schema() for dbDelta()
$claim = $inbox->claim( $event->id, [ 'type' => $event->type ] );

if ( ! $claim->owned() ) {
	return new WP_REST_Response( null, Claim::Busy === $claim ? 409 : 200 );
}
```

Then `complete( $id )`, or `fail( $id, $message )` so the sender's retry takes
it over. The claim is the insert, so two concurrent deliveries get one owner,
and an abandoned claim can be taken over after five minutes.

## Bounding a run

```php
use SugarCommerce\Jobs\{Budget, Lock, Pass, Steps};

Lock::with( 'myplugin_nightly', function () {          // one run at a time, held in the database
	$steps = Steps::logging( 'myplugin nightly' );      // a step that throws is logged, the next runs
	$pass  = new Pass( due: time(), limit: 500, budget: new Budget( 25 ) );

	$steps->run( 'mail', fn() => $mail->drain( $send, 50, new Budget( 10 ) ), [] );
	$steps->run( 'links', fn() => Links::sweep( $pass ), 0 );
} );
```

A lock whose run died is taken over when its lease ends (five minutes, or the
`$ttl` given), and only its holder can let it go. `Budget` is a monotonic
time limit; `Pass` carries one row count across a sweep of many customers.

## Batch syncs with a progress bar

Two callbacks -- one fetches a page, one handles an item -- and the button,
the REST loop, the progress bar above the list table and the error summary
are done:

```php
use SugarCommerce\Jobs\{Sync, SyncRetry};

add_action( 'init', function () {
	Sync::register( 'shop_orders', [
		'hook_suffix'      => 'toplevel_page_myplugin',
		'title'            => __( 'Orders', 'myplugin' ),
		'button_label'     => __( 'Import Orders', 'myplugin' ),
		'data_callback'    => [ Orders::class, 'page' ],    // ( string $cursor ): [ items, has_more, cursor, total ]
		'process_callback' => [ Orders::class, 'import' ],  // ( $item ): 'created'|'updated'|'skipped'|WP_Error
		'resume'           => true,                         // cursor, counts and failures kept as it goes
	] );

	SyncRetry::register( 'shop_retry', [ 'shop_orders' ], [
		'hook_suffix'  => 'toplevel_page_myplugin',
		'button_label' => __( 'Try Failures Again', 'myplugin' ),
	] );
} );

Sync::get( 'shop_orders' )?->render_button();
```

Register on `init`: the work is over REST, where `admin_init` never runs. A
resuming sync writes its cursor before reading a page, so a closed browser
reads that page again and no other -- the process callback must find what it
made last time. Once finished it does nothing until `reset_progress()`;
`progress()->get()` and `->failures()` are for its screen. A failed item is
kept and replayed, or fetched fresh by `retry_callback`.

From script: `SugarSync.start( id )`, and `sugar-sync:complete`,
`sugar-sync:cancelled` and `sugar-sync:error` on `document`, each with
`detail.id`. Two plugins bundling this library each get their own REST
namespace, script handle and option names, derived from the Strauss prefix;
`Runtime::use_prefix( 'myplugin' )` names them yourself.

## What it does not do

WP-Cron, and Action Scheduler on top of it, runs on traffic: a quiet site runs
a "daily" job late, and a punctual one needs a system cron calling
`wp-cron.php`. Nothing here sends a row exactly once.

From the retired SaaS `outbox` package the queue, inbox, lock, budget, pass
and steps are all here, on `$wpdb` instead of SQLite; the file lock is a row
in the options table, because a WordPress site may be several servers. Left
out: its SQLite connection class and the `$now` arguments, which the shared
`SugarCommerce\Dates\Clock` replaces.

## Moving from arraypress/wp-*

This library is `wp-register-cron` and `wp-inline-sync` in one. The global
helpers are gone -- a global function is the one thing Strauss cannot prefix:

| Was | Now |
|---|---|
| `register_cron_schedule( $name, $interval, $display )` | `Cron::schedule( $name, $interval, $display )` |
| `register_cron_job( $hook, $config )` | `Cron::job( $hook, $config )` |
| `unregister_cron_job( $hook, $args )` | `Cron::unschedule( $hook, $args )` |
| `unregister_cron_jobs()` | `Cron::unschedule_all()` -- or `( 'myplugin_' )` to clear by prefix |
| `cron_job_next_run( $hook, $args )` | `Cron::next_run( $hook, $args )` |
| `register_sync( $id, $config )`, `new Sync( $id, $config )` | `Sync::register( $id, $config )` |
| `get_sync( $id )`, `has_sync( $id )` | `Sync::get( $id )`, `Sync::has( $id )` |
| `render_sync_button( $id, $args )` | `Sync::get( $id )?->render_button( $args )` |
| `get_sync_button( $id, $args )` | `Sync::get( $id )?->button( $args ) ?? ''` |

Classes, under `SugarCommerce\Jobs`:

| Was | Now |
|---|---|
| `RegisterCron\Jobs::add / remove / remove_all / next_run / all` | `Cron::job / unschedule / unschedule_all / next_run / jobs` |
| `RegisterCron\Schedules::add / apply / exists / all` | `Cron::schedule / apply_schedules / has_schedule / schedules` |
| `RegisterCron\Scheduler`, `RegisterCron\LocalJob` | `Scheduler`, `LocalJob` -- same methods |
| `Scheduler::transient_failure( $error )` | `Retry::transient( $error )` -- a 408 is now worth retrying too |
| `InlineSync\Registry::instance()->get / has / all / unregister` | `Sync::get / has / all / unregister` |
| `InlineSync\Registry::instance()->get_for_screen( $hook )` | `Sync::on_screen( $hook )` |
| `$sync->get_id() / get_config() / get_button()` | `$sync->id() / config() / button()` |
| `$sync->get_data_callback()` and the other three | `$sync->data_callback()` and the other three |
| `InlineSync\RestApi`, `RestApi::CHUNK_SIZE` | `SyncRoutes`; the `chunk_size` setting, default 5 |
| `InlineSync\Retry::register / page / process` | `SyncRetry::register / page / process` |
| `InlineSync\Progress`, `InlineSync\Utils\Runtime` | `Progress`, `Utils\Runtime` |

In the browser, and stored:

| Was | Now |
|---|---|
| `window.InlineSync.start( id )` / `.cancel( id )` | `window.SugarSync.start( id )` / `.cancel( id )` -- no jQuery needed |
| jQuery `inline-sync:complete` `( event, id, totals )` | `sugar-sync:complete` on `document`: `event.detail.id`, `.totals` |
| `inline-sync:cancelled`, `inline-sync:error` `( event, id, message )` | `sugar-sync:cancelled`, `sugar-sync:error`: `event.detail.id`, `.message` |
| CSS `.inline-sync-*`, `window.InlineSyncConfig` | `.sugar-sync-*`, `window.SugarSyncConfig` |
| progress option `{prefix}_inline_sync_progress_{id}` | `{prefix}_sugarcommerce_jobs_sync_progress_{id}` -- pass `progress_option` to keep a run in progress |
| REST `{prefix}-inline-sync/v1`, text domain `arraypress` | `{prefix}-sugarcommerce-jobs/v1`, `sugarcommerce` |

A button's `container` argument now does what it says; it used to be ignored.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* Action Scheduler 3.6 or later, for `Scheduler`

## License

GPL-2.0-or-later
