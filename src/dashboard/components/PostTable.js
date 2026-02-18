/**
 * PostTable — Table of posts affected by a migration.
 */
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import RollbackButton from './RollbackButton';
import DiffModal from './DiffModal';

export default function PostTable( { posts, termId, rollingBackIds, onRollback } ) {
	const [ diffPost, setDiffPost ] = useState( null );

	if ( ! posts || ! posts.length ) {
		return <p className="nre-dashboard__empty">No posts found.</p>;
	}

	return (
		<>
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
					{ posts.map( ( post ) => (
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
				</tbody>
			</table>

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
