/**
 * Batch sync: the button, the loop and the progress bar.
 *
 * Two phases, so the bar moves visibly:
 *
 * 1. FETCH -- /fetch asks the sync's data callback for a page and keeps it on
 *    the server. It answers how many items it holds.
 * 2. PROCESS -- /process is called until the page is done, a few items at a
 *    time, each call answering what every item came to. The bar and the name
 *    of the item being worked on move after every call. Then the next page is
 *    fetched, if there is one.
 *
 * Every button with the class `sugar-sync-trigger` starts the sync its
 * `data-sync-id` names. Or from code:
 *
 *     window.SugarSync.start( 'stripe_prices' );
 *     document.addEventListener( 'sugar-sync:complete', ( e ) => e.detail.id );
 *
 * Events, on document, each with `detail.id`: `sugar-sync:complete` (and
 * `detail.totals`), `sugar-sync:cancelled`, `sugar-sync:error` (and
 * `detail.message`).
 *
 * @package SugarCommerce\Jobs
 * @since   1.0.0
 */
( function () {
	'use strict';

	if ( ! window.SugarSyncConfig ) {
		return;
	}

	/*
	 * Bind once, however many copies of this file load.
	 *
	 * Strauss gives each prefixed copy its own script handle, so two plugins
	 * bundling this library both enqueue it and the browser runs it twice.
	 * Without this every button gets two click handlers and every sync runs
	 * twice against the same cursor. The copy that runs first serves every
	 * plugin's syncs: they all add to the one SugarSyncConfig.
	 */
	if ( window.SugarSync && window.SugarSync.__bound ) {
		return;
	}

	const syncs = window.SugarSyncConfig.syncs;

	/** @type {Object<string, boolean>} */
	const cancelled = {};

	/**
	 * A sync's configuration, with its endpoint and strings.
	 *
	 * @param {string} id The sync.
	 * @return {Object} Its configuration.
	 */
	function configFor( id ) {
		return syncs[ id ] || { i18n: {} };
	}

	/**
	 * Escape text for HTML.
	 *
	 * @param {string} text The text.
	 * @return {string} Escaped.
	 */
	function esc( text ) {
		const el = document.createElement( 'div' );
		el.textContent = text == null ? '' : String( text );
		return el.innerHTML;
	}

	/**
	 * Tell anyone listening.
	 *
	 * @param {string} name   The event, after `sugar-sync:`.
	 * @param {Object} detail What happened.
	 */
	function announce( name, detail ) {
		document.dispatchEvent( new CustomEvent( 'sugar-sync:' + name, { detail } ) );
	}

	/**
	 * Every trigger button for a sync.
	 *
	 * @param {string} id The sync.
	 * @return {Element[]} The buttons.
	 */
	function triggers( id ) {
		return Array.from( document.querySelectorAll( '.sugar-sync-trigger' ) ).filter(
			( button ) => button.dataset.syncId === id
		);
	}

	/**
	 * POST to one of the sync's endpoints.
	 *
	 * @param {string} id       The sync.
	 * @param {string} endpoint `fetch` or `process`.
	 * @param {Object} body     What to send.
	 * @return {Promise<Object>} The answer, or a rejection with a message.
	 */
	function call( id, endpoint, body ) {
		const config = configFor( id );

		return fetch( config.restUrl + endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.restNonce,
			},
			body: JSON.stringify( body ),
		} ).then( ( response ) =>
			response
				.json()
				.catch( () => ( {} ) )
				.then( ( json ) => {
					if ( ! response.ok ) {
						throw new Error( json.message || config.i18n.syncFailed );
					}
					return json;
				} )
		);
	}

	const api = {
		/**
		 * Set once this file has bound its handlers; read by the guard at
		 * the top.
		 */
		__bound: true,

		/**
		 * Start a sync.
		 *
		 * @param {string} id        The sync.
		 * @param {string} container CSS selector the bar goes in front of.
		 *                           The sync's own when left out.
		 */
		start( id, container ) {
			const config = syncs[ id ];

			if ( ! config ) {
				// eslint-disable-next-line no-console
				console.error( '[SugarSync] Unknown sync:', id );
				return;
			}

			const selector = container || config.container;
			const target = document.querySelector( selector );

			if ( ! target ) {
				// eslint-disable-next-line no-console
				console.error( '[SugarSync] Container not found:', selector );
				return;
			}

			cancelled[ id ] = false;

			// updating-message is core's own busy state for a button: the
			// spinning glyph, its colour and its reduced-motion handling are
			// already written, on the class core puts on a plugin's Update
			// button.
			triggers( id ).forEach( ( button ) => {
				button.classList.add( 'is-syncing', 'updating-message' );
				button.disabled = true;
			} );

			let bar = target.previousElementSibling;

			if ( ! bar || ! bar.classList.contains( 'sugar-sync-bar' ) || bar.dataset.syncId !== id ) {
				bar = this._createBar( id );
				target.before( bar );
			}

			this._resetBar( bar, config );

			this._fetchPage( bar, id, '', {
				created: 0,
				updated: 0,
				skipped: 0,
				failed: 0,
				processed: 0,
				total: null,
				fetched: 0,
				errors: [],
			} );
		},

		/**
		 * Cancel a running sync, after the call in flight.
		 *
		 * @param {string} id The sync.
		 */
		cancel( id ) {
			cancelled[ id ] = true;
		},

		/**
		 * Phase 1: fetch a page.
		 *
		 * @param {Element} bar    The bar.
		 * @param {string}  id     The sync.
		 * @param {string}  cursor Where the page starts.
		 * @param {Object}  totals Running totals.
		 * @private
		 */
		_fetchPage( bar, id, cursor, totals ) {
			const config = configFor( id );

			if ( cancelled[ id ] ) {
				this._showCancelled( bar, id );
				return;
			}

			this._title( bar, config.title + ' — ' + config.i18n.fetching );

			call( id, 'fetch', { sync_id: id, cursor } )
				.then( ( response ) => {
					if ( cancelled[ id ] ) {
						this._showCancelled( bar, id );
						return;
					}

					if ( response.total ) {
						totals.total = response.total;
					}

					this._title( bar, config.title + ' — ' + config.i18n.syncing );

					if ( ! response.fetched ) {
						this._showComplete( bar, id, totals );
						return;
					}

					// Without a total from the other end, what has been
					// fetched so far is the best size there is.
					totals.fetched += response.fetched;

					this._processChunk( bar, id, totals, {
						has_more: response.has_more,
						cursor: response.cursor,
					} );
				} )
				.catch( ( error ) => this._showError( bar, id, error.message || config.i18n.syncFailed ) );
		},

		/**
		 * Phase 2: process the page, a few items a call.
		 *
		 * @param {Element} bar    The bar.
		 * @param {string}  id     The sync.
		 * @param {Object}  totals Running totals.
		 * @param {Object}  page   Where the next page is.
		 * @private
		 */
		_processChunk( bar, id, totals, page ) {
			const config = configFor( id );

			if ( cancelled[ id ] ) {
				this._showCancelled( bar, id );
				return;
			}

			call( id, 'process', { sync_id: id } )
				.then( ( response ) => {
					if ( cancelled[ id ] ) {
						this._showCancelled( bar, id );
						return;
					}

					[ 'created', 'updated', 'skipped', 'failed', 'processed' ].forEach( ( key ) => {
						totals[ key ] += response[ key ] || 0;
					} );

					( response.items || [] ).forEach( ( item ) => {
						if ( 'failed' === item.status && item.error ) {
							totals.errors.push( item.name + ': ' + item.error );
						}
					} );

					this._updateProgress( bar, id, totals, response.items || [] );

					if ( ! response.page_done ) {
						this._processChunk( bar, id, totals, page );
					} else if ( page.has_more ) {
						this._fetchPage( bar, id, page.cursor, totals );
					} else {
						this._showComplete( bar, id, totals );
					}
				} )
				.catch( ( error ) => this._showError( bar, id, error.message || config.i18n.syncFailed ) );
		},

		/**
		 * Build the bar.
		 *
		 * @param {string} id The sync.
		 * @return {Element} The bar.
		 * @private
		 */
		_createBar( id ) {
			const config = configFor( id );
			const bar = document.createElement( 'div' );

			bar.className = 'sugar-sync-bar';
			bar.dataset.syncId = id;
			bar.setAttribute( 'role', 'status' );
			bar.innerHTML = [
				'<div class="sugar-sync-header">',
				'  <div class="sugar-sync-title"></div>',
				'  <button type="button" class="sugar-sync-cancel">' + esc( config.i18n.cancel ) + '</button>',
				'</div>',
				'<div class="sugar-sync-progress">',
				'  <div class="sugar-sync-track"><div class="sugar-sync-fill"></div></div>',
				'</div>',
				'<div class="sugar-sync-status">',
				'  <span class="sugar-sync-count"></span>',
				'  <span class="sugar-sync-current"></span>',
				'</div>',
				'<div class="sugar-sync-result"></div>',
			].join( '\n' );

			bar.addEventListener( 'click', ( event ) => {
				if ( event.target.closest( '.sugar-sync-cancel' ) ) {
					// eslint-disable-next-line no-alert
					if ( window.confirm( config.i18n.confirmCancel ) ) {
						this.cancel( id );
					}
				} else if ( event.target.closest( '.sugar-sync-dismiss' ) ) {
					bar.classList.remove( 'is-active' );
				}
			} );

			return bar;
		},

		/**
		 * Put the bar back to the start of a run.
		 *
		 * @param {Element} bar    The bar.
		 * @param {Object}  config The sync's configuration.
		 * @private
		 */
		_resetBar( bar, config ) {
			bar.classList.remove( 'is-complete', 'is-error' );
			bar.classList.add( 'is-active' );
			this._title( bar, config.title + ' — ' + config.i18n.fetching );
			bar.querySelector( '.sugar-sync-fill' ).style.width = '0%';
			bar.querySelector( '.sugar-sync-count' ).textContent = '';
			bar.querySelector( '.sugar-sync-current' ).textContent = '';
			bar.querySelector( '.sugar-sync-result' ).innerHTML = '';
		},

		/**
		 * Set the bar's title.
		 *
		 * @param {Element} bar   The bar.
		 * @param {string}  title The title.
		 * @private
		 */
		_title( bar, title ) {
			bar.querySelector( '.sugar-sync-title' ).textContent = title;
		},

		/**
		 * Move the bar on, and name the last item processed.
		 *
		 * @param {Element} bar    The bar.
		 * @param {string}  id     The sync.
		 * @param {Object}  totals Running totals.
		 * @param {Array}   items  What this call processed.
		 * @private
		 */
		_updateProgress( bar, id, totals, items ) {
			const config = configFor( id );
			const size = totals.total || totals.fetched || null;
			const pct = size ? Math.min( 100, Math.round( ( totals.processed / size ) * 100 ) ) : 0;

			bar.querySelector( '.sugar-sync-fill' ).style.width = pct + '%';
			bar.querySelector( '.sugar-sync-count' ).textContent = size
				? totals.processed + ' ' + config.i18n.of + ' ' + size
				: totals.processed + ' ' + config.i18n.items;

			const last = items.length ? items[ items.length - 1 ].name : '';

			if ( last ) {
				bar.querySelector( '.sugar-sync-current' ).textContent = last;
			}
		},

		/**
		 * The run finished: say what came of it.
		 *
		 * @param {Element} bar    The bar.
		 * @param {string}  id     The sync.
		 * @param {Object}  totals Final totals.
		 * @private
		 */
		_showComplete( bar, id, totals ) {
			const config = configFor( id );
			const i18n = config.i18n;
			const total = totals.created + totals.updated + totals.skipped + totals.failed;
			const parts = [];

			bar.classList.add( 'is-complete' );
			bar.querySelector( '.sugar-sync-fill' ).style.width = '100%';
			this._title( bar, config.title + ' — ' + i18n.complete );

			[ 'created', 'updated', 'skipped' ].forEach( ( key ) => {
				if ( totals[ key ] > 0 ) {
					parts.push( totals[ key ] + ' ' + i18n[ key ] );
				}
			} );

			let html = '<span>' + esc( total + ' ' + i18n.items + ' ' + i18n.synced + ( parts.length ? ' — ' + parts.join( ', ' ) : '' ) + '.' ) + '</span>';

			if ( totals.failed > 0 ) {
				const shown = totals.errors.slice( 0, 3 ).join( '; ' ) + ( totals.errors.length > 3 ? '...' : '' );

				html += '<div class="sugar-sync-error-summary">' + esc( totals.failed + ' ' + i18n.failed + '.' + ( shown ? ' ' + shown : '' ) ) + '</div>';
			}

			if ( false !== config.reload_on_complete ) {
				html += '<span class="sugar-sync-reloading">' + esc( i18n.reloading ) + '</span>';
			}

			bar.querySelector( '.sugar-sync-result' ).innerHTML = html;

			this._enableTrigger( id );

			announce( 'complete', { id, totals } );

			// After a moment, so the summary can be read.
			if ( false !== config.reload_on_complete ) {
				window.setTimeout( () => window.location.reload(), 1500 );
			}
		},

		/**
		 * The run was cancelled.
		 *
		 * @param {Element} bar The bar.
		 * @param {string}  id  The sync.
		 * @private
		 */
		_showCancelled( bar, id ) {
			this._finish( bar, id, configFor( id ).i18n.cancelled );

			announce( 'cancelled', { id } );
		},

		/**
		 * The run failed.
		 *
		 * @param {Element} bar     The bar.
		 * @param {string}  id      The sync.
		 * @param {string}  message Why.
		 * @private
		 */
		_showError( bar, id, message ) {
			this._finish( bar, id, message );

			announce( 'error', { id, message } );
		},

		/**
		 * Stop the bar with a message and a Dismiss button.
		 *
		 * @param {Element} bar     The bar.
		 * @param {string}  id      The sync.
		 * @param {string}  message What to say.
		 * @private
		 */
		_finish( bar, id, message ) {
			const config = configFor( id );

			bar.classList.add( 'is-error' );
			this._title( bar, config.title );
			bar.querySelector( '.sugar-sync-result' ).innerHTML =
				'<span>' + esc( message ) + '</span>' +
				'<button type="button" class="sugar-sync-dismiss">' + esc( config.i18n.dismiss ) + '</button>';

			this._enableTrigger( id );
		},

		/**
		 * Let the buttons be pressed again.
		 *
		 * @param {string} id The sync.
		 * @private
		 */
		_enableTrigger( id ) {
			triggers( id ).forEach( ( button ) => {
				button.classList.remove( 'is-syncing', 'updating-message' );
				button.disabled = false;
			} );
		},
	};

	window.SugarSync = api;

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest ? event.target.closest( '.sugar-sync-trigger' ) : null;

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		if ( button.classList.contains( 'is-syncing' ) || ! button.dataset.syncId ) {
			return;
		}

		api.start( button.dataset.syncId, button.dataset.container );
	} );
}() );
