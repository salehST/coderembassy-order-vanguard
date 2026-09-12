const fs = require( 'fs' );
const path = require( 'path' );
const postcss = require( 'postcss' );

const root = path.resolve( __dirname, '..' );
const failures = [];
const check = ( condition, message ) => {
	if ( ! condition ) failures.push( message );
};
const read = ( file ) => fs.readFileSync( path.join( root, file ), 'utf8' );

const css = postcss.parse( read( 'src/style.css' ) );
css.walkRules( ( rule ) => {
	if ( rule.parent?.type === 'atrule' && /keyframes$/i.test( rule.parent.name || '' ) ) return;
	for ( const selector of rule.selectors ) {
		check( selector.trim().startsWith( '.ceog-app' ), `Unscoped CSS selector: ${ selector }` );
	}
} );

const sourceFiles = [];
const collect = ( directory ) => {
	for ( const item of fs.readdirSync( path.join( root, directory ), { withFileTypes: true } ) ) {
		const relative = path.posix.join( directory, item.name );
		if ( item.isDirectory() ) collect( relative );
		else sourceFiles.push( relative );
	}
};
collect( 'src' );
collect( 'includes' );
const applicationSource = sourceFiles.map( read ).join( '\n' );

check( ! applicationSource.includes( 'dangerouslySetInnerHTML' ), 'React must not use dangerouslySetInnerHTML.' );
check( ! /https?:\/\//.test( applicationSource ), 'Runtime source must not reference remote assets or services.' );
check( ! /\bisPro\b|\bproOnly\b|ceog-pro|LicenseBanner|ExpiredLicenseModal|ProWorkspace|exportLogCsv|Available in Pro|Order Vanguard Pro/i.test( applicationSource ), 'The WordPress.org runtime must not contain paid-feature or license-gating integration.' );
check( ! fs.existsSync( path.join( root, 'src/components/LicenseNotice.jsx' ) ), 'License UI must not ship in the WordPress.org source.' );
check( ! fs.existsSync( path.join( root, 'src/views/ProWorkspace.jsx' ) ), 'Paid workspace mounts must not ship in the WordPress.org source.' );

const functions = read( 'includes/functions.php' );
const settings = read( 'includes/class-ceog-settings.php' );
const logger = read( 'includes/class-ceog-logger.php' );
const privacy = read( 'src/views/PrivacyLogs.jsx' );
check( functions.includes( "'log_retention_days'        => 30" ), 'Retention must have a safe 30-day default.' );
check( settings.includes( "array( 7, 30, 90 )" ), 'Retention sanitation must allow 7, 30, and 90 days.' );
check( logger.includes( "$settings['log_retention_days'] ?? 30" ), 'The daily pruner must use the saved retention setting.' );
check( [ 7, 30, 90 ].every( ( days ) => new RegExp( `<option\\s+value=\\{\\s*${ days }\\s*\\}>` ).test( privacy ) ), 'The admin UI must expose every retention choice.' );

const rest = read( 'includes/class-ceog-rest-controller.php' );
const routeCount = ( rest.match( /register_rest_route\(/g ) || [] ).length;
const permissionCount = ( rest.match( /'permission_callback'\s*=>\s*'ceog_rest_can_manage'/g ) || [] ).length;
check( routeCount === 8, `Expected 8 REST route registrations, found ${ routeCount }.` );
check( permissionCount === 10, `Expected 10 protected REST methods, found ${ permissionCount }.` );
check( ( rest.match( /'args'\s*=>/g ) || [] ).length >= 10, 'Every REST method must declare an args schema.' );
check( rest.includes( "'maximum'           => 100" ), 'Activity Log per-page maximum must remain 100.' );
check( rest.includes( "'maxItems'          => 100" ), 'Bulk log deletion maximum must remain 100.' );

const admin = read( 'includes/class-ceog-admin.php' );
check( admin.includes( 'wp_set_script_translations(' ), 'Admin script translations must be registered.' );
check( admin.includes( 'build/style-index.css' ), 'Missing-build checks must include the compiled stylesheet.' );

const asset = read( 'build/index.asset.php' );
check( asset.includes( "'wp-element'" ), 'The build must externalize WordPress element.' );
check( asset.includes( "'react'" ), 'The build must depend on WordPress-provided React.' );

const readme = read( 'readme.txt' );
check( readme.includes( 'https://github.com/salehST/coderembassy-order-vanguard' ), 'The readme must link to the public source repository.' );
check( readme.includes( 'Human-readable admin source and build configuration are included' ), 'The package must document its bundled source.' );

const pot = path.join( root, 'languages', 'coderembassy-order-vanguard.pot' );
check( fs.existsSync( pot ) && fs.statSync( pot ).size > 1000, 'The release POT file is missing or empty.' );

if ( failures.length ) {
	process.stderr.write( failures.map( ( failure ) => `FAIL: ${ failure }` ).join( '\n' ) + '\n' );
	process.exit( 1 );
}
process.stdout.write( 'Release static audit passed.\n' );
