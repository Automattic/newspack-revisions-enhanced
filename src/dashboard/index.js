/**
 * Migration Dashboard — Entry point.
 */
import { render, useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Notice as WPNotice } from '@wordpress/components';
import './style.scss';

import MigrationList from './components/MigrationList';
import MigrationDetail from './components/MigrationDetail';

/**
 * Read the "migration" query parameter from the current URL.
 *
 * @return {number|null} The migration term ID, or null.
 */
function getMigrationIdFromUrl() {
	const params = new URLSearchParams( window.location.search );
	const id = parseInt( params.get( 'migration' ), 10 );
	return Number.isFinite( id ) ? id : null;
}

/**
 * Update the URL query parameter without a full page reload.
 *
 * @param {number|null} id The migration term ID to set, or null to clear.
 */
function setMigrationIdInUrl( id ) {
	const url = new URL( window.location.href );
	if ( id ) {
		url.searchParams.set( 'migration', id );
	} else {
		url.searchParams.delete( 'migration' );
	}
	window.history.pushState( { migration: id }, '', url );
}

function App() {
	const [ migrations, setMigrations ] = useState( [] );
	const [ selectedId, setSelectedId ] = useState( getMigrationIdFromUrl );
	const [ detail, setDetail ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ detailLoading, setDetailLoading ] = useState( false );
	const [ rollingBackIds, setRollingBackIds ] = useState( new Set() );
	const [ notice, setNotice ] = useState( null );

	// Sync selectedId → URL.
	const handleSelect = useCallback( ( id ) => {
		setSelectedId( id );
		setMigrationIdInUrl( id );
	}, [] );

	// Handle browser back/forward navigation.
	useEffect( () => {
		const onPopState = () => {
			setSelectedId( getMigrationIdFromUrl() );
		};
		window.addEventListener( 'popstate', onPopState );
		return () => window.removeEventListener( 'popstate', onPopState );
	}, [] );

	// Fetch migrations list.
	useEffect( () => {
		apiFetch( { path: '/nre/v1/migrations' } )
			.then( ( data ) => {
				setMigrations( data );
				setLoading( false );
			} )
			.catch( () => {
				setLoading( false );
			} );
	}, [] );

	// Fetch detail when selection changes.
	useEffect( () => {
		if ( ! selectedId ) {
			setDetail( null );
			return;
		}

		setDetailLoading( true );
		apiFetch( { path: `/nre/v1/migrations/${ selectedId }` } )
			.then( ( data ) => {
				setDetail( data );
				setDetailLoading( false );
			} )
			.catch( () => {
				setDetailLoading( false );
			} );
	}, [ selectedId ] );

	const refreshDetail = useCallback( () => {
		if ( ! selectedId ) return;
		apiFetch( { path: `/nre/v1/migrations/${ selectedId }` } )
			.then( ( data ) => setDetail( data ) )
			.catch( () => {} );
	}, [ selectedId ] );

	const handleRollback = useCallback(
		async ( postId ) => {
			setRollingBackIds( ( prev ) => new Set( [ ...prev, postId ] ) );
			setNotice( null );

			try {
				const result = await apiFetch( {
					path: `/nre/v1/migrations/${ selectedId }/rollback`,
					method: 'POST',
					data: { post_id: postId },
				} );
				setNotice( { type: 'success', message: result.message } );
				refreshDetail();
			} catch ( err ) {
				setNotice( {
					type: 'error',
					message: err.message || 'Rollback failed.',
				} );
			} finally {
				setRollingBackIds( ( prev ) => {
					const next = new Set( prev );
					next.delete( postId );
					return next;
				} );
			}
		},
		[ selectedId, refreshDetail ]
	);

	const handleBulkRollback = useCallback( async () => {
		setNotice( null );
		setRollingBackIds( ( prev ) => new Set( [ ...prev, 'bulk' ] ) );

		try {
			const result = await apiFetch( {
				path: `/nre/v1/migrations/${ selectedId }/rollback-all`,
				method: 'POST',
			} );
			const msg = `Rolled back ${ result.rolled_back } post(s). Skipped ${ result.skipped }. Errors: ${ result.errors.length }.`;
			setNotice( {
				type: result.errors.length ? 'error' : 'success',
				message: msg,
			} );
			refreshDetail();
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err.message || 'Bulk rollback failed.',
			} );
		} finally {
			setRollingBackIds( ( prev ) => {
				const next = new Set( prev );
				next.delete( 'bulk' );
				return next;
			} );
		}
	}, [ selectedId, refreshDetail ] );

	return (
		<div className="nre-dashboard">
			<div className="nre-dashboard__header">
				<div className="nre-dashboard__header-title">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						height="36"
						width="36"
						viewBox="0 0 24 24"
						className="nre-dashboard__header-icon"
					>
						<path
							fillRule="evenodd"
							clipRule="evenodd"
							d="M24 12C24 18.6271 18.6271 24 12 24C5.37213 24 0 18.6271 0 12C0 5.3729 5.3729 0 12 0C18.6271 0 24 5.3729 24 12ZM17.4545 17.4546L6.54545 6.54545V17.4545H8.72727V11.8182L14.3636 17.4546H17.4545ZM11.2727 8.18182H17.4545V6.54545H9.63636L11.2727 8.18182ZM17.4545 11.2727H14.3636L12.7273 9.63636H17.4545V11.2727ZM17.4545 12.7273V14.3636L15.8182 12.7273H17.4545Z"
						/>
					</svg>
					<div>
						<h2>Newspack <span>/ Migrations</span></h2>
					</div>
				</div>
			</div>

			<div className="nre-dashboard__content">
				{ notice && (
					<WPNotice
						status={ notice.type }
						isDismissible
						onDismiss={ () => setNotice( null ) }
					>
						{ notice.message }
					</WPNotice>
				) }

				<div className="nre-dashboard__layout">
					<MigrationList
						migrations={ migrations }
						loading={ loading }
						selectedId={ selectedId }
						onSelect={ handleSelect }
					/>
					<MigrationDetail
						detail={ detail }
						loading={ detailLoading }
						selectedId={ selectedId }
						rollingBackIds={ rollingBackIds }
						onRollback={ handleRollback }
						onBulkRollback={ handleBulkRollback }
					/>
				</div>
			</div>
		</div>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'nre-migration-dashboard' );
	if ( root ) {
		render( <App />, root );
	}
} );
