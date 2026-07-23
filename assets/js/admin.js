/**
 * Settings → Search: drives the batched index rebuild.
 *
 * Each tick is its own request, so a large site rebuilds without any single
 * request running long enough to be killed by the host.
 */
( function () {
	'use strict';

	var config = window.coywolfSearchAdmin;
	if ( ! config ) {
		return;
	}

	var button = document.getElementById( 'coywolf-search-rebuild' );
	var progress = document.getElementById( 'coywolf-search-progress' );

	if ( ! button || ! progress ) {
		return;
	}

	var track = progress.querySelector( '.coywolf-search-progress-bar' );
	var bar = progress.querySelector( '.coywolf-search-progress-bar span' );
	var text = progress.querySelector( '.coywolf-search-progress-text' );
	var busy = false;

	/**
	 * Mark the button busy without disabling it.
	 *
	 * A disabled element cannot hold focus, so flipping the real disabled
	 * attribute while the button has focus silently dumps a keyboard user's
	 * position to the top of the document. aria-disabled communicates the
	 * state and the busy flag enforces it, and focus stays where it was.
	 */
	function setBusy( value ) {
		busy = value;
		button.setAttribute( 'aria-disabled', value ? 'true' : 'false' );
		button.classList.toggle( 'is-busy', value );
	}

	function post( step ) {
		var body = new FormData();
		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce );
		body.append( 'step', step );

		return window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( 'rebuild-failed' );
				}
				return payload.data;
			} );
	}

	function paintStats( stats ) {
		if ( ! stats ) {
			return;
		}
		Object.keys( stats ).forEach( function ( key ) {
			var node = document.querySelector( '[data-coywolf-stat="' + key + '"]' );
			if ( node ) {
				node.textContent = stats[ key ];
			}
		} );
	}

	function paint( data ) {
		progress.hidden = false;
		bar.style.width = data.percent + '%';
		track.setAttribute( 'aria-valuenow', String( Math.round( data.percent ) ) );
		text.textContent = data.complete
			? config.strings.done
			: config.strings.building + ' ' + data.done + ' / ' + data.total;
		paintStats( data.stats );
	}

	function fail() {
		progress.hidden = false;
		text.textContent = config.strings.failed;
		bar.style.width = '0%';
		track.setAttribute( 'aria-valuenow', '0' );
		setBusy( false );
	}

	function run() {
		post( 'tick' )
			.then( function ( data ) {
				paint( data );
				if ( data.running ) {
					run();
					return;
				}
				setBusy( false );
			} )
			.catch( fail );
	}

	button.addEventListener( 'click', function () {
		if ( busy ) {
			return;
		}
		if ( ! window.confirm( config.strings.confirm ) ) {
			return;
		}
		setBusy( true );
		post( 'start' )
			.then( function ( data ) {
				paint( data );
				run();
			} )
			.catch( fail );
	} );
} )();
