/**
 * MigrationList — Left sidebar showing all migrations.
 */
import { Spinner } from '@wordpress/components';

export default function MigrationList( {
	migrations,
	loading,
	selectedId,
	onSelect,
} ) {
	if ( loading ) {
		return (
			<div className="nre-dashboard__sidebar">
				<div className="nre-dashboard__sidebar-header">Migrations</div>
				<div className="nre-dashboard__loading">
					<Spinner />
				</div>
			</div>
		);
	}

	if ( ! migrations.length ) {
		return (
			<div className="nre-dashboard__sidebar">
				<div className="nre-dashboard__sidebar-header">Migrations</div>
				<div className="nre-dashboard__empty">
					No migrations found.
				</div>
			</div>
		);
	}

	return (
		<div className="nre-dashboard__sidebar">
			<div className="nre-dashboard__sidebar-header">Migrations</div>
			<div className="nre-dashboard__sidebar-list">
				{ migrations.map( ( m ) => (
					<button
						key={ m.term_id }
						className={ `nre-dashboard__sidebar-item${
							selectedId === m.term_id ? ' is-selected' : ''
						}` }
						onClick={ () => onSelect( m.term_id ) }
					>
						<span className="nre-dashboard__sidebar-item-name">
							{ m.name }
						</span>
						<span className="nre-dashboard__sidebar-item-meta">
							{ m.post_count } post{ m.post_count !== 1 ? 's' : '' }
						</span>
						<span className="nre-dashboard__sidebar-item-date">
							{ m.date }
						</span>
					</button>
				) ) }
			</div>
		</div>
	);
}
