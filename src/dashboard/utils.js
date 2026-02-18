/**
 * Dashboard utilities.
 */

/**
 * Trigger download of the self-contained HTML migration report.
 *
 * @param {number} termId The migration term ID.
 */
export function downloadReport( termId ) {
	const { exportUrl, exportNonce } = window.nreDashboard;
	const params = new URLSearchParams( {
		action: 'nre_export_migration',
		term_id: termId,
		_wpnonce: exportNonce,
	} );

	window.open( `${ exportUrl }?${ params.toString() }`, '_blank' );
}

/**
 * Download a CSV of migration posts from the server.
 *
 * @param {number} termId The migration term ID.
 */
export async function downloadCsv( termId ) {
	const { exportUrl, exportNonce } = window.nreDashboard;
	const params = new URLSearchParams( {
		action: 'nre_export_migration',
		term_id: termId,
		format: 'csv',
		_wpnonce: exportNonce,
	} );

	const response = await fetch( `${ exportUrl }?${ params.toString() }`, {
		credentials: 'same-origin',
	} );

	const disposition = response.headers.get( 'Content-Disposition' ) || '';
	const match = disposition.match( /filename="(.+?)"/ );
	const filename = match ? match[ 1 ] : `migration-${ termId }-posts.csv`;

	const blob = await response.blob();
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	URL.revokeObjectURL( url );
}
