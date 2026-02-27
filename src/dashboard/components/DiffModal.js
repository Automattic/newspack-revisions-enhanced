/**
 * DiffModal — Modal that fetches and renders a revision diff inline,
 * with an optional Visual Preview tab showing rendered content in iframes.
 */
import { useState, useEffect } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import VisualPreview from './VisualPreview';

const TABS = [
	{ value: 'field-diff', label: 'Field Diff' },
	{ value: 'visual-preview', label: 'Visual Preview' },
];

export default function DiffModal( {
	postId,
	termId,
	postTitle,
	postStatus,
	compareFrom,
	compareTo,
	viewUrl,
	onClose,
} ) {
	const [ activeTab, setActiveTab ] = useState( 'field-diff' );
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
			<div className="nre-dashboard__diff-tabs">
				{ TABS.map( ( tab ) => (
					<button
						key={ tab.value }
						className={ `nre-dashboard__diff-tab${
							activeTab === tab.value ? ' is-active' : ''
						}` }
						onClick={ () => setActiveTab( tab.value ) }
					>
						{ tab.label }
					</button>
				) ) }
			</div>

			{ activeTab === 'field-diff' && (
				<>
					{ loading && (
						<div className="nre-dashboard__loading">
							<Spinner />
						</div>
					) }

					{ error && (
						<p className="nre-dashboard__diff-error">{ error }</p>
					) }

					{ fields && fields.length === 0 && ! error && (
						<p>No differences found.</p>
					) }

					{ fields &&
						fields.length > 0 &&
						fields.map( ( field ) => (
							<div
								key={ field.id }
								className="nre-dashboard__diff-field"
							>
								<h3 className="nre-dashboard__diff-field-name">
									{ field.name }
								</h3>
								<div
									className={ `nre-dashboard__diff-field-content${
										postStatus === 'created'
											? ' is-created'
											: ''
									}` }
									dangerouslySetInnerHTML={ {
										__html: field.diff,
									} }
								/>
							</div>
						) ) }
				</>
			) }

			{ activeTab === 'visual-preview' && (
				<VisualPreview
					postId={ postId }
					compareFrom={ compareFrom }
					compareTo={ compareTo }
					viewUrl={ viewUrl }
					postStatus={ postStatus }
				/>
			) }
		</Modal>
	);
}
