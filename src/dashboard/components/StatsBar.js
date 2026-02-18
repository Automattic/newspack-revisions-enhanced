/**
 * StatsBar — Summary stat cards for a migration.
 */
export default function StatsBar( { stats } ) {
	if ( ! stats ) {
		return null;
	}

	const cards = [
		{ label: 'Created posts', value: stats.posts_created },
		{ label: 'Updated posts', value: stats.posts_updated },
		{ label: 'Total revisions', value: stats.total_revisions },
	];

	return (
		<div className="nre-dashboard__stats">
			{ cards.map( ( card ) => (
				<div key={ card.label } className="nre-dashboard__stat-card">
					<div className="nre-dashboard__stat-value">{ card.value }</div>
					<div className="nre-dashboard__stat-label">{ card.label }</div>
				</div>
			) ) }
		</div>
	);
}
