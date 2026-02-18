/**
 * RollbackButton — Per-post rollback with confirmation modal.
 */
import { useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';

export default function RollbackButton( {
	post,
	isRollingBack,
	onRollback,
} ) {
	const [ showConfirm, setShowConfirm ] = useState( false );

	if ( ! post.can_rollback ) {
		return (
			<span className="nre-dashboard__no-rollback">Created</span>
		);
	}

	return (
		<>
			<Button
				variant="secondary"
				isDestructive
				isBusy={ isRollingBack }
				disabled={ isRollingBack }
				onClick={ () => setShowConfirm( true ) }
			>
				{ isRollingBack ? 'Rolling back...' : 'Rollback' }
			</Button>

			{ showConfirm && (
				<Modal
					title="Confirm Rollback"
					onRequestClose={ () => setShowConfirm( false ) }
					className="nre-dashboard__modal"
				>
					<p>
						Roll back <strong>{ post.title }</strong> to its
						pre-migration state? This will restore content,
						taxonomies, and post type from the revision before
						this migration.
					</p>
					<div className="nre-dashboard__modal-actions">
						<Button
							variant="secondary"
							onClick={ () => setShowConfirm( false ) }
						>
							Cancel
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => {
								setShowConfirm( false );
								onRollback( post.post_id );
							} }
						>
							Rollback
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
