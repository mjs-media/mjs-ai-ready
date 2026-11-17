<?php
/**
 * Plugin Name: MJS AI Ready
 * Description: Helps search engines and AI search tools (ChatGPT, Claude, Perplexity, Google, Bing) read and describe the business correctly: crawler-friendly robots.txt, /llms.txt, LocalBusiness, Service and FAQ structured data, IndexNow and Search Console / Bing verification. Settings → AI ready. Updates itself from GitHub.
 * Version: 1.0.0
 * Author: MJS Media
 * Author URI: https://www.mjsmedia.co.uk/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: mjs-ai-ready
 * Update URI: https://github.com/mjs-media/mjs-ai-ready
 *
 * Works on any WordPress site (block themes, Divi, Elementor, Yoast, Rank Math).
 * It does not need the MJS Client Kit.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

define( 'MJSAIR_VERSION', '1.0.0' );
define( 'MJSAIR_DIR', __DIR__ );
define( 'MJSAIR_FILE', __FILE__ );
define( 'MJSAIR_URL', plugin_dir_url( __FILE__ ) );

require MJSAIR_DIR . '/inc/bots.php';
require MJSAIR_DIR . '/inc/options.php';
require MJSAIR_DIR . '/inc/robots.php';
require MJSAIR_DIR . '/inc/llms.php';
require MJSAIR_DIR . '/inc/schema.php';
require MJSAIR_DIR . '/inc/faq.php';
require MJSAIR_DIR . '/inc/head.php';
require MJSAIR_DIR . '/inc/indexnow.php';
require MJSAIR_DIR . '/inc/audit.php';
require MJSAIR_DIR . '/inc/admin.php';
require MJSAIR_DIR . '/inc/updater.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require MJSAIR_DIR . '/inc/cli.php';
}

register_activation_hook( __FILE__, 'mjsair_activate' );
function mjsair_activate() {
	mjsair_indexnow_key(); // create the key now so the key file works straight away
}
