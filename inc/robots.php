<?php
/**
 * robots.txt: let search and AI search crawlers in.
 *
 * WordPress builds robots.txt on the fly, so this only works when there is no
 * real robots.txt file in the site's root folder. If there is one, the web
 * server sends that file and this filter never runs: the admin and the audit
 * say so, and `wp ai-ready fix-robots` backs the file up and removes it.
 *
 * We add to what WordPress and other plugins (Yoast, Rank Math) already put in
 * robots.txt, and leave alone any crawler that already has its own rules there.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'robots_txt', 'mjsair_robots_txt', 99, 2 );

function mjsair_robots_txt( $output, $public ) {
	$mode = mjsair_opt( 'robots_mode' );
	if ( 'off' === $mode || ! $public ) {
		return $output; // "Discourage search engines" is on: WordPress already says Disallow: /.
	}
	$output   = rtrim( (string) $output ) . "\n";
	$existing = array();
	$general  = array(); // the "*" group's rules: a crawler with its own group ignores "*", so repeat them
	foreach ( mjsair_robots_parse( $output ) as $g ) {
		$existing = array_merge( $existing, $g['agents'] );
		if ( in_array( '*', $g['agents'], true ) ) {
			foreach ( $g['rules'] as $r ) {
				if ( '/' !== trim( $r[1] ) && '' !== trim( $r[1] ) ) {
					$general[] = ucfirst( $r[0] ) . ': ' . $r[1];
				}
			}
		}
	}

	$lines = array( '', '# Search and AI search tools are welcome (MJS AI Ready).' );
	if ( 'search_only' === $mode ) {
		$lines[] = '# AI model training crawlers are asked to stay out. This does not affect search or AI search answers.';
	}
	foreach ( mjsair_bots() as $bot => $info ) {
		if ( in_array( strtolower( $bot ), $existing, true ) ) {
			continue;
		}
		$block   = ( 'search_only' === $mode && 'training' === $info['kind'] );
		$lines[] = 'User-agent: ' . $bot;
		if ( $block ) {
			$lines[] = 'Disallow: /';
		} else {
			$lines   = array_merge( $lines, $general );
			$lines[] = 'Allow: /';
		}
		$lines[] = '';
	}

	// Yoast adds its own Sitemap line after us (priority 99999), so leave that to Yoast.
	if ( false === stripos( $output, 'sitemap:' ) && ! defined( 'WPSEO_VERSION' ) ) {
		$sitemap = mjsair_sitemap_url();
		if ( $sitemap ) {
			$lines[] = 'Sitemap: ' . $sitemap;
		}
	}
	return $output . implode( "\n", $lines ) . "\n";
}

/** What WordPress would send for /robots.txt (if there were no physical file). */
function mjsair_virtual_robots() {
	$public = (bool) get_option( 'blog_public' );
	$output = "User-agent: *\n";
	if ( ! $public ) {
		$output .= "Disallow: /\n";
	} else {
		$path    = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		$output .= "Disallow: $path/wp-admin/\n";
		$output .= "Allow: $path/wp-admin/admin-ajax.php\n";
	}
	return (string) apply_filters( 'robots_txt', $output, $public );
}
