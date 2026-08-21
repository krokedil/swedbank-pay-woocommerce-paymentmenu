/**
 * WordPress Playground dev-environment config for this plugin.
 * Consumed by @krokedil/wp-playground-tools — see its README for the full schema.
 */
export default {
	slug: 'swedbank-pay-payment-menu',
	siteName: 'Swedbank Pay Payment Menu',

	// Claimed in the org port registry (wp-playground-tools README).
	basePort: 8920,

	woocommerce: true,
	store: { country: 'SE', currency: 'SEK', timezone: 'Europe/Stockholm' },

	// wpify-scoper plugin: the bootstrap requires vendor/autoload.php and
	// vendor/dependencies/scoper-autoload.php (see includes/class-swedbank-pay-plugin.php).
	composer: { markers: [ 'vendor/autoload.php', 'vendor/dependencies/scoper-autoload.php' ] },

	// No `build` key: the minified JS/CSS assets are committed, so the gulp
	// build is not needed to boot the playground.

	activate: [ 'swedbank-pay-payment-menu' ],
};
