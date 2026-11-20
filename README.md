# Jobs

Background work for a WordPress plugin: deferred, retried, recurring and
local-time jobs on Action Scheduler, incoming webhooks handled once, and long
imports run from a button with a progress bar.

## What it does

Everything a plugin does outside the request that asked for it goes wrong in
the same few ways. A job saved three times runs three times. A failed webhook
is retried twelve times when the other end said no, or never when it was only
down. A sender delivers the same event twice and the handler grants access
twice. An import of four thousand orders times out, or the browser is closed
and it starts again from page one.

This is each of those done properly once, on what WordPress sites already run:
an Action Scheduler engine for deferred, deduplicated, retried, recurring and
local-time jobs, an inbox that claims each incoming event once, and batch
syncs that remember where they stopped. Plain WP-Cron and Action Scheduler's
own queue are used as they are -- see "What it does not do".

## Features

* Push slow work out of a save, queued once however many times it is saved
* Retry what failed on a stated schedule, and give up at once on a refusal
* Run a job at eight in the morning where the site is, through the clocks changing
* Handle an incoming webhook once, however often the sender delivers it
* Keep a run inside a time limit
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

`Retry` is the one answer to "again, and when?" for the scheduler and for
webhooks' sender. No answer, a 408, a 429 and anything in the five hundreds are worth
another go; any other status is a refusal and is given up on at once. A
`Retry-After` the other end sent wins, exactly, up to a day.

Each stated wait is spread a tenth either way (`Retry::JITTER`): a minute is 54
to 66 seconds. Without it every job that failed in one outage comes back in the
same second and knocks the other end over again as it recovers. `jitter: 0`
waits exactly; `'jitter' => 25` in a group's or a job's configuration spreads by
a quarter.

```php
use SugarCommerce\Jobs\Retry;

new Retry( [ 60, 300, 900 ] );                       // three waits, four tries
new Retry( [ 60, 300 ], attempts: 10 );              // the last wait repeats
new Retry( [ 60, 300 ], jitter: 0 );                 // exactly 60, then 300
new Retry( $webhooks_policy );                       // any object with exhausted() and delay()
new Retry( retry_if: fn( WP_Error $e ) => 'invalid_request' !== $e->get_error_code() );
```

## Receiving once

```php
use SugarCommerce\Jobs\Inbox;
use SugarCommerce\Jobs\Models\Claim;

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
use SugarCommerce\Jobs\Budget;

$budget = new Budget( 20 );     // seconds, on a monotonic clock; 0 is unlimited

foreach ( $rows as $row ) {
	if ( $budget->spent() ) {
		break;                  // what is left is still due; the next run starts there
	}
	work( $row );
}
```

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
`Runtime::use_prefix( 'myplugin' )` names them yourself. A prefix that itself
starts `SugarCommerce\` keeps all its segments -- `SugarCommerce\Playground\Vendor\`
gives `sugarcommerce-playground-vendor-sugarcommerce-jobs/v1` -- so a plugin of
the family is never taken for an unprefixed copy.

## What it does not do

WP-Cron, and Action Scheduler on top of it, runs on traffic: a quiet site runs
a "daily" job late, and a punctual one needs a system cron calling
`wp-cron.php`. Nothing here sends a row exactly once.

It keeps no queue, lock or cron wrapper of its own -- those were here once
and went, because WordPress and Action Scheduler already do them:

* a recurring or one-off job: `Scheduler::group()->recurring()` / `later()`,
  or core's `wp_schedule_event()` / `wp_schedule_single_event()`;
* a queue table worked by overlapping workers: Action Scheduler is one
  (claims, retries, logs), and `once()` keeps a job from being queued twice;
* one run at a time: an Action Scheduler action runs once, and a plugin that
  needs a database lock takes MySQL's `GET_LOCK()`.

Its script and stylesheet URLs come from `SugarCommerce\Plugin\Assets`, the
one asset-URL helper of these packages.

## Security

* The sync routes run only for a signed-in user with the sync's capability
  (`manage_options` unless it says otherwise), through the REST API's own
  nonce check, and a sync id is sanitised before it is looked up.
* Each user's fetched page is kept apart from every other user's.
* The sync bar escapes every item name and error message before it shows
  them, so a product name from another service cannot run script.
* `Inbox` refuses a table or column name that is not a plain identifier, and
  every value goes through `$wpdb->prepare()`; one delivery, retried at once,
  is processed once.

## Layout

* `src/Scheduler.php`: a plugin's jobs on Action Scheduler;
  `src/Traits/Scheduler/` holds scheduling and what a failure does.
  `Retry.php`, `LocalJob.php`, `Budget.php` and `Inbox.php` are its parts.
* `src/Models/`: data shapes and nothing that does work. `Claim.php` is
  the inbox's answer to a delivery: run it, already done, or busy.
* `src/Sync.php`: a registered batch sync; `src/Traits/Sync/` holds its screen
  (button, script, words) and its items. `SyncRoutes.php`, `SyncRetry.php` and
  `Progress.php` are the REST routes, retrying failed items and the saved run.
* `src/Utils/Runtime.php`: the names this copy of the library uses.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* Action Scheduler 3.6 or later, for `Scheduler`

## License

GPL-2.0-or-later
