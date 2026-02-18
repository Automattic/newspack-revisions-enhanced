/**
 * PostTable — Table of posts affected by a migration, with search and pagination.
 */
import { useState, useMemo } from '@wordpress/element';
import { Button, SearchControl } from '@wordpress/components';
import RollbackButton from './RollbackButton';
import DiffModal from './DiffModal';

const PER_PAGE = 50;

export default function PostTable( { posts, termId, rollingBackIds, onRollback } ) {
	const [ diffPost, setDiffPost ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );

	// Filter posts by search query (title, status, post type).
	const filtered = useMemo( () => {
		if ( ! posts ) {
			return [];
		}
		if ( ! search.trim() ) {
			return posts;
		}
		const q = search.toLowerCase().trim();
		return posts.filter(
			( p ) =>
				p.title.toLowerCase().includes( q ) ||
				p.status.toLowerCase().includes( q ) ||
				p.post_type.toLowerCase().includes( q ) ||
				String( p.post_id ).includes( q )
		);
	}, [ posts, search ] );

	// Reset to page 1 when search changes.
	const totalPages = Math.max( 1, Math.ceil( filtered.length / PER_PAGE ) );
	const safePage = Math.min( page, totalPages );
	const paged = filtered.slice( ( safePage - 1 ) * PER_PAGE, safePage * PER_PAGE );

	// Reset page when search changes.
	const handleSearch = ( value ) => {
		setSearch( value );
		setPage( 1 );
	};

	if ( ! posts || ! posts.length ) {
		return <p className="nre-dashboard__empty">No posts found.</p>;
	}

	return (
		<>
			<div className="nre-dashboard__table-toolbar">
				<SearchControl
					value={ search }
					onChange={ handleSearch }
					placeholder="Search posts..."
					className="nre-dashboard__table-search"
				/>
				<span className="nre-dashboard__table-count">
					{ filtered.length === posts.length
						? `${ posts.length } posts`
						: `${ filtered.length } of ${ posts.length } posts` }
				</span>
			</div>

			<table className="nre-dashboard__table widefat striped">
				<thead>
					<tr>
						<th>Title</th>
						<th>Status</th>
						<th>Type</th>
						<th>Revisions</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					{ paged.map( ( post ) => (
						<tr key={ post.post_id }>
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
								<span
									className={ `nre-dashboard__badge nre-dashboard__badge--${ post.status }` }
								>
									{ post.status }
								</span>
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
										<a
											href={ post.revision_url }
											target="_blank"
											rel="noreferrer"
											className="nre-dashboard__action-link"
										>
											Revisions
										</a>
									) }
									{ post.compare_from && post.compare_to && (
										<Button
											variant="tertiary"
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
							<td colSpan="5" className="nre-dashboard__empty">
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
					onClose={ () => setDiffPost( null ) }
				/>
			) }
		</>
	);
}
