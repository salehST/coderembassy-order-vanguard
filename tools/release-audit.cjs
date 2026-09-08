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
check( applicationSource.includes( 'data-ceog-pro-view={ view }' ), 'The embedded Pro mount must expose its requested view before effects run.' );
check( applicationSource.includes( "id: 'history-reporting'" ), 'The shared shell must expose the Pro History and Reports workspace.' );
check( applicationSource.includes( "id: 'attack-cleanup'" ), 'The shared shell must expose a dedicated Attack Cleanup route.' );
check( applicationSource.includes( "id: 'pro-license'" ), 'The shared shell must expose a dedicated Pro License route.' );
check( /id:\s*'pro-license'[\s\S]{0,200}proOnly:\s*true/.test( read( 'src/navigation.js' ) ), 'The Pro License route must remain hidden on Free-only installations.' );
check( applicationSource.includes( '<LicenseBanner' ), 'The shared shell must host the Pro-provided in-app license banner.' );
check( applicationSource.includes( '<ExpiredLicenseModal' ), 'The shared shell must host the Pro-provided expired-license reminder.' );
check( applicationSource.includes( 'public function get_field_name_for_flow( $flow )' ), 'Free must expose the shared salted field-name contract used by Pro Checkout Block protection.' );
check( applicationSource.includes( 'Pro honeypot active' ), 'Store API Guard must report the Pro Checkout Block field layer.' );
check( applicationSource.includes( "id: 'turnstile'" ), 'The shared shell must expose the Pro Turnstile workspace.' );
check( applicationSource.includes( '<ProWorkspace view="turnstile"' ), 'The Turnstile route must mount the separate Pro workspace.' );
check( applicationSource.includes( 'exportLogCsv( filters )' ), 'The Pro CSV action must export the currently applied Activity Log filters.' );
check( applicationSource.includes( "__( 'Export filtered CSV'" ), 'The Pro CSV action must remain available from Activity Log.' );

const rest = read( 'includes/class-ceog-rest-controller.php' );
const routeCount = ( rest.match( /register_rest_route\(/g ) || [] ).length;
const permissionCount = ( rest.match( /'permission_callback'\s*=>\s*'ceog_rest_can_manage'/g ) || [] ).length;
check( routeCount === 8, `Expected 8 REST route registrations, found ${ routeCount }.` );
check( permissionCount === 10, `Expected 10 protected REST methods, found ${ permissionCount }.` );
check( ( rest.match( /'args'\s*=>/g ) || [] ).length >= 10, 'Every REST method must declare an args schema.' );
check( rest.includes( "'maximum'           => 100" ), 'Activity Log per-page maximum must remain 100.' );
check( rest.includes( "'maxItems'          => 100" ), 'Bulk log deletion maximum must remain 100.' );
check( read( 'includes/class-ceog-logger.php' ).includes( "'include_total'" ), 'Bounded export batches must be able to skip repeated count queries.' );

const admin = read( 'includes/class-ceog-admin.php' );
check( admin.includes( "wp_set_script_translations(" ), 'Admin script translations must be registered.' );
check( admin.includes( "build/style-index.css" ), 'Missing-build checks must include the compiled stylesheet.' );

const asset = read( 'build/index.asset.php' );
check( asset.includes( "'wp-element'" ), 'The build must externalize WordPress element.' );
check( asset.includes( "'react'" ), 'The build must depend on WordPress-provided React.' );

const pot = path.join( root, 'languages', 'coderembassy-order-vanguard.pot' );
check( fs.existsSync( pot ) && fs.statSync( pot ).size > 1000, 'The release POT file is missing or empty.' );

if ( failures.length ) {
	process.stderr.write( failures.map( ( failure ) => `FAIL: ${ failure }` ).join( '\n' ) + '\n' );
	process.exit( 1 );
}
process.stdout.write( 'Release static audit passed.\n' );
