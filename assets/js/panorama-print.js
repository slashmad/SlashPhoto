/**
 * Renders a rectilinear photo from a complete equirectangular panorama.
 * The browser uses this projection for both the preview and print file.
 */

const radians = degrees => degrees * Math.PI / 180;

export async function project( source, view, longest, original = false ) {
	const image = new Image();
	image.src = source;
	await image.decode();
	if ( Math.abs( image.naturalWidth / image.naturalHeight - 2 ) > 0.015 ) {
		throw new Error( 'Expected a complete equirectangular image.' );
	}
	const input = document.createElement( 'canvas' );
	input.width = Math.min( image.naturalWidth, original ? 12000 : 2048 );
	input.height = Math.round( input.width / 2 );
	const canvas = document.createElement( 'canvas' );
	const aspect = view.width / view.height;
	canvas.width = longest;
	canvas.height = Math.max( 1, Math.round( longest / aspect ) );
	const context = input.getContext( '2d', { willReadFrequently: true } );
	context.drawImage( image, 0, 0, input.width, input.height );
	const sourcePixels = context.getImageData( 0, 0, input.width, input.height ).data;
	const draw = canvas.getContext( '2d' );
	const result = draw.createImageData( canvas.width, canvas.height );

	const yaw = radians( view.yaw );
	const pitch = radians( view.pitch );
	const fov = Math.tan( radians( view.fov ) / 2 );
	const ca = Math.cos( yaw );
	const sa = Math.sin( yaw );
	const cp = Math.cos( pitch );
	const sp = Math.sin( pitch );
	const width = input.width;
	const height = input.height;

	for ( let y = 0; y < canvas.height; y++ ) {
		const up = ( 1 - 2 * ( y + 0.5 ) / canvas.height ) * fov;
		const z = sp + up * cp;
		const forward = cp - up * sp;
		for ( let x = 0; x < canvas.width; x++ ) {
			const right = ( 2 * ( x + 0.5 ) / canvas.width - 1 ) * fov * aspect;
			const a = ca * forward - sa * right;
			const b = sa * forward + ca * right;
			const u = ( 0.5 + Math.atan2( b, a ) / ( 2 * Math.PI ) ) * width;
			const v = Math.max( 0, Math.min( height - 1, ( 0.5 - Math.atan2( z, Math.hypot( a, b ) ) / Math.PI ) * height ) );
			const u0 = Math.floor( u );
			const v0 = Math.floor( v );
			const fx = u - u0;
			const fy = v - v0;
			const col = u0 % width;
			const next = ( col + 1 ) % width;
			const top = v0 * width;
			const bottom = Math.min( v0 + 1, height - 1 ) * width;
			const pixel = 4 * ( y * canvas.width + x );
			for ( let channel = 0; channel < 3; channel++ ) {
				const topValue = sourcePixels[ 4 * ( top + col ) + channel ] * ( 1 - fx ) + sourcePixels[ 4 * ( top + next ) + channel ] * fx;
				const bottomValue = sourcePixels[ 4 * ( bottom + col ) + channel ] * ( 1 - fx ) + sourcePixels[ 4 * ( bottom + next ) + channel ] * fx;
				result.data[ pixel + channel ] = ( 1 - fy ) * topValue + fy * bottomValue;
			}
			result.data[ pixel + 3 ] = 255;
		}
	}
	draw.putImageData( result, 0, 0 );
	return canvas;
}
