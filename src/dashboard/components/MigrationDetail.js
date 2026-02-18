/**
 * MigrationDetail — Right panel for the selected migration.
 */
import { useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';

import StatsBar from './StatsBar';
import BulkRollback from './BulkRollback';
import PostTable from './PostTable';
import { downloadCsv } from '../utils';

export default function MigrationDetail( {
	detail,
	loading,
	selectedId,
	rollingBackIds,
	onRollback,
	onBulkRollback,
	onCancelRollback,
	rollbackStatus,
	isRollbackRunning,
	postsRefreshKey,
} ) {
	const [ downloadingCsv, setDownloadingCsv ] = useState( false );

	const handleCsvDownload = async () => {
		setDownloadingCsv( true );
		try {
			await downloadCsv( detail.term_id );
		} finally {
			setDownloadingCsv( false );
		}
	};

	if ( ! selectedId ) {
		return (
			<div className="nre-dashboard__detail">
				<div className="nre-dashboard__detail-empty">
					Select a migration to view details.
				</div>
			</div>
		);
	}

	if ( loading ) {
		return (
			<div className="nre-dashboard__detail">
				<div className="nre-dashboard__loading">
					<Spinner />
				</div>
			</div>
		);
	}

	if ( ! detail ) {
		return (
			<div className="nre-dashboard__detail">
				<div className="nre-dashboard__detail-empty">
					Migration not found.
				</div>
			</div>
		);
	}

	return (
		<div className="nre-dashboard__detail">
			<div className="nre-dashboard__detail-header">
				<div>
					<h2>{ detail.name }</h2>
					{ detail.date && (
						<span className="nre-dashboard__detail-date">
							{ detail.date }
						</span>
					) }
				</div>
				<div className="nre-dashboard__detail-header-actions">
					<Button
						variant="secondary"
						isBusy={ downloadingCsv }
						disabled={ downloadingCsv || isRollbackRunning }
						onClick={ handleCsvDownload }
					>
						{ downloadingCsv ? 'Downloading...' : 'Download CSV' }
					</Button>
					<BulkRollback
						stats={ detail.stats }
						onBulkRollback={ onBulkRollback }
						onCancelRollback={ onCancelRollback }
						rollbackStatus={ rollbackStatus }
					/>
				</div>
			</div>

			<StatsBar stats={ detail.stats } />

			<PostTable
				termId={ detail.term_id }
				stats={ detail.stats }
				rollingBackIds={ rollingBackIds }
				onRollback={ onRollback }
				refreshKey={ postsRefreshKey }
				isRollbackRunning={ isRollbackRunning }
			/>
		</div>
	);
}
