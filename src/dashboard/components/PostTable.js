/**
 * PostTable — Table of posts affected by a migration, with search and pagination.
 */
import { useState, useMemo } from '@wordpress/element';
import { Button, SearchControl } from '@wordpress/components';
import RollbackButton from './RollbackButton';
import DiffModal from './DiffModal';

const PER_PAGE = 50;
const TABS = [
	{ value: 'all', label: 'All' },
	{ value: 'created', label: 'Created' },
	{ value: 'updated', label: 'Updated' },
];

export default function PostTable( { posts, termId, rollingBackIds, onRollback } ) {
	const [ diffPost, setDiffPost ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ tab, setTab ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );

	// Count per status for tab badges.
	const counts = useMemo( () => {
		if ( ! posts ) {
			return { all: 0, created: 0, updated: 0 };
		}
		return {
			all: posts.length,
			created: posts.filter( ( p ) => p.status === 'created' ).length,
			updated: posts.filter( ( p ) => p.status === 'updated' ).length,
		};
	}, [ posts ] );

	// Filter posts by tab and search query.
	const filtered = useMemo( () => {
		if ( ! posts ) {
			return [];
		}
		let list = posts;
		if ( tab !== 'all' ) {
			list = list.filter( ( p ) => p.status === tab );
		}
		if ( search.trim() ) {
			const q = search.toLowerCase().trim();
			list = list.filter(
				( p ) =>
					p.title.toLowerCase().includes( q ) ||
					p.status.toLowerCase().includes( q ) ||
					p.post_type.toLowerCase().includes( q ) ||
					String( p.post_id ).includes( q )
			);
		}
		return list;
	}, [ posts, tab, search ] );

	// Reset to page 1 when search changes.
	const totalPages = Math.max( 1, Math.ceil( filtered.length / PER_PAGE ) );
	const safePage = Math.min( page, totalPages );
	const paged = filtered.slice( ( safePage - 1 ) * PER_PAGE, safePage * PER_PAGE );

	// Reset page when search or tab changes.
	const handleSearch = ( value ) => {
		setSearch( value );
		setPage( 1 );
	};

	const handleTab = ( value ) => {
		setTab( value );
		setPage( 1 );
	};

	if ( ! posts || ! posts.length ) {
		return <p className="nre-dashboard__empty">No posts found.</p>;
	}

	return (
		<>
			<div className="nre-dashboard__table-search-bar">
				<SearchControl
					value={ search }
					onChange={ handleSearch }
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
								{ counts[ t.value ] }
							</span>
						</button>
					) ) }
				</div>
				<span className="nre-dashboard__table-count">
					{ filtered.length === posts.length
						? `${ posts.length } posts`
						: `${ filtered.length } of ${ posts.length } posts` }
				</span>
			</div>

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
					{ paged.map( ( post ) => (
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
										onRollback={ onRollback }
									/>
								</div>
							</td>
						</tr>
					) ) }
					{ paged.length === 0 && (
						<tr>
							<td colSpan="6" className="nre-dashboard__empty">
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
						disabled={ safePage <= 1 }
						onClick={ () => setPage( safePage - 1 ) }
					>
						&lsaquo; Previous
					</Button>
					<span className="nre-dashboard__pagination-info">
						Page { safePage } of { totalPages }
					</span>
					<Button
						variant="secondary"
						size="compact"
						disabled={ safePage >= totalPages }
						onClick={ () => setPage( safePage + 1 ) }
					>
						Next &rsaquo;
					</Button>
				</div>
			) }

			{ diffPost && (
				<DiffModal
					postId={ diffPost.post_id }
					termId={ termId }
					postTitle={ diffPost.title }
					postStatus={ diffPost.status }
					onClose={ () => setDiffPost( null ) }
				/>
			) }
		</>
	);
}
