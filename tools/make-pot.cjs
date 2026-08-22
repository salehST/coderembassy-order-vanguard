const fs = require( 'fs' );
const path = require( 'path' );
const parser = require( '@babel/parser' );
const traverse = require( '@babel/traverse' ).default;
const gettextParser = require( 'gettext-parser' );

const root = path.resolve( __dirname, '..' );
const domain = 'coderembassy-order-guard';
const entries = new Map();

const lineAt = ( source, index ) => source.slice( 0, index ).split( /\r?\n/ ).length;
const translatorComment = ( source, index ) => {
	const nearby = source.slice( Math.max( 0, index - 400 ), index );
	const match = nearby.match( /\/\*\s*translators:\s*((?:(?!\*\/)[\s\S])*)\*\/\s*$/i );
	return match ? match[ 1 ].replace( /\s+/g, ' ' ).trim() : '';
};

const add = ( msgid, file, line, extracted = '' ) => {
	if ( ! msgid ) {
		return;
	}
	const current = entries.get( msgid ) || { references: new Set(), comments: new Set() };
	current.references.add( `${ file }:${ line }` );
	if ( extracted ) {
		current.comments.add( extracted );
	}
	entries.set( msgid, current );
};

const decodePhpString = ( raw ) => {
	const quote = raw[ 0 ];
	const body = raw.slice( 1, -1 );
	if ( quote === "'" ) {
		return body.replace( /\\'/g, "'" ).replace( /\\\\/g, '\\' );
	}
	return body
		.replace( /\\n/g, '\n' )
		.replace( /\\r/g, '\r' )
		.replace( /\\t/g, '\t' )
		.replace( /\\\$/g, '$' )
		.replace( /\\"/g, '"' )
		.replace( /\\\\/g, '\\' );
};

const extractPhp = ( file ) => {
	const source = fs.readFileSync( path.join( root, file ), 'utf8' );
	const calls = /\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(/g;
	let match;
	while ( ( match = calls.exec( source ) ) ) {
		let cursor = calls.lastIndex;
		while ( /\s/.test( source[ cursor ] || '' ) ) {
			cursor += 1;
		}
		const quote = source[ cursor ];
		if ( quote !== "'" && quote !== '"' ) {
			continue;
		}
		let end = cursor + 1;
		let escaped = false;
		for ( ; end < source.length; end += 1 ) {
			const char = source[ end ];
			if ( char === quote && ! escaped ) {
				break;
			}
			escaped = char === '\\' && ! escaped;
			if ( char !== '\\' ) {
				escaped = false;
			}
		}
		if ( end >= source.length ) {
			continue;
		}
		const raw = source.slice( cursor, end + 1 );
		add( decodePhpString( raw ), file, lineAt( source, match.index ), translatorComment( source, match.index ) );
	}
};

const extractJs = ( file ) => {
	const source = fs.readFileSync( path.join( root, file ), 'utf8' );
	const ast = parser.parse( source, { sourceType: 'module', plugins: [ 'jsx' ] } );
	traverse( ast, {
		CallExpression( callPath ) {
			const { node } = callPath;
			if ( node.callee?.type !== 'Identifier' || node.callee.name !== '__' ) {
				return;
			}
			const first = node.arguments[ 0 ];
			const second = node.arguments[ 1 ];
			if (
				first?.type !== 'StringLiteral' ||
				second?.type !== 'StringLiteral' ||
				second.value !== domain
			) {
				return;
			}
			add( first.value, file, node.loc?.start.line || 1, translatorComment( source, node.start || 0 ) );
		},
	} );
};

const walk = ( directory, extensions ) => {
	const output = [];
	for ( const item of fs.readdirSync( path.join( root, directory ), { withFileTypes: true } ) ) {
		const relative = path.posix.join( directory, item.name );
		if ( item.isDirectory() ) {
			output.push( ...walk( relative, extensions ) );
		} else if ( extensions.includes( path.extname( item.name ) ) ) {
			output.push( relative );
		}
	}
	return output;
};

extractPhp( 'coderembassy-order-guard.php' );
walk( 'includes', [ '.php' ] ).forEach( extractPhp );
walk( 'src', [ '.js', '.jsx' ] ).forEach( extractJs );
add( 'CoderEmbassy Order Guard for WooCommerce', 'coderembassy-order-guard.php', 3 );
add( 'API-level protection against card testing, bot orders, and fake WooCommerce checkouts.', 'coderembassy-order-guard.php', 5 );

const pluginSource = fs.readFileSync( path.join( root, 'coderembassy-order-guard.php' ), 'utf8' );
const version = pluginSource.match( /define\(\s*'CEOG_VERSION',\s*'([^']+)'\s*\)/ )?.[ 1 ] || '1.0.0';
const catalog = {
	charset: 'UTF-8',
	headers: {
		'project-id-version': `CoderEmbassy Order Guard for WooCommerce ${ version }`,
		'report-msgid-bugs-to': 'https://coderembassy.com/',
		'pot-creation-date': new Date().toISOString().replace( 'T', ' ' ).replace( /\.\d{3}Z$/, '+0000' ),
		'mime-version': '1.0',
		'content-type': 'text/plain; charset=UTF-8',
		'content-transfer-encoding': '8bit',
		'x-generator': 'CoderEmbassy release tooling',
		'x-domain': domain,
	},
	translations: { '': {} },
};

for ( const msgid of [ ...entries.keys() ].sort( ( left, right ) => left.localeCompare( right ) ) ) {
	const entry = entries.get( msgid );
	catalog.translations[ '' ][ msgid ] = {
		msgid,
		msgstr: [ '' ],
		comments: {
			reference: [ ...entry.references ].sort().join( ' ' ),
			extracted: [ ...entry.comments ].join( ' ' ),
		},
	};
}

const output = gettextParser.po.compile( catalog, { foldLength: 100, sortByMsgid: true } );
const destination = path.join( root, 'languages', 'coderembassy-order-guard.pot' );
fs.mkdirSync( path.dirname( destination ), { recursive: true } );
fs.writeFileSync( destination, output );
process.stdout.write( `Generated ${ path.relative( root, destination ) } with ${ entries.size } strings.\n` );
