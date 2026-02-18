/**
 * BulkRollback — Bulk rollback button with confirmation modal and progress display.
 */
import { useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';

export default function BulkRollback( {
	stats,
	onBulkRollback,
	onCancelRollback,
	rollbackStatus,
} ) {
	const [ showConfirm, setShowConfirm ] = useState( false );

	if ( ! stats || stats.posts_updated === 0 ) {
		return null;
	}

	const isRunning = rollbackStatus && rollbackStatus.status === 'running';

	if ( isRunning ) {
		const { processed, total } = rollbackStatus;
		const pct = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;

		return (
			<div className="nre-dashboard__rollback-progress">
				<div className="nre-dashboard__rollback-progress-text">
					Rolling back... { processed.toLocaleString() } /{ ' ' }
					{ total.toLocaleString() } ({ pct }%)
				</div>
				<div className="nre-dashboard__rollback-progress-bar">
					<div
						className="nre-dashboard__rollback-progress-fill"
						style={ { width: `${ pct }%` } }
					/>
				</div>
				<Button
					variant="secondary"
					isDestructive
					size="compact"
					onClick={ onCancelRollback }
				>
					Cancel Rollback
				</Button>
			</div>
		);
	}

	return (
		<>
			<Button
				variant="secondary"
				isDestructive
				onClick={ () => setShowConfirm( true ) }
			>
				{ `Rollback All (${ stats.posts_updated.toLocaleString() })` }
			</Button>

			{ showConfirm && (
				<Modal
					title="Confirm Bulk Rollback"
					onRequestClose={ () => setShowConfirm( false ) }
					className="nre-dashboard__modal"
				>
					<p>
						This will roll back <strong>{ stats.posts_updated.toLocaleString() }</strong>{ ' ' }
						updated post{ stats.posts_updated !== 1 ? 's' : '' } to their
						pre-migration state.
					</p>
					{ stats.posts_created > 0 && (
						<p>
							<strong>{ stats.posts_created.toLocaleString() }</strong> post{ stats.posts_created !== 1 ? 's' : '' }{ ' ' }
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
