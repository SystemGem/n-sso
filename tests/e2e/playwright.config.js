// @ts-check
const { defineConfig } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );

const siteDir = process.env.SITE_DIR || path.resolve( __dirname, '../../.e2e-site' );
const env = JSON.parse( fs.readFileSync( path.join( siteDir, 'e2e.json' ), 'utf8' ) );

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: '*.spec.js',
	timeout: 90000,
	workers: 1, // Tests share one WordPress site and one mock N+ state file.
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: env.siteUrl,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	metadata: env,
} );
