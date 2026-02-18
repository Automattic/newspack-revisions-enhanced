/**
 * BulkRollback — Bulk rollback button with confirmation modal.
 */
import { useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';

export default function BulkRollback( {
	stats,
	isRollingBack,
	onBulkRollback,
} ) {
	const [ showConfirm, setShowConfirm ] = useState( false );

	if ( ! stats || stats.posts_updated === 0 ) {
		return null;
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
				{ isRollingBack
					? 'Rolling back all...'
					: `Rollback All (${ stats.posts_updated })` }
			</Button>

			{ showConfirm && (
				<Modal
					title="Confirm Bulk Rollback"
					onRequestClose={ () => setShowConfirm( false ) }
					className="nre-dashboard__modal"
				>
					<p>
						This will roll back <strong>{ stats.posts_updated }</strong>{ ' ' }
						updated post{ stats.posts_updated !== 1 ? 's' : '' } to their
						pre-migration state.
					</p>
					{ stats.posts_created > 0 && (
						<p>
							<strong>{ stats.posts_created }</strong> post{ stats.posts_created !== 1 ? 's' : '' }{ ' ' }
							created during this migration will be skipped (no
							prior state to restore).
						</p>
					) }
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
								onBulkRollback();
							} }
						>
							Rollback All
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
