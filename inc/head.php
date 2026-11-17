<?php
/**
 * <head> tags: Google Search Console and Bing Webmaster Tools verification,
 * and (optional) a homepage meta description from the summary.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', function () {
	if ( ! is_front_page() ) {
		return;
	}
	$o = mjsair_opt();
	if ( $o['google_verify'] ) {
		echo '<meta name="google-site-verification" content="' . esc_attr( $o['google_verify'] ) . '">' . "\n";
	}
	if ( $o['bing_verify'] ) {
		echo '<meta name="msvalidate.01" content="' . esc_attr( $o['bing_verify'] ) . '">' . "\n";
	}
	// Only when no SEO plugin is handling descriptions, and only if switched on
	// (the theme might already print one; the audit tells you if it's missing).
	if ( $o['home_description'] && ! mjsair_seo_plugin() && $o['summary'] ) {
		echo '<meta name="description" content="' . esc_attr( wp_html_excerpt( mjsair_plain( $o['summary'] ), 160, '…' ) ) . '">' . "\n";
	}
}, 1 );
