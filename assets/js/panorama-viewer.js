/**
 * SlashPhoto 360° viewer.
 *
 * Loaded on demand by the lightbox the first time a 360° image is opened.
 * Wraps Photo Sphere Viewer; the panorama geometry (panoData) is computed on
 * the server from the image's GPano metadata, so the viewer is told not to
 * read XMP itself (re-cropped panoramas have XMP that no longer matches the
 * pixels).
 *
 * @package SlashPhoto
 */

import { EquirectangularAdapter, Viewer } from '@slashphoto/vendor/psv-core';
import { VisibleRangePlugin } from '@slashphoto/vendor/psv-visible-range';

/**
 * Whether the browser can render WebGL.
 *
 * @return {boolean} True when WebGL is available.
 */
export function supportsWebGL() {
	try {
		const canvas = document.createElement( 'canvas' );
		return !! ( canvas.getContext( 'webgl2' ) || canvas.getContext( 'webgl' ) );
	} catch {
		return false;
	}
}

/**
 * Creates a viewer.
 *
 * @param {HTMLElement} container Element to render into.
 * @param {Object}      options
 * @param {string}      options.src       Image URL.
 * @param {Object}      options.panoData  Geometry for Photo Sphere Viewer.
 * @param {string}      options.caption   Accessible caption.
 * @param {Object}      options.i18n      Translated strings.
 * @return {{ destroy: Function, ready: Promise<void> }} Viewer handle.
 */
export function createViewer( container, { src, panoData, caption, i18n } ) {
	const hasData = panoData && panoData.fullWidth;
	const isPartial = hasData && ( panoData.croppedWidth < panoData.fullWidth || panoData.croppedHeight < panoData.fullHeight );

	// Start looking at the centre of the photographed area.
	let defaultYaw = 0;
	let defaultPitch = 0;
	if ( hasData ) {
		const centreX = panoData.croppedX + panoData.croppedWidth / 2;
		const centreY = panoData.croppedY + panoData.croppedHeight / 2;
		defaultYaw = ( centreX / panoData.fullWidth - 0.5 ) * 2 * Math.PI;
		defaultPitch = ( 0.5 - centreY / panoData.fullHeight ) * Math.PI;
	}

	const plugins = isPartial ? [ [ VisibleRangePlugin, { usePanoData: true } ] ] : [];

	const viewer = new Viewer( {
		container,
		panorama: src,
		panoData: hasData ? panoData : undefined,
		adapter: [ EquirectangularAdapter, { useXmpData: false } ],
		caption: caption || '',
		description: null,
		loadingTxt: i18n.loading,
		defaultYaw,
		defaultPitch,
		defaultZoomLvl: isPartial ? 0 : 30,
		mousewheelCtrlKey: false,
		touchmoveTwoFingers: false,
		navbar: [ 'zoom', 'caption', 'fullscreen' ],
		lang: {
			fullscreen: i18n.fullscreen,
			loading: i18n.loading,
			loadError: i18n.noWebGL,
			webglError: i18n.noWebGL,
		},
		plugins,
	} );

	const ready = new Promise( ( resolve ) => viewer.addEventListener( 'ready', () => resolve(), { once: true } ) );

	return {
		ready,
		printView() {
			if ( ! hasData || panoData.fullWidth - panoData.croppedWidth > 1 || panoData.fullHeight - panoData.croppedHeight > 1 || panoData.posePitch || panoData.poseRoll ) {
				return null;
			}
			const { textureX, textureY } = viewer.dataHelper.sphericalCoordsToTextureCoords( viewer.getPosition() );
			const yaw = ( ( textureX + panoData.croppedX ) / panoData.fullWidth - 0.5 ) * 360;
			const pitch = ( 0.5 - ( textureY + panoData.croppedY ) / panoData.fullHeight ) * 180;
			const fov = viewer.state.vFov;
			if ( ! Number.isFinite( yaw ) || ! Number.isFinite( pitch ) || Math.abs( pitch ) > 89.5 || fov < 15 || fov > 100 ) {
				return null;
			}
			return [ yaw, pitch, fov ].map( value => Math.round( value * 1000 ) ).join( ':' );
		},
		destroy() {
			viewer.destroy();
		},
	};
}
