/**
 * Coywolf Search — instant suggestions.
 *
 * Attaches to any search field on the page and shows matches as the visitor
 * types: instantly from a small in-browser index of titles, then merged with
 * full-text results from the server a moment later.
 *
 * Progressive enhancement throughout. Without JavaScript, or before anything
 * here has loaded, the form is an ordinary search form that submits to /?s=.
 */
( function () {
	'use strict';

	var config = window.coywolfSearchTypeahead;
	if ( ! config || ! window.fetch ) {
		return;
	}

	var LIBRARY = { promise: null, MiniSearch: null };
	var CORPUS = { promise: null, index: null, byId: {} };
	var counter = 0;

	/* ------------------------------------------------------------------ */
	/* Lazy loading                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch the search library, once, on first interaction.
	 *
	 * Deferred this far because most visitors never touch the search box, and
	 * the ones who do can afford the moment it takes while they are still
	 * typing their first word.
	 */
	function loadLibrary() {
		if ( LIBRARY.promise ) {
			return LIBRARY.promise;
		}

		LIBRARY.promise = new Promise( function ( resolve, reject ) {
			// The library sets a window global of its own; stash whatever is
			// already there and put it back, so loading here can't disturb
			// another copy on the page.
			var previous = window.MiniSearch;
			var script = document.createElement( 'script' );

			script.src = config.libraryUrl;
			script.async = true;
			script.onload = function () {
				LIBRARY.MiniSearch = window.MiniSearch;
				window.MiniSearch = previous;
				resolve( LIBRARY.MiniSearch );
			};
			script.onerror = function () {
				reject( new Error( 'library' ) );
			};

			document.head.appendChild( script );
		} );

		return LIBRARY.promise;
	}

	/**
	 * Build the in-browser title index, once.
	 */
	function loadCorpus() {
		if ( CORPUS.promise ) {
			return CORPUS.promise;
		}

		CORPUS.promise = Promise.all( [
			loadLibrary(),
			window.fetch( config.docsUrl, { credentials: 'omit' } ).then( function ( r ) {
				return r.json();
			} ),
		] ).then( function ( parts ) {
			var MiniSearch = parts[ 0 ];
			var docs = ( parts[ 1 ] && parts[ 1 ].docs ) || [];

			var index = new MiniSearch( {
				fields: [ 'title' ],
				storeFields: [ 'title', 'url', 'type' ],
				searchOptions: { prefix: true, fuzzy: 0.2 },
			} );

			var rows = docs.map( function ( d ) {
				var row = { id: d[ 0 ], title: d[ 1 ], url: d[ 2 ], type: d[ 3 ] };
				CORPUS.byId[ row.id ] = row;
				return row;
			} );

			index.addAll( rows );
			CORPUS.index = index;
			return index;
		} );

		return CORPUS.promise;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function escapeHtml( text ) {
		return String( text ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function escapeRegExp( text ) {
		return String( text ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	/**
	 * Wrap the words the visitor typed, after escaping the text.
	 */
	function highlight( text, query ) {
		var escaped = escapeHtml( text );
		var words = String( query )
			.split( /[\s\p{P}]+/u )
			.filter( function ( w ) {
				return w.length >= 2;
			} )
			.sort( function ( a, b ) {
				return b.length - a.length;
			} )
			.slice( 0, 10 );

		if ( ! words.length ) {
			return escaped;
		}

		var pattern = new RegExp( '(' + words.map( escapeRegExp ).join( '|' ) + ')', 'gi' );
		return escaped.replace( pattern, '<mark>$1</mark>' );
	}

	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			var args = arguments;
			var self = this;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( self, args );
			}, wait );
		};
	}

	/* ------------------------------------------------------------------ */
	/* One search field                                                    */
	/* ------------------------------------------------------------------ */

	function attach( input ) {
		if ( input.dataset.coywolfSearchBound ) {
			return;
		}
		input.dataset.coywolfSearchBound = '1';

		var form = input.form;
		var id = 'coywolf-search-list-' + ++counter;
		var items = [];
		var active = -1;
		var activeId = null;
		var userMoved = false;
		var open = false;
		var requestToken = 0;

		/* --- markup ---------------------------------------------------- */

		// Nothing is inserted around the input. Themes lay search forms out
		// with flexbox and direct-child selectors, and a wrapper here would
		// quietly break those; anchoring to the document instead means this
		// works against markup it has never seen.
		var clear = document.createElement( 'button' );
		clear.type = 'button';
		clear.className = 'coywolf-search-clear';
		clear.setAttribute( 'aria-label', config.strings.clear );
		clear.innerHTML = '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><path d="M1 1L15 15M15 1L1 15" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>';
		clear.hidden = true;
		document.body.appendChild( clear );

		var list = document.createElement( 'div' );
		list.className = 'coywolf-search-suggestions';
		list.id = id;
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;
		document.body.appendChild( list );

		var status = document.createElement( 'span' );
		status.className = 'coywolf-search-status';
		status.setAttribute( 'aria-live', 'polite' );
		document.body.appendChild( status );

		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', id );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'autocomplete', 'off' );

		/**
		 * Whether the field is actually on screen.
		 *
		 * Themes routinely ship a second, hidden search field — a header
		 * search that expands on click, for instance — and a dropdown anchored
		 * to something invisible would land in the wrong place.
		 */
		function visible() {
			if ( ! input.offsetParent && 'fixed' !== window.getComputedStyle( input ).position ) {
				return false;
			}
			var rect = input.getBoundingClientRect();
			return rect.width > 0 && rect.height > 0;
		}

		/**
		 * Put the dropdown and the clear button where the input is.
		 */
		function position() {
			var rect = input.getBoundingClientRect();
			var top = rect.bottom + window.scrollY + 4;
			var left = rect.left + window.scrollX;

			list.style.top = top + 'px';
			list.style.left = left + 'px';
			list.style.width = rect.width + 'px';

			var size = Math.min( 26, Math.max( 16, rect.height * 0.5 ) );
			clear.style.width = size + 'px';
			clear.style.height = size + 'px';
			clear.style.top = rect.top + window.scrollY + ( rect.height - size ) / 2 + 'px';
			clear.style.left = rect.right + window.scrollX - size - 8 + 'px';
		}

		/* --- rendering -------------------------------------------------- */

		/**
		 * Which option is selected when a fresh set of results appears.
		 *
		 * The first one, so the visitor can see what Enter is about to open
		 * rather than having to guess — unless the site has turned that
		 * behaviour off, in which case nothing is preselected and Enter runs
		 * an ordinary search.
		 */
		function defaultActive() {
			return config.enterOpensTop && items.length ? 0 : -1;
		}

		/**
		 * Choose the selected option for a new render.
		 *
		 * Results arrive twice — instantly from the local index, then again
		 * when the server's answer merges in — and someone who arrowed down in
		 * between should not have their choice yanked back to the top.
		 */
		function restoreActive() {
			if ( userMoved && null !== activeId ) {
				for ( var i = 0; i < items.length; i++ ) {
					if ( items[ i ].id === activeId ) {
						active = i;
						return;
					}
				}
			}
			active = defaultActive();
			activeId = active >= 0 ? items[ active ].id : null;
		}

		function render( query ) {
			if ( ! items.length || ! visible() ) {
				close();
				return;
			}

			position();

			list.innerHTML = items
				.map( function ( item, i ) {
					var body = item.snippet
						? '<span class="coywolf-search-snippet">' + item.snippet + '</span>'
						: '';
					// The content type is off by default: on most sites every
					// suggestion is a Post, so the label is a column of
					// identical words rather than information.
					var type =
						config.showType && item.type
							? '<span class="coywolf-search-type">' + escapeHtml( item.type ) + '</span>'
							: '';
					return (
						'<div class="coywolf-search-option" role="option" id="' +
						id +
						'-' +
						i +
						'" aria-selected="' +
						( i === active ? 'true' : 'false' ) +
						'" data-index="' +
						i +
						'">' +
						'<span class="coywolf-search-title">' +
						highlight( item.title, query ) +
						'</span>' +
						type +
						body +
						'</div>'
					);
				} )
				.join( '' );

			list.hidden = false;
			open = true;
			input.setAttribute( 'aria-expanded', 'true' );
			status.textContent = config.strings.available.replace( '%d', items.length );
			paintActive();
		}

		function paintActive() {
			var nodes = list.querySelectorAll( '.coywolf-search-option' );
			for ( var i = 0; i < nodes.length; i++ ) {
				var selected = i === active;
				nodes[ i ].setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				nodes[ i ].classList.toggle( 'is-active', selected );
			}

			if ( active >= 0 && nodes[ active ] ) {
				input.setAttribute( 'aria-activedescendant', nodes[ active ].id );
				if ( nodes[ active ].scrollIntoView ) {
					nodes[ active ].scrollIntoView( { block: 'nearest' } );
				}
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function close() {
			open = false;
			active = -1;
			activeId = null;
			userMoved = false;
			list.hidden = true;
			list.innerHTML = '';
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}

		/**
		 * Empty the field and return to the starting state — what both Escape
		 * and the clear button do.
		 */
		function reset() {
			input.value = '';
			items = [];
			close();
			showClear( false );
			status.textContent = '';
			requestToken++;
		}

		function go( item ) {
			if ( item && item.url ) {
				window.location.href = item.url;
			}
		}

		/* --- searching --------------------------------------------------- */

		/**
		 * Merge server results over local ones.
		 *
		 * The server searched the full text and ranked properly, so its
		 * results lead; local title matches it hasn't returned fill the rest
		 * of the list rather than disappearing as the request lands.
		 */
		function merge( server, local ) {
			var seen = {};
			var out = [];

			server.forEach( function ( item ) {
				seen[ item.id ] = true;
				out.push( item );
			} );

			local.forEach( function ( item ) {
				if ( ! seen[ item.id ] && out.length < config.max ) {
					out.push( item );
				}
			} );

			return out.slice( 0, config.max );
		}

		function localMatches( query ) {
			if ( ! CORPUS.index ) {
				return [];
			}
			return CORPUS.index
				.search( query, { prefix: true, fuzzy: 0.2 } )
				.slice( 0, config.max )
				.map( function ( hit ) {
					var doc = CORPUS.byId[ hit.id ] || {};
					return { id: hit.id, title: doc.title, url: doc.url, type: doc.type, snippet: '' };
				} );
		}

		var lastLocal = [];

		var askServer = debounce( function ( query ) {
			var token = ++requestToken;
			var url =
				config.searchUrl +
				( config.searchUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
				'q=' +
				encodeURIComponent( query ) +
				'&per_page=' +
				config.max;

			window
				.fetch( url, { credentials: 'omit' } )
				.then( function ( r ) {
					return r.ok ? r.json() : { results: [] };
				} )
				.then( function ( data ) {
					// A slower earlier request must not overwrite a newer one.
					if ( token !== requestToken || input.value.trim() !== query ) {
						return;
					}
					var server = ( data.results || [] ).map( function ( r ) {
						return {
							id: r.id,
							title: r.title,
							url: r.url,
							type: r.type_label,
							snippet: r.snippet,
						};
					} );
					items = merge( server, lastLocal );
					restoreActive();
					render( query );
				} )
				.catch( function () {
					// The local suggestions already on screen stand.
				} );
		}, config.debounce );

		function showClear( show ) {
			clear.hidden = ! show;
			if ( show ) {
				position();
			}
		}

		function onInput() {
			var query = input.value.trim();

			// The query changed, so whatever was selected under the old one is
			// no longer meaningful.
			userMoved = false;
			activeId = null;

			showClear( '' !== input.value && visible() );

			if ( query.length < config.minChars ) {
				items = [];
				lastLocal = [];
				close();
				return;
			}

			loadCorpus()
				.then( function () {
					if ( input.value.trim() !== query ) {
						return;
					}
					lastLocal = localMatches( query );
					// Show what we have straight away; the server's answer
					// merges in when it arrives.
					items = merge( [], lastLocal );
					restoreActive();
					render( query );
				} )
				.catch( function () {
					// No local index: the server results are still coming.
				} );

			askServer( query );
		}

		/* --- keyboard ---------------------------------------------------- */

		function move( delta ) {
			if ( ! open || ! items.length ) {
				return;
			}
			if ( active === -1 ) {
				active = delta > 0 ? 0 : items.length - 1;
			} else {
				active = ( active + delta + items.length ) % items.length;
			}
			userMoved = true;
			activeId = items[ active ].id;
			paintActive();
		}

		input.addEventListener( 'keydown', function ( event ) {
			switch ( event.key ) {
				case 'ArrowDown':
					if ( open && items.length ) {
						event.preventDefault();
						move( 1 );
					}
					break;

				case 'ArrowUp':
					if ( open && items.length ) {
						event.preventDefault();
						move( -1 );
					}
					break;

				case 'Enter':
					// Enter opens whatever is highlighted. The first suggestion
					// highlights itself as soon as results appear, so what Enter
					// will do is always visible before it is pressed. With the
					// preselect behaviour turned off nothing is highlighted
					// until the visitor arrows to it, and Enter otherwise
					// submits the form for a full search.
					if ( open && items.length && active >= 0 ) {
						event.preventDefault();
						go( items[ active ] );
					}
					break;

				case 'Escape':
					if ( open || input.value ) {
						event.preventDefault();
						event.stopPropagation();
						reset();
					}
					break;

				case 'Tab':
					close();
					break;
			}
		} );

		input.addEventListener( 'input', onInput );

		input.addEventListener( 'focus', function () {
			// Warm the index while the visitor types their first character.
			loadCorpus().catch( function () {} );
			showClear( '' !== input.value && visible() );
			if ( items.length ) {
				render( input.value.trim() );
			}
		} );

		clear.addEventListener( 'click', function () {
			reset();
			input.focus();
		} );

		list.addEventListener( 'mousedown', function ( event ) {
			// Beat the blur that would otherwise close the list first.
			var option = event.target.closest( '.coywolf-search-option' );
			if ( ! option ) {
				return;
			}
			event.preventDefault();
			go( items[ parseInt( option.dataset.index, 10 ) ] );
		} );

		list.addEventListener( 'mousemove', function ( event ) {
			var option = event.target.closest( '.coywolf-search-option' );
			if ( ! option ) {
				return;
			}
			var index = parseInt( option.dataset.index, 10 );
			if ( index !== active ) {
				active = index;
				userMoved = true;
				activeId = items[ active ].id;
				paintActive();
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target !== input && ! list.contains( event.target ) && event.target !== clear ) {
				close();
			}
		} );

		// The dropdown is anchored to the document, so it has to follow the
		// field when the page moves under it.
		window.addEventListener(
			'scroll',
			function () {
				if ( open ) {
					position();
				}
			},
			{ passive: true }
		);

		window.addEventListener( 'resize', function () {
			if ( open ) {
				position();
			}
		} );

		if ( form ) {
			form.addEventListener( 'submit', function () {
				close();
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	function boot() {
		// Every search form WordPress or a theme can produce posts an "s"
		// field, whether it came from get_search_form(), the search block, or
		// hand-written markup — so that is what we look for rather than any
		// particular wrapper class.
		var inputs = document.querySelectorAll( 'form input[name="s"]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			attach( inputs[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
