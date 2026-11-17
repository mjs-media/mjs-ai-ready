<?php
/**
 * /llms.txt: a plain-text summary of the business for AI tools.
 *
 * llms.txt is a proposed standard (https://llmstxt.org), not one every AI tool
 * reads yet. It's cheap, harmless and gives tools that do read it an accurate,
 * up-to-date summary built from Settings → AI ready plus the site's pages and
 * latest posts.
 *
 * A real llms.txt file in the site root (Yoast can write one) wins over this.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

const MJSAIR_LLMS_CACHE = 'mjsair_llms_txt';

add_action( 'parse_request', function () {
	if ( ! mjsair_opt( 'llms' ) ) {
		return;
	}
	$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	if ( $path !== $home . '/llms.txt' ) {
		return;
	}
	if ( ! get_option( 'blog_public' ) ) {
		status_header( 404 );
		exit;
	}
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Cache-Control: public, max-age=3600' );
	echo mjsair_llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain text.
	exit;
}, 1 );

// Rebuild after content or settings change.
foreach ( array( 'save_post', 'deleted_post', 'update_option_' . MJSAIR_OPTION, 'update_option_blogname' ) as $hook ) {
	add_action( $hook, fn() => delete_transient( MJSAIR_LLMS_CACHE ) );
}

/** Plain text for a line: no tags, no line breaks, decoded entities. */
function mjsair_plain( $text ) {
	$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '/\s+/', ' ', $text ) );
}

/** A page's description: SEO plugin description, then excerpt. */
function mjsair_page_description( $id ) {
	$desc = '';
	if ( defined( 'WPSEO_VERSION' ) ) {
		$desc = (string) get_post_meta( $id, '_yoast_wpseo_metadesc', true );
	}
	if ( ! $desc && defined( 'RANK_MATH_VERSION' ) ) {
		$desc = (string) get_post_meta( $id, 'rank_math_description', true );
	}
	if ( ! $desc ) {
		$desc = (string) get_post_field( 'post_excerpt', $id );
	}
	// Yoast/Rank Math variables such as %%sitename%% mean nothing outside the page.
	return mjsair_plain( preg_replace( '/%%?[a-z_]+%%?/i', '', $desc ) );
}

function mjsair_address_line() {
	$o = mjsair_opt();
	return implode( ', ', array_filter( array( $o['street'], $o['locality'], $o['region'], $o['postcode'] ) ) );
}

/** "Monday to Friday 08:00–17:30" lines. */
function mjsair_hours_lines() {
	$days  = mjsair_days();
	$lines = array();
	foreach ( (array) mjsair_opt( 'hours' ) as $row ) {
		$idx = array_map( fn( $d ) => array_search( $d, $days, true ), $row['days'] );
		sort( $idx );
		$run = count( $idx ) > 2 && end( $idx ) - $idx[0] === count( $idx ) - 1;
		$label = $run ? $days[ $idx[0] ] . ' to ' . $days[ end( $idx ) ] : implode( ', ', array_map( fn( $i ) => $days[ $i ], $idx ) );
		$lines[] = $label . ' ' . $row['opens'] . '–' . $row['closes'];
	}
	return $lines;
}

function mjsair_contact_line( $c ) {
	$types = mjsair_contact_types();
	$label = $c['label'] ?: $types[ $c['type'] ] ?? 'Contact';
	$value = $c['value'];
	if ( in_array( $c['type'], array( 'form', 'booking' ), true ) && 0 === strpos( $value, '/' ) ) {
		$value = home_url( $value );
	}
	return $label . ': ' . $value;
}

function mjsair_llms_txt() {
	$cached = get_transient( MJSAIR_LLMS_CACHE );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}
	$txt = mjsair_build_llms_txt();
	set_transient( MJSAIR_LLMS_CACHE, $txt, HOUR_IN_SECONDS );
	return $txt;
}

function mjsair_build_llms_txt() {
	$o   = mjsair_opt();
	$out = array( '# ' . mjsair_plain( $o['name'] ?: get_bloginfo( 'name' ) ), '' );

	$summary = $o['summary'] ?: get_bloginfo( 'description' );
	if ( $summary ) {
		$out[] = '> ' . mjsair_plain( $summary );
		$out[] = '';
	}

	$facts   = array();
	$facts[] = 'Website: ' . home_url( '/' );
	if ( $o['show_address'] && mjsair_address_line() ) {
		$facts[] = 'Address: ' . mjsair_address_line();
	} elseif ( $o['locality'] ) {
		$facts[] = 'Based in: ' . implode( ', ', array_filter( array( $o['locality'], $o['region'] ) ) );
	}
	if ( mjsair_lines( $o['areas'] ) ) {
		$facts[] = 'Areas served: ' . implode( ', ', mjsair_lines( $o['areas'] ) );
	}
	$hours = mjsair_hours_lines();
	if ( $hours ) {
		$facts[] = 'Opening hours: ' . implode( '; ', $hours ) . ( $o['hours_note'] ? '. ' . $o['hours_note'] : '' );
	} elseif ( $o['hours_note'] ) {
		$facts[] = 'Opening hours: ' . $o['hours_note'];
	}
	foreach ( $o['contacts'] as $c ) {
		$facts[] = mjsair_contact_line( $c );
	}
	foreach ( mjsair_lines( $o['same_as'] ) as $link ) {
		$facts[] = 'Also at: ' . $link;
	}
	foreach ( $facts as $f ) {
		$out[] = '- ' . $f;
	}

	if ( $o['services'] ) {
		$out[] = '';
		$out[] = '## Services';
		foreach ( $o['services'] as $s ) {
			$name  = $s['url'] ? '[' . $s['name'] . '](' . $s['url'] . ')' : $s['name'];
			$out[] = '- ' . $name . ( $s['description'] ? ': ' . $s['description'] : '' );
		}
	}

	$shown = array();
	if ( $o['key_pages'] ) {
		$out[] = '';
		$out[] = '## Key pages';
		foreach ( $o['key_pages'] as $id ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$shown[] = (int) $id;
			$desc    = mjsair_page_description( $id );
			$out[]   = '- [' . mjsair_plain( get_the_title( $id ) ) . '](' . get_permalink( $id ) . ')' . ( $desc ? ': ' . $desc : '' );
		}
	}

	if ( $o['faqs'] ) {
		$out[] = '';
		$out[] = '## Frequently asked questions';
		foreach ( $o['faqs'] as $f ) {
			$out[] = '';
			$out[] = '### ' . mjsair_plain( $f['q'] );
			$out[] = mjsair_plain( $f['a'] );
		}
	}

	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'post__not_in'   => $shown,
			'has_password'   => false,
		)
	);
	$skip  = array( (int) get_option( 'wp_page_for_privacy_policy' ), (int) get_option( 'page_on_front' ) );
	$lines = array();
	foreach ( $pages as $p ) {
		if ( in_array( $p->ID, $skip, true ) || mjsair_is_noindex( $p->ID ) ) {
			continue;
		}
		$desc    = mjsair_page_description( $p->ID );
		$lines[] = '- [' . mjsair_plain( get_the_title( $p ) ) . '](' . get_permalink( $p ) . ')' . ( $desc ? ': ' . $desc : '' );
	}
	if ( $lines ) {
		$out[] = '';
		$out[] = $shown ? '## Other pages' : '## Pages';
		$out   = array_merge( $out, $lines );
	}

	$n = (int) $o['llms_posts'];
	if ( $n > 0 ) {
		$posts = get_posts( array( 'numberposts' => $n, 'post_status' => 'publish', 'has_password' => false ) );
		if ( $posts ) {
			$out[] = '';
			$out[] = '## Latest articles';
			foreach ( $posts as $p ) {
				$out[] = '- [' . mjsair_plain( get_the_title( $p ) ) . '](' . get_permalink( $p ) . ') (' . get_the_date( 'j F Y', $p ) . ')';
			}
		}
	}

	$sitemap = mjsair_sitemap_url();
	if ( $sitemap ) {
		$out[] = '';
		$out[] = '## Optional';
		$out[] = '- [Sitemap](' . $sitemap . ')';
	}
	return implode( "\n", $out ) . "\n";
}

/** Pages marked noindex in Yoast or Rank Math stay out of llms.txt. */
function mjsair_is_noindex( $id ) {
	if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
		return true;
	}
	$rm = get_post_meta( $id, 'rank_math_robots', true );
	return is_array( $rm ) && in_array( 'noindex', $rm, true );
}
