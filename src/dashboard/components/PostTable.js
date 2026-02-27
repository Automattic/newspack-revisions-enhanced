/**
 * PostTable — Table of posts affected by a migration, with server-side search and pagination.
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SearchControl, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import RollbackButton from './RollbackButton';
import DiffModal from './DiffModal';

const PER_PAGE = 50;
const SEARCH_DEBOUNCE_MS = 300;
const TABS = [
	{ value: 'all', label: 'All' },
	{ value: 'created', label: 'Created' },
	{ value: 'updated', label: 'Updated' },
];

export default function PostTable( {
	termId,
	stats,
	rollingBackIds,
	onRollback,
	refreshKey,
	isRollbackRunning,
} ) {
	const [ diffPost, setDiffPost ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ tab, setTab ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ posts, setPosts ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const debounceRef = useRef( null );

	// Debounce search input.
	useEffect( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		debounceRef.current = setTimeout( () => {
			setDebouncedSearch( search );
			setPage( 1 );
		}, SEARCH_DEBOUNCE_MS );
		return () => clearTimeout( debounceRef.current );
	}, [ search ] );

	// Reset to page 1 when tab changes.
	const handleTab = useCallback( ( value ) => {
		setTab( value );
		setPage( 1 );
	}, [] );

	// Fetch posts from server when params change.
	useEffect( () => {
		setLoading( true );

		const params = new URLSearchParams( {
			per_page: PER_PAGE,
			page,
			status: tab,
		} );

		if ( debouncedSearch ) {
			params.set( 'search', debouncedSearch );
		}

		apiFetch( {
			path: `/nre/v1/migrations/${ termId }/posts?${ params.toString() }`,
		} )
			.then( ( data ) => {
				setPosts( data.posts );
				setTotal( data.total );
				setTotalPages( data.total_pages );
				setLoading( false );
			} )
			.catch( () => {
				setPosts( [] );
				setTotal( 0 );
				setTotalPages( 1 );
				setLoading( false );
			} );
	}, [ termId, page, tab, debouncedSearch, refreshKey ] );

	// Tab counts from server-computed stats.
	const counts = {
		all: stats?.total_posts || 0,
		created: stats?.posts_created || 0,
		updated: stats?.posts_updated || 0,
	};

	return (
		<>
			<div className="nre-dashboard__table-search-bar">
				<SearchControl
					value={ search }
					onChange={ setSearch }
					placeholder="Search posts..."
					className="nre-dashboard__table-search"
				/>
			</div>
			<div className="nre-dashboard__table-toolbar">
				<div className="nre-dashboard__tabs">
					{ TABS.map( ( t ) => (
						<button
							key={ t.value }
							className={ `nre-dashboard__tab${
								tab === t.value ? ' is-active' : ''
							}` }
							onClick={ () => handleTab( t.value ) }
						>
							{ t.label }
							<span className="nre-dashboard__tab-count">
								{ counts[ t.value ].toLocaleString() }
							</span>
						</button>
					) ) }
				</div>
				<span className="nre-dashboard__table-count">
					{ total.toLocaleString() } post{ total !== 1 ? 's' : '' }
				</span>
			</div>

			{ loading ? (
				<div className="nre-dashboard__loading">
					<Spinner />
				</div>
			) : (
				<>
					<table className="nre-dashboard__table widefat striped">
						<thead>
							<tr>
								<th>ID</th>
								<th>Status</th>
								<th>Title</th>
								<th>Type</th>
								<th>Revisions</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							{ posts.map( ( post ) => (
								<tr key={ post.post_id }>
									<td>{ post.post_id }</td>
									<td>
										<span
											className={ `nre-dashboard__badge nre-dashboard__badge--${ post.status }` }
										>
											{ post.status }
										</span>
									</td>
									<td>
										{ post.edit_url ? (
											<a
												href={ post.edit_url }
												target="_blank"
												rel="noreferrer"
											>
												{ post.title }
											</a>
										) : (
											post.title
										) }
									</td>
									<td>
										<span className="nre-dashboard__badge nre-dashboard__badge--type">
											{ post.post_type }
										</span>
									</td>
									<td>{ post.revision_count }</td>
									<td>
										<div className="nre-dashboard__actions">
											{ post.view_url && (
												<Button
													variant="secondary"
													size="compact"
													href={ post.view_url }
													target="_blank"
													rel="noreferrer"
												>
													View
												</Button>
											) }
											{ post.revision_url && (
												<Button
													variant="secondary"
													size="compact"
													href={ post.revision_url }
													target="_blank"
													rel="noreferrer"
												>
													Revisions
												</Button>
											) }
											{ post.compare_to && (
												<Button
													variant="secondary"
													size="compact"
													onClick={ () =>
														setDiffPost( post )
													}
												>
													View Changes
												</Button>
											) }
											<RollbackButton
												post={ post }
												isRollingBack={ rollingBackIds.has(
													post.post_id
												) }
												disabled={ isRollbackRunning }
												onRollback={ onRollback }
											/>
										</div>
									</td>
								</tr>
							) ) }
							{ posts.length === 0 && (
								<tr>
									<td
										colSpan="6"
										className="nre-dashboard__empty"
									>
										No posts match your search.
									</td>
								</tr>
							) }
						</tbody>
					</table>

					{ totalPages > 1 && (
						<div className="nre-dashboard__pagination">
							<Button
								variant="secondary"
								size="compact"
								disabled={ page <= 1 }
								onClick={ () => setPage( page - 1 ) }
							>
								&lsaquo; Previous
							</Button>
							<span className="nre-dashboard__pagination-info">
								Page { page } of { totalPages }
							</span>
							<Button
								variant="secondary"
								size="compact"
								disabled={ page >= totalPages }
								onClick={ () => setPage( page + 1 ) }
							>
								Next &rsaquo;
							</Button>
						</div>
					) }
				</>
			) }

			{ diffPost && (
				<DiffModal
					postId={ diffPost.post_id }
					termId={ termId }
					postTitle={ diffPost.title }
					postStatus={ diffPost.status }
					compareFrom={ diffPost.compare_from }
					compareTo={ diffPost.compare_to }
					viewUrl={ diffPost.view_url }
					onClose={ () => setDiffPost( null ) }
				/>
			) }
		</>
	);
}
