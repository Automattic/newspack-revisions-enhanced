/**
 * VisualPreview — Side-by-side iframe previews of before/after revision content,
 * with overlay comparison (clip-path slider) and a pixel-diff "Diff Map" mode
 * that highlights changed regions.
 */
import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import {
	ButtonGroup,
	Button,
	RangeControl,
	Spinner,
} from '@wordpress/components';
// Lazy-loaded to avoid bundling 200KB+ into the main chunk.
const loadHtml2Canvas = () =>
	import( /* webpackChunkName: "html2canvas" */ 'html2canvas' ).then(
		( m ) => m.default
	);

/* global nreDashboard */

const MODES = [
	{ value: 'side-by-side', label: 'Side by Side' },
	{ value: 'overlay', label: 'Overlay' },
	{ value: 'diff-map', label: 'Diff Map' },
];

// Pixel diff threshold — how different an RGB channel must be to count as "changed".
const DIFF_THRESHOLD = 30;

// Block size for grouping diff pixels into regions (px).
const BLOCK_SIZE = 4;

function buildPreviewUrl( revisionId, viewUrl ) {
	const url = new URL( viewUrl );
	url.searchParams.set( 'nre_preview_revision', String( revisionId ) );
	url.searchParams.set( '_wpnonce', nreDashboard.previewNonce );
	return url.toString();
}

/**
 * Resize a hidden iframe to a given width and its full document height,
 * so html2canvas can capture the entire page at the correct scale.
 *
 * @param {HTMLIFrameElement} iframe       The iframe element.
 * @param {number}            captureWidth The width to set (px).
 */
function prepareIframeForCapture( iframe, captureWidth ) {
	iframe.style.width = captureWidth + 'px';
	// Let the document reflow at the new width, then expand to full height.
	const doc = iframe.contentDocument || iframe.contentWindow.document;
	const fullHeight = doc.documentElement.scrollHeight;
	iframe.style.height = fullHeight + 'px';
}

/**
 * Capture an iframe's full document as a canvas via html2canvas.
 *
 * @param {HTMLIFrameElement} iframe       The iframe element (already prepared).
 * @param {Function}          html2canvas  The html2canvas function.
 * @param {number}            captureWidth The capture width (px).
 * @return {Promise<HTMLCanvasElement>} The rendered canvas.
 */
async function captureIframe( iframe, html2canvas, captureWidth ) {
	const doc = iframe.contentDocument || iframe.contentWindow.document;
	const fullHeight = doc.documentElement.scrollHeight;

	return html2canvas( doc.body, {
		width: captureWidth,
		height: fullHeight,
		windowWidth: captureWidth,
		windowHeight: fullHeight,
		scrollX: 0,
		scrollY: 0,
		logging: false,
		useCORS: true,
	} );
}

/**
 * Generate a diff map canvas from two source canvases.
 * Identical regions become faded grayscale; changed regions are highlighted.
 *
 * @param {HTMLCanvasElement} canvasA "Before" canvas.
 * @param {HTMLCanvasElement} canvasB "After" canvas.
 * @return {HTMLCanvasElement} The diff map canvas.
 */
function generateDiffMap( canvasA, canvasB ) {
	const w = Math.max( canvasA.width, canvasB.width );
	const h = Math.max( canvasA.height, canvasB.height );

	// Draw both onto same-size canvases to normalize.
	const normalize = ( source ) => {
		const c = document.createElement( 'canvas' );
		c.width = w;
		c.height = h;
		const ctx = c.getContext( '2d' );
		ctx.fillStyle = '#fff';
		ctx.fillRect( 0, 0, w, h );
		ctx.drawImage( source, 0, 0 );
		return ctx.getImageData( 0, 0, w, h );
	};

	const dataA = normalize( canvasA );
	const dataB = normalize( canvasB );
	const pixA = dataA.data;
	const pixB = dataB.data;

	// Build a boolean grid at block resolution marking changed blocks.
	const cols = Math.ceil( w / BLOCK_SIZE );
	const rows = Math.ceil( h / BLOCK_SIZE );
	const changed = new Uint8Array( cols * rows );

	for ( let i = 0; i < pixA.length; i += 4 ) {
		const dr = Math.abs( pixA[ i ] - pixB[ i ] );
		const dg = Math.abs( pixA[ i + 1 ] - pixB[ i + 1 ] );
		const db = Math.abs( pixA[ i + 2 ] - pixB[ i + 2 ] );
		if ( dr > DIFF_THRESHOLD || dg > DIFF_THRESHOLD || db > DIFF_THRESHOLD ) {
			const px = ( i / 4 ) % w;
			const py = Math.floor( i / 4 / w );
			const bx = Math.floor( px / BLOCK_SIZE );
			const by = Math.floor( py / BLOCK_SIZE );
			changed[ by * cols + bx ] = 1;
		}
	}

	// Render diff map: "After" image faded, with changed blocks highlighted.
	const out = document.createElement( 'canvas' );
	out.width = w;
	out.height = h;
	const ctx = out.getContext( '2d' );

	// Draw the "After" canvas as a faded base.
	ctx.drawImage( canvasB, 0, 0 );
	ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
	ctx.fillRect( 0, 0, w, h );

	// Highlight changed blocks.
	ctx.fillStyle = 'rgba(228, 55, 65, 0.35)';
	for ( let by = 0; by < rows; by++ ) {
		for ( let bx = 0; bx < cols; bx++ ) {
			if ( changed[ by * cols + bx ] ) {
				ctx.fillRect(
					bx * BLOCK_SIZE,
					by * BLOCK_SIZE,
					BLOCK_SIZE,
					BLOCK_SIZE
				);
			}
		}
	}

	return out;
}

export default function VisualPreview( {
	postId,
	compareFrom,
	compareTo,
	viewUrl,
	postStatus,
} ) {
	const [ mode, setMode ] = useState( 'side-by-side' );
	const [ sliderValue, setSliderValue ] = useState( 50 );
	const [ diffMapUrl, setDiffMapUrl ] = useState( null );
	const [ diffMapLoading, setDiffMapLoading ] = useState( false );
	const [ diffMapError, setDiffMapError ] = useState( null );

	const beforeRef = useRef( null );
	const afterRef = useRef( null );
	const diffBeforeRef = useRef( null );
	const diffAfterRef = useRef( null );
	const diffMapGenerated = useRef( false );
	const containerRef = useRef( null );

	const beforeUrl = buildPreviewUrl( compareFrom || 0, viewUrl );
	const afterUrl = buildPreviewUrl( compareTo, viewUrl );

	const isCreated = postStatus === 'created';

	// Reset diff map when mode changes away.
	useEffect( () => {
		if ( mode !== 'diff-map' ) {
			diffMapGenerated.current = false;
		}
	}, [ mode ] );

	const generateDiffMapFromIframes = useCallback( async () => {
		if ( diffMapGenerated.current || ! diffBeforeRef.current || ! diffAfterRef.current ) {
			return;
		}
		diffMapGenerated.current = true;
		setDiffMapLoading( true );
		setDiffMapError( null );

		try {
			// Measure the container the image will be displayed in,
			// so we capture at exactly 1:1 pixel ratio.
			const captureWidth = containerRef.current
				? containerRef.current.clientWidth
				: 780;

			// Resize hidden iframes to match and expand to full height.
			prepareIframeForCapture( diffBeforeRef.current, captureWidth );
			prepareIframeForCapture( diffAfterRef.current, captureWidth );

			const html2canvas = await loadHtml2Canvas();
			const [ canvasA, canvasB ] = await Promise.all( [
				captureIframe( diffBeforeRef.current, html2canvas, captureWidth ),
				captureIframe( diffAfterRef.current, html2canvas, captureWidth ),
			] );
			const diffCanvas = generateDiffMap( canvasA, canvasB );
			setDiffMapUrl( diffCanvas.toDataURL() );
		} catch {
			setDiffMapError(
				'Could not generate diff map. Both iframes must be fully loaded.'
			);
		} finally {
			setDiffMapLoading( false );
		}
	}, [] );

	// When both hidden iframes for diff-map mode have loaded, generate the map.
	const diffLoadCount = useRef( 0 );
	const onDiffIframeLoad = useCallback( () => {
		diffLoadCount.current += 1;
		if ( diffLoadCount.current >= 2 ) {
			generateDiffMapFromIframes();
		}
	}, [ generateDiffMapFromIframes ] );

	// Reset load counter when entering diff-map mode.
	useEffect( () => {
		if ( mode === 'diff-map' ) {
			diffLoadCount.current = 0;
			diffMapGenerated.current = false;
			setDiffMapUrl( null );
		}
	}, [ mode ] );

	if ( isCreated ) {
		return (
			<div className="nre-visual-preview">
				<p className="nre-visual-preview__note">
					This post was created by the migration — there is no
					previous version to compare.
				</p>
				<div className="nre-visual-preview__single">
					<div className="nre-visual-preview__label">After</div>
					<iframe
						className="nre-visual-preview__iframe"
						src={ afterUrl }
						title="After migration"
					/>
				</div>
			</div>
		);
	}

	return (
		<div className="nre-visual-preview" ref={ containerRef }>
			<div className="nre-visual-preview__controls">
				<ButtonGroup>
					{ MODES.map( ( m ) => (
						<Button
							key={ m.value }
							variant={
								mode === m.value ? 'primary' : 'secondary'
							}
							size="compact"
							onClick={ () => setMode( m.value ) }
						>
							{ m.label }
						</Button>
					) ) }
				</ButtonGroup>
				{ mode === 'overlay' && (
					<RangeControl
						label="Clip position"
						value={ sliderValue }
						onChange={ setSliderValue }
						min={ 0 }
						max={ 100 }
						__nextHasNoMarginBottom
					/>
				) }
			</div>

			{ mode === 'side-by-side' && (
				<div className="nre-visual-preview__side-by-side">
					<div className="nre-visual-preview__pane">
						<div className="nre-visual-preview__label">Before</div>
						<iframe
							ref={ beforeRef }
							className="nre-visual-preview__iframe"
							src={ beforeUrl }
							title="Before migration"
						/>
					</div>
					<div className="nre-visual-preview__pane">
						<div className="nre-visual-preview__label">After</div>
						<iframe
							ref={ afterRef }
							className="nre-visual-preview__iframe"
							src={ afterUrl }
							title="After migration"
						/>
					</div>
				</div>
			) }

			{ mode === 'overlay' && (
				<div className="nre-visual-preview__overlay">
					<div className="nre-visual-preview__overlay-label nre-visual-preview__overlay-label--before">
						Before
					</div>
					<div className="nre-visual-preview__overlay-label nre-visual-preview__overlay-label--after">
						After
					</div>
					<iframe
						className="nre-visual-preview__iframe"
						src={ beforeUrl }
						title="Before migration"
					/>
					<iframe
						className="nre-visual-preview__iframe nre-visual-preview__iframe--overlay"
						src={ afterUrl }
						title="After migration"
						style={ {
							clipPath: `inset(0 0 0 ${ sliderValue }%)`,
						} }
					/>
				</div>
			) }

			{ mode === 'diff-map' && (
				<>
					{ /* Hidden iframes used only for canvas capture */ }
					<div className="nre-visual-preview__capture-iframes">
						<iframe
							ref={ diffBeforeRef }
							src={ beforeUrl }
							title="Before (capture)"
							onLoad={ onDiffIframeLoad }
						/>
						<iframe
							ref={ diffAfterRef }
							src={ afterUrl }
							title="After (capture)"
							onLoad={ onDiffIframeLoad }
						/>
					</div>

					{ diffMapLoading && (
						<div className="nre-visual-preview__diff-map-loading">
							<Spinner />
							<span>Generating diff map&hellip;</span>
						</div>
					) }

					{ diffMapError && (
						<p className="nre-visual-preview__diff-map-error">
							{ diffMapError }
						</p>
					) }

					{ diffMapUrl && (
						<div className="nre-visual-preview__diff-map">
							<div className="nre-visual-preview__diff-map-legend">
								<span className="nre-visual-preview__diff-map-legend-swatch" />
								Changed regions
							</div>
							<img
								src={ diffMapUrl }
								alt="Visual diff map highlighting changed regions"
								className="nre-visual-preview__diff-map-image"
							/>
						</div>
					) }
				</>
			) }
		</div>
	);
}
