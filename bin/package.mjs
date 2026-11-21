import { execFileSync } from 'node:child_process';
import { cpSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const source = fileURLToPath( new URL( '../', import.meta.url ) );
const header = readFileSync( join( source, 'slashphoto.php' ), 'utf8' );
const version = header.match( /^\s*\* Version:\s*([\da-z.-]+)\s*$/im )?.[ 1 ];
if ( ! version ) {
	throw new Error( 'SlashPhoto plugin version was not found.' );
}

const output = resolve( process.argv[ 2 ] || join( source, 'dist', `slashphoto-${ version }-beta.zip` ) );
const files = [ 'slashphoto.php', 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE', 'assets', 'blocks', 'languages', 'src', 'templates' ];
const stage = mkdtempSync( join( tmpdir(), 'slashphoto-package-' ) );
mkdirSync( dirname( output ), { recursive: true } );
const temporary = mkdtempSync( join( dirname( output ), '.slashphoto-package-' ) );

try {
	const target = join( stage, 'slashphoto' );
	mkdirSync( target );
	for ( const file of files ) {
		cpSync( join( source, file ), join( target, file ), {
			recursive: true,
			filter( path ) {
				if ( lstatSync( path ).isSymbolicLink() ) {
					throw new Error( `Symbolic links are excluded from the plugin package: ${ path }` );
				}
				return ! path.split( '/' ).at( -1 ).startsWith( '.' );
			},
		} );
	}
	const archive = join( temporary, 'slashphoto.zip' );
	execFileSync( 'zip', [ '-q', '-X', '-r', archive, 'slashphoto' ], { cwd: stage, stdio: 'inherit' } );
	renameSync( archive, output );
	console.log( output );
} finally {
	rmSync( stage, { recursive: true, force: true } );
	rmSync( temporary, { recursive: true, force: true } );
}
