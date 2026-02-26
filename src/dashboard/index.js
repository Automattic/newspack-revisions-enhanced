/**
 * Migration Dashboard — Entry point.
 */
import {
	render,
	useState,
	useEffect,
	useCallback,
	useRef,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Notice as WPNotice } from '@wordpress/components';
import './style.scss';

import MigrationList from './components/MigrationList';
import MigrationDetail from './components/MigrationDetail';

const ROLLBACK_POLL_INTERVAL = 2000;

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
	const [ postsRefreshKey, setPostsRefreshKey ] = useState( 0 );
	const [ rollbackStatus, setRollbackStatus ] = useState( null );
	const pollRef = useRef( null );

	// Sync selectedId -> URL.
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
	const fetchMigrations = useCallback( () => {
		apiFetch( { path: '/nre/v1/migrations' } )
			.then( ( data ) => {
				setMigrations( data );
				setLoading( false );
			} )
			.catch( () => {
				setLoading( false );
			} );
	}, [] );

	useEffect( () => {
		fetchMigrations();
	}, [ fetchMigrations ] );

	// Fetch detail (stats only) when selection changes.
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
		if ( ! selectedId ) {
			return;
		}
		apiFetch( { path: `/nre/v1/migrations/${ selectedId }` } )
			.then( ( data ) => setDetail( data ) )
			.catch( () => {} );
		setPostsRefreshKey( ( k ) => k + 1 );
	}, [ selectedId ] );

	// Stop polling helper.
	const stopPolling = useCallback( () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}
	}, [] );

	// Start polling for rollback status.
	const startPolling = useCallback(
		( termId ) => {
			stopPolling();
			const poll = () => {
				apiFetch( {
					path: `/nre/v1/migrations/${ termId }/rollback-status`,
				} )
					.then( ( data ) => {
						setRollbackStatus( data );
						if ( data.status !== 'running' ) {
							stopPolling();
							if ( data.status === 'complete' ) {
								const msg = `Rolled back ${ data.rolled_back.toLocaleString() } post(s). Skipped ${ data.skipped.toLocaleString() }. Errors: ${
									data.errors.length
								}.`;
								setNotice( {
									type: data.errors.length
										? 'error'
										: 'success',
									message: msg,
								} );
								refreshDetail();
								fetchMigrations();
							} else if ( data.status === 'cancelled' ) {
								setNotice( {
									type: 'warning',
									message: `Rollback cancelled. Rolled back ${ data.rolled_back.toLocaleString() } of ${ data.total.toLocaleString() } posts before cancellation.`,
								} );
								refreshDetail();
								fetchMigrations();
							} else if ( data.status === 'failed' ) {
								setNotice( {
									type: 'error',
									message: 'Rollback failed.',
								} );
							}
						}
					} )
					.catch( () => {
						stopPolling();
					} );
			};
			poll();
			pollRef.current = setInterval( poll, ROLLBACK_POLL_INTERVAL );
		},
		[ stopPolling, refreshDetail, fetchMigrations ]
	);

	// Check rollback status when selectedId changes (resume progress display).
	useEffect( () => {
		if ( ! selectedId ) {
			setRollbackStatus( null );
			stopPolling();
			return;
		}

		apiFetch( {
			path: `/nre/v1/migrations/${ selectedId }/rollback-status`,
		} )
			.then( ( data ) => {
				setRollbackStatus( data );
				if ( data.status === 'running' ) {
					startPolling( selectedId );
				}
			} )
			.catch( () => {
				setRollbackStatus( null );
			} );

		return () => stopPolling();
	}, [ selectedId, startPolling, stopPolling ] );

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
				fetchMigrations();
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
		[ selectedId, refreshDetail, fetchMigrations ]
	);

	const handleBulkRollback = useCallback( async () => {
		setNotice( null );

		try {
			const result = await apiFetch( {
				path: `/nre/v1/migrations/${ selectedId }/rollback-all`,
				method: 'POST',
			} );

			if ( result.status === 'running' ) {
				setRollbackStatus( {
					status: 'running',
					total: result.total,
					processed: 0,
					rolled_back: 0,
					skipped: 0,
					errors: [],
				} );
				startPolling( selectedId );
			}
		} catch ( err ) {
			if ( err.data?.status === 409 ) {
				startPolling( selectedId );
			} else {
				setNotice( {
					type: 'error',
					message: err.message || 'Bulk rollback failed.',
				} );
			}
		}
	}, [ selectedId, startPolling ] );

	const handleCancelRollback = useCallback( async () => {
		try {
			await apiFetch( {
				path: `/nre/v1/migrations/${ selectedId }/rollback-cancel`,
				method: 'POST',
			} );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err.message || 'Failed to cancel rollback.',
			} );
		}
	}, [ selectedId ] );

	const isRollbackRunning =
		rollbackStatus && rollbackStatus.status === 'running';

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
						<h2>
							Newspack <span>/ Migrations</span>
						</h2>
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
						onCancelRollback={ handleCancelRollback }
						rollbackStatus={ rollbackStatus }
						isRollbackRunning={ isRollbackRunning }
						postsRefreshKey={ postsRefreshKey }
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
