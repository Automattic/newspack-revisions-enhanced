/**
 * NRE Revisions — Migration sidebar and badge injection.
 *
 * Loaded with 'revisions' as dependency, so wp.revisions classes are already defined.
 */
( function( $, wp ) {
	'use strict';

	// Override view templates to use our custom versions (with migration badge
	// and custom-fields section grouping). Runs immediately — before document.ready.
	var _nreMetaTmpl = null;
	wp.revisions.view.Meta.prototype.template = function() {
		if ( ! _nreMetaTmpl ) {
			_nreMetaTmpl = document.getElementById( 'tmpl-nre-revisions-meta' )
				? wp.template( 'nre-revisions-meta' )
				: wp.template( 'revisions-meta' );
		}
		return _nreMetaTmpl.apply( this, arguments );
	};

	var _nreDiffTmpl = null;
	wp.revisions.view.Diff.prototype.template = function() {
		if ( ! _nreDiffTmpl ) {
			_nreDiffTmpl = document.getElementById( 'tmpl-nre-revisions-diff' )
				? wp.template( 'nre-revisions-diff' )
				: wp.template( 'revisions-diff' );
		}
		return _nreDiffTmpl.apply( this, arguments );
	};

	// On document.ready (fires after revisions.init() since our script loads after).
	$( function() {
		if ( typeof _nreMigrationSettings === 'undefined' || ! _nreMigrationSettings.length ) {
			return;
		}

		var frame = wp.revisions.view.frame;
		if ( ! frame ) {
			return;
		}

		var model      = frame.model,
			migrations = _nreMigrationSettings,
			$diffFrame = $( '.revisions-diff-frame' ),
			activeMigration = null,
			navIndex   = 0;

		if ( ! $diffFrame.length ) {
			return;
		}

		// Wrap the diff frame in a flex container with our sidebar.
		var $wrapper = $( '<div class="nre-diff-wrapper"></div>' );
		var $sidebar = $( '<div class="nre-migration-sidebar"></div>' );

		$sidebar.append( '<div class="nre-sidebar-header">Migrations</div>' );

		// "All" item.
		$sidebar.append(
			'<button type="button" class="nre-sidebar-item active" data-migration="all">' +
				'<span class="nre-sidebar-item-name">All revisions</span>' +
			'</button>'
		);

		// Migration items.
		$.each( migrations, function( i, migration ) {
			$sidebar.append(
				'<button type="button" class="nre-sidebar-item" data-migration="' + i + '">' +
					'<span class="nre-sidebar-item-name">' + migration.name + '</span>' +
					'<span class="nre-sidebar-item-meta">' +
						migration.revisionIds.length + ' revision' + ( migration.revisionIds.length !== 1 ? 's' : '' ) +
					'</span>' +
					'<span class="nre-sidebar-item-date">' + migration.date + '</span>' +
				'</button>'
			);
		} );

		// Navigation controls (hidden by default).
		var $nav = $(
			'<div class="nre-sidebar-nav" style="display:none;">' +
				'<div class="nre-sidebar-nav-row">' +
					'<button type="button" class="button button-small nre-nav-prev">&lsaquo;</button>' +
					'<span class="nre-nav-position"></span>' +
					'<button type="button" class="button button-small nre-nav-next">&rsaquo;</button>' +
				'</div>' +
			'</div>'
		);
		$sidebar.append( $nav );

		// Insert the wrapper: sidebar + diff frame.
		$diffFrame.before( $wrapper );
		$wrapper.append( $sidebar );
		$wrapper.append( $diffFrame );

		/**
		 * Get the sequential pairs for a migration's revisions.
		 *
		 * For "creation" migrations where the first revision is the very
		 * first overall revision, from is null — there is no predecessor.
		 */
		function getMigrationPairs( migration ) {
			var ids   = migration.revisionIds,
				pairs = [],
				revisions = model.revisions;

			$.each( ids, function( i, id ) {
				var rev = revisions.get( id );
				if ( ! rev ) {
					return;
				}

				var fromRev;
				if ( i === 0 ) {
					var idx = revisions.indexOf( rev );
					if ( idx > 0 ) {
						fromRev = revisions.at( idx - 1 );
					} else {
						fromRev = null;
					}
				} else {
					fromRev = revisions.get( ids[ i - 1 ] );
				}

				pairs.push( { from: fromRev, to: rev } );
			} );

			return pairs;
		}

		function jumpToPair( pair ) {
			if ( pair.from === null ) {
				// Creation revision — compare from 0 (nothing) to show all content as additions.
				model.set( { compareTwoMode: true, from: 0, to: pair.to } );
			} else {
				model.set( { compareTwoMode: true, from: pair.from, to: pair.to } );
			}
		}

		function updateNav() {
			if ( ! activeMigration ) {
				$nav.hide();
				return;
			}

			var pairs = getMigrationPairs( activeMigration );
			$nav.show();
			$nav.find( '.nre-nav-position' ).text( ( navIndex + 1 ) + ' / ' + pairs.length );
			$nav.find( '.nre-nav-prev' ).prop( 'disabled', navIndex <= 0 );
			$nav.find( '.nre-nav-next' ).prop( 'disabled', navIndex >= pairs.length - 1 );
		}

		// Sidebar item click handler.
		$sidebar.on( 'click', '.nre-sidebar-item', function() {
			var $item = $( this ),
				migrationIndex = $item.data( 'migration' );

			$sidebar.find( '.nre-sidebar-item' ).removeClass( 'active' );
			$item.addClass( 'active' );

			if ( migrationIndex === 'all' ) {
				activeMigration = null;
				navIndex = 0;
				$nav.hide();
				return;
			}

			var migration = migrations[ migrationIndex ];
			activeMigration = migration;
			navIndex = 0;

			var pairs = getMigrationPairs( migration );
			if ( pairs.length > 0 ) {
				if ( pairs.length === 1 ) {
					// Single revision — jump directly to it.
					jumpToPair( pairs[0] );
				} else if ( pairs[0].from === null ) {
					// Creation migration with multiple revisions —
					// show the first (creation) pair in slider mode.
					jumpToPair( pairs[0] );
				} else {
					// Update migration — show full range in compareTwoMode.
					jumpToPair( {
						from: pairs[0].from,
						to: pairs[ pairs.length - 1 ].to
					} );
				}
				navIndex = 0;
				updateNav();
			}
		} );

		// Prev/Next click handlers.
		$nav.on( 'click', '.nre-nav-prev', function() {
			if ( ! activeMigration || navIndex <= 0 ) {
				return;
			}
			navIndex--;
			jumpToPair( getMigrationPairs( activeMigration )[ navIndex ] );
			updateNav();
		} );

		$nav.on( 'click', '.nre-nav-next', function() {
			if ( ! activeMigration ) {
				return;
			}
			var pairs = getMigrationPairs( activeMigration );
			if ( navIndex >= pairs.length - 1 ) {
				return;
			}
			navIndex++;
			jumpToPair( pairs[ navIndex ] );
			updateNav();
		} );
	} );

} )( jQuery, wp );
