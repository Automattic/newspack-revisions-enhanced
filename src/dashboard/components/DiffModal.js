/**
 * DiffModal — Modal that fetches and renders a revision diff inline.
 */
import { useState, useEffect } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

export default function DiffModal( {
	postId,
	termId,
	postTitle,
	postStatus,
	onClose,
} ) {
	const [ fields, setFields ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( {
			path: `/nre/v1/migrations/${ termId }/diff/${ postId }`,
		} )
			.then( ( data ) => {
				setFields( Array.isArray( data ) ? data : [] );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to load diff.' );
				setLoading( false );
			} );
	}, [ postId, termId ] );

	return (
		<Modal
			title={ `Changes: ${ postTitle }` }
			onRequestClose={ onClose }
			className="nre-dashboard__diff-modal"
		>
			{ loading && (
				<div className="nre-dashboard__loading">
					<Spinner />
				</div>
			) }

			{ error && <p className="nre-dashboard__diff-error">{ error }</p> }

			{ fields && fields.length === 0 && ! error && (
				<p>No differences found.</p>
			) }

			{ fields &&
				fields.length > 0 &&
				fields.map( ( field ) => (
					<div key={ field.id } className="nre-dashboard__diff-field">
						<h3 className="nre-dashboard__diff-field-name">
							{ field.name }
						</h3>
						<div
							className={ `nre-dashboard__diff-field-content${
								postStatus === 'created' ? ' is-created' : ''
							}` }
							dangerouslySetInnerHTML={ {
								__html: field.diff,
							} }
						/>
					</div>
				) ) }
		</Modal>
	);
}
