/**
 * MigrationDetail — Right panel for the selected migration.
 */
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
} ) {
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
						onClick={ () =>
							downloadCsv( detail.slug, detail.posts )
						}
					>
						Download CSV
					</Button>
					<BulkRollback
						stats={ detail.stats }
						isRollingBack={ rollingBackIds.has( 'bulk' ) }
						onBulkRollback={ onBulkRollback }
					/>
				</div>
			</div>

			<StatsBar stats={ detail.stats } />

			<PostTable
				posts={ detail.posts }
				termId={ detail.term_id }
				rollingBackIds={ rollingBackIds }
				onRollback={ onRollback }
			/>
		</div>
	);
}
