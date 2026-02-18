/**
 * Dashboard utilities.
 */

/**
 * Generate and download a CSV of migration posts.
 *
 * @param {string} migrationSlug The migration slug for the filename.
 * @param {Array}  posts         Array of post objects from the detail response.
 */
export function downloadCsv( migrationSlug, posts ) {
	const headers = [ 'Post ID', 'Title', 'Status', 'Post Type', 'Revision URL' ];
	const rows = posts.map( ( post ) => [
		post.post_id,
		`"${ ( post.title || '' ).replace( /"/g, '""' ) }"`,
		post.status,
		post.post_type,
		post.revision_url || '',
	] );

	const csv = [ headers.join( ',' ), ...rows.map( ( r ) => r.join( ',' ) ) ].join( '\n' );
	const blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
	const url = URL.createObjectURL( blob );

	const link = document.createElement( 'a' );
	link.href = url;
	link.download = `migration-${ migrationSlug }-posts.csv`;
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	URL.revokeObjectURL( url );
}
