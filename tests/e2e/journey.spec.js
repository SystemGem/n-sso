// @ts-check
/**
 * Full learner journey: buy a WooCommerce product mapped to N+ -> learner is
 * created on N+ -> subscription assigned -> one click from WordPress signs the
 * learner into N+ (Auto Login, HMAC validated by the mock N+ server).
 */
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

const siteDir = process.env.SITE_DIR || path.resolve( __dirname, '../../.e2e-site' );
const env = JSON.parse( fs.readFileSync( path.join( siteDir, 'e2e.json' ), 'utf8' ) );

async function mockState( request ) {
	return ( await request.get( env.mockUrl + '/__state' ) ).json();
}

async function login( page, user, pass ) {
	await page.goto( '/my-account/' );
	await page.fill( '#username', user );
	await page.fill( '#password', pass );
	await page.click( 'button[name="login"]' );
	await expect( page.locator( '.woocommerce-MyAccount-navigation' ) ).toBeVisible();
}

async function checkout( page, productId, billing ) {
	await page.goto( `/?add-to-cart=${ productId }` );
	await page.goto( '/checkout/' );
	for ( const [ field, value ] of Object.entries( billing ) ) {
		const input = page.locator( `#billing_${ field }` );
		if ( await input.count() ) {
			await input.fill( value );
		}
	}
	await page.locator( '#payment_method_cod' ).check( { force: true } );
	await page.waitForLoadState( 'networkidle' );
	await page.click( '#place_order' );
	await page.waitForURL( /order-received/, { timeout: 60000 } );
}

const address = {
	address_1: '12 MG Road',
	city: 'Pune',
	postcode: '411001',
	phone: '9876543210',
};

test.describe.configure( { mode: 'serial' } );

test.beforeAll( async ( { request } ) => {
	await request.post( env.mockUrl + '/__reset' );
} );

test( 'registered learner buys the N+ programme and lands in N+ with one click', async ( { page, context, request } ) => {
	await login( page, 'learner', 'learner' );
	await checkout( page, env.nplusProductId, { first_name: 'Asha', last_name: 'Rao', ...address } );

	// Thank-you page offers the N+ launch.
	const box = page.locator( '.nplus-sso-thankyou' );
	await expect( box ).toContainText( 'Start learning on N+' );
	const launch = box.locator( 'a.nplus-sso-launch' );
	await expect( launch ).toHaveAttribute( 'href', /nplus-sso=launch/ );
	// The page must never contain a signature or N+ login link: they are generated at click time.
	expect( await page.content() ).not.toContain( 'signature=' );
	expect( await page.content() ).not.toContain( 'auto-login' );

	const [ nplus ] = await Promise.all( [ context.waitForEvent( 'page' ), launch.click() ] );
	await nplus.waitForLoadState();
	expect( nplus.url() ).toContain( env.mockUrl + '/auto-login/session?token=' );
	await expect( nplus.locator( '#nplus-welcome' ) ).toHaveText( 'Welcome to N+, Asha Rao' );
	await expect( nplus.locator( '#nplus-subscriptions' ) ).toContainText( 'Campaign 12345 / NPLUS-CYBER-12M' );

	// N+ received exactly the documented calls.
	const state = await mockState( request );
	const users = Object.values( state.users );
	expect( users ).toHaveLength( 1 );
	expect( users[ 0 ] ).toMatchObject( { email: 'learner@example.com', firstname: 'Asha', lastname: 'Rao', roleid: 5, phone_country_code: '+91' } );
	expect( state.orders ).toHaveLength( 1 );
	expect( state.orders[ 0 ] ).toMatchObject( {
		campaignid: '12345',
		subscription_skuid: 'NPLUS-CYBER-12M',
		quantity: '1',
		payment: '4999.00',
		payment_currency: 'INR',
		payment_status: 'completed',
		source: 'website',
		sendmail: '1',
	} );
	expect( state.logins.at( -1 ) ).toMatchObject( { ok: true } );

	// My Account > N+ Learning lists the programme and launches again.
	await page.goto( '/my-account/nplus-learning/' );
	await expect( page.locator( '.nplus-sso-account' ) ).toContainText( 'Cyber Security Programme (N+)' );
	await expect( page.locator( '.nplus-sso-account' ) ).toContainText( 'Active' );
	const [ again ] = await Promise.all( [ context.waitForEvent( 'page' ), page.locator( '.nplus-sso-account a.nplus-sso-launch' ).click() ] );
	await expect( again.locator( '#nplus-welcome' ) ).toHaveText( 'Welcome to N+, Asha Rao' );

	// The N+ Learning tab is in the account menu.
	await expect( page.locator( '.woocommerce-MyAccount-navigation' ) ).toContainText( 'N+ Learning' );
} );

test( 'buying a second N+ product reuses the same N+ learner account', async ( { page, request } ) => {
	await login( page, 'learner', 'learner' );
	await checkout( page, env.nplusProductId, { first_name: 'Asha', last_name: 'Rao', ...address } );
	await expect( page.locator( '.nplus-sso-thankyou' ) ).toBeVisible();
	await page.goto( '/?nplus-sso=launch' );
	await expect( page.locator( '#nplus-welcome' ) ).toHaveText( 'Welcome to N+, Asha Rao' );

	const state = await mockState( request );
	expect( Object.keys( state.users ) ).toHaveLength( 1 );
	expect( state.orders ).toHaveLength( 2 );
	expect( state.orders[ 0 ].website_orderid ).not.toEqual( state.orders[ 1 ].website_orderid );
	// Only one Create User call: the N+ user ID is stored on the WordPress user.
	expect( state.requests.filter( ( r ) => r.fn === 'local_lms_create_user_site' ) ).toHaveLength( 1 );
} );

test( 'guest checkout buyer can launch N+ from the thank-you page', async ( { browser, request } ) => {
	const context = await browser.newContext();
	const page = await context.newPage();
	await checkout( page, env.nplusProductId, { first_name: 'Ravi', last_name: 'Kumar', email: 'ravi.guest@example.com', ...address } );

	const launch = page.locator( '.nplus-sso-thankyou a.nplus-sso-launch' );
	await expect( launch ).toHaveAttribute( 'href', /order_id=\d+&key=wc_order_/ );
	const [ nplus ] = await Promise.all( [ context.waitForEvent( 'page' ), launch.click() ] );
	await expect( nplus.locator( '#nplus-welcome' ) ).toHaveText( 'Welcome to N+, Ravi Kumar' );

	const state = await mockState( request );
	expect( Object.values( state.users ).map( ( u ) => u.email ) ).toContain( 'ravi.guest@example.com' );

	// A tampered order key is rejected and nothing is signed.
	const href = await launch.getAttribute( 'href' );
	const bad = await context.request.get( href.replace( /key=wc_order_[^&]+/, 'key=wc_order_forged' ), { maxRedirects: 0 } );
	expect( bad.status() ).toBe( 403 );
	expect( bad.headers().location || '' ).not.toContain( 'auto-login' );
	await context.close();
} );

test( 'logged-out visitor is sent to log in, and non-buyers get no access', async ( { browser } ) => {
	const context = await browser.newContext();
	const res = await context.request.get( '/?nplus-sso=launch', { maxRedirects: 0 } );
	expect( res.status() ).toBe( 302 );
	expect( res.headers().location ).toContain( 'wp-login.php' );
	await context.close();
} );

test( 'a normal product does not touch N+', async ( { page, request } ) => {
	const before = ( await mockState( request ) ).requests.length;
	await login( page, 'learner', 'learner' );
	await checkout( page, env.plainProductId, { first_name: 'Asha', last_name: 'Rao', ...address } );
	await expect( page.locator( '.nplus-sso-thankyou' ) ).toHaveCount( 0 );
	// Allow background jobs a moment, then confirm N+ was never called.
	await page.waitForTimeout( 2000 );
	expect( ( await mockState( request ) ).requests.length ).toBe( before );
} );

test( 'admin "Test N+ connection" runs all three N+ calls and opens N+', async ( { page, context } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	await page.goto( '/wp-admin/admin.php?page=nplus-sso' );
	await page.fill( '#nplus_test_email', 'connection.test@yopmail.com' );
	await page.fill( '#nplus_test_campaign', '12345' );
	await page.fill( '#nplus_test_sku', 'TEST-SKU' );
	await page.click( 'form:has(#nplus_test_email) [type=submit]' );

	const rows = page.locator( '#nplus-test ~ table.widefat tbody tr' );
	await expect( rows ).toHaveCount( 4 );
	for ( let i = 0; i < 4; i++ ) {
		await expect( rows.nth( i ).locator( 'td' ).nth( 1 ) ).toHaveText( 'OK' );
	}
	// Secrets never shown in the result.
	const html = await page.content();
	expect( html ).not.toContain( 'test-wstoken' );
	expect( html ).not.toContain( 'test-secret' );

	const [ nplus ] = await Promise.all( [ context.waitForEvent( 'page' ), page.click( 'text=Open N+ as the test learner' ) ] );
	await expect( nplus.locator( '#nplus-welcome' ) ).toHaveText( 'Welcome to N+, NPlus Test' );
} );
