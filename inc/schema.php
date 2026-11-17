<?php
/**
 * Structured data (schema.org JSON-LD):
 *   - the business (LocalBusiness or a subtype), with @id home_url('/#business'), on every page
 *   - a Service for each service: all on the homepage, and each on its own page
 *   - FAQPage on any page showing the FAQ block or [ai_ready_faq] shortcode
 *
 * With Yoast or Rank Math active the nodes go into their graph (one JSON-LD
 * block, cross-linked) instead of a second script tag. With no SEO plugin,
 * or one we don't integrate with, we print our own script tag.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

function mjsair_business_id() {
	return home_url( '/#business' );
}

function mjsair_business_node() {
	$o    = mjsair_opt();
	$node = array(
		'@type' => $o['type'] ?: 'LocalBusiness',
		'@id'   => mjsair_business_id(),
		'name'  => $o['name'] ?: get_bloginfo( 'name' ),
		'url'   => home_url( '/' ),
	);
	if ( $o['summary'] ) {
		$node['description'] = mjsair_plain( $o['summary'] );
	}
	$logo = $o['logo'];
	if ( ! $logo && has_custom_logo() ) {
		$logo = wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' );
	}
	if ( ! $logo && get_site_icon_url() ) {
		$logo = get_site_icon_url( 512 );
	}
	if ( $logo ) {
		$node['logo']  = $logo;
		$node['image'] = $logo;
	}
	if ( $o['show_address'] && ( $o['street'] || $o['postcode'] ) ) {
		$node['address'] = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $o['street'],
				'addressLocality' => $o['locality'],
				'addressRegion'   => $o['region'],
				'postalCode'      => $o['postcode'],
				'addressCountry'  => $o['country'],
			)
		);
	} elseif ( $o['locality'] ) {
		$node['address'] = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'addressLocality' => $o['locality'],
				'addressRegion'   => $o['region'],
				'addressCountry'  => $o['country'],
			)
		);
	}
	$areas = mjsair_lines( $o['areas'] );
	if ( $areas ) {
		$node['areaServed'] = array_map( fn( $a ) => array( '@type' => 'Place', 'name' => $a ), $areas );
	}
	foreach ( $o['contacts'] as $c ) {
		if ( 'phone' === $c['type'] && empty( $node['telephone'] ) ) {
			$node['telephone'] = $c['value'];
		} elseif ( 'email' === $c['type'] && empty( $node['email'] ) ) {
			$node['email'] = $c['value'];
		}
	}
	$hours = array();
	foreach ( $o['hours'] as $row ) {
		$hours[] = array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => $row['days'],
			'opens'     => $row['opens'],
			'closes'    => $row['closes'],
		);
	}
	if ( $hours && ! in_array( $node['@type'], array( 'Organization', 'NGO' ), true ) ) {
		$node['openingHoursSpecification'] = $hours;
	}
	$same = mjsair_lines( $o['same_as'] );
	if ( $same ) {
		$node['sameAs'] = $same;
	}
	if ( $o['services'] ) {
		$node['hasOfferCatalog'] = array(
			'@type'           => 'OfferCatalog',
			'name'            => 'Services',
			'itemListElement' => array_map(
				fn( $s ) => array( '@type' => 'Offer', 'itemOffered' => array( '@id' => mjsair_service_id( $s ) ) ),
				$o['services']
			),
		);
	}
	return apply_filters( 'mjsair_business_node', $node );
}

function mjsair_service_id( $s ) {
	return ( $s['url'] ?: home_url( '/' ) ) . '#service-' . sanitize_title( $s['name'] );
}

function mjsair_service_node( $s ) {
	$o    = mjsair_opt();
	$node = array(
		'@type'       => 'Service',
		'@id'         => mjsair_service_id( $s ),
		'name'        => $s['name'],
		'serviceType' => $s['name'],
		'provider'    => array( '@id' => mjsair_business_id() ),
	);
	if ( $s['description'] ) {
		$node['description'] = $s['description'];
	}
	if ( $s['url'] ) {
		$node['url'] = $s['url'];
	}
	$areas = mjsair_lines( $o['areas'] );
	if ( $areas ) {
		$node['areaServed'] = array_map( fn( $a ) => array( '@type' => 'Place', 'name' => $a ), $areas );
	}
	return $node;
}

/** Current page URL without query string, for matching service URLs. */
function mjsair_current_url() {
	if ( is_front_page() ) {
		return home_url( '/' );
	}
	if ( is_singular() ) {
		return get_permalink();
	}
	return '';
}

/** Our nodes for the current page. */
function mjsair_graph_nodes() {
	if ( ! mjsair_opt( 'schema' ) ) {
		return array();
	}
	$o     = mjsair_opt();
	$nodes = array( mjsair_business_node() );
	$here  = untrailingslashit( mjsair_current_url() );
	foreach ( $o['services'] as $s ) {
		if ( is_front_page() || ( $here && $s['url'] && untrailingslashit( strtok( $s['url'], '#' ) ) === $here ) ) {
			$nodes[] = mjsair_service_node( $s );
		}
	}
	$faq = mjsair_faq_node_for_current_page();
	if ( $faq ) {
		$nodes[] = $faq;
	}
	return apply_filters( 'mjsair_graph_nodes', $nodes );
}

/* --------------------------------------------------------------------------
 * Output.
 * ----------------------------------------------------------------------- */

// Yoast: add to its graph, and point its WebSite publisher at our business.
add_filter( 'wpseo_schema_graph', function ( $graph ) {
	if ( ! is_array( $graph ) ) {
		return $graph;
	}
	$ours = mjsair_graph_nodes();
	if ( ! $ours ) {
		return $graph;
	}
	foreach ( $graph as $i => $node ) {
		$types = (array) ( $node['@type'] ?? array() );
		// Yoast's own FAQ block already makes a FAQPage piece; don't add a second one.
		if ( in_array( 'FAQPage', $types, true ) ) {
			$ours = array_values( array_filter( $ours, fn( $n ) => 'FAQPage' !== $n['@type'] ) );
		}
	}
	return array_merge( $graph, $ours );
}, 20 );

// Rank Math: add to its @graph.
add_filter( 'rank_math/json_ld', function ( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	foreach ( mjsair_graph_nodes() as $i => $node ) {
		$key = 'mjsair_' . strtolower( $node['@type'] ) . '_' . $i;
		if ( 'FAQPage' === $node['@type'] ) {
			foreach ( $data as $existing ) {
				if ( is_array( $existing ) && in_array( 'FAQPage', (array) ( $existing['@type'] ?? array() ), true ) ) {
					continue 2;
				}
			}
		}
		$data[ $key ] = $node;
	}
	return $data;
}, 99 );

// Everything else: our own script tag.
add_action( 'wp_head', function () {
	if ( in_array( mjsair_seo_plugin(), array( 'yoast', 'rankmath' ), true ) ) {
		return;
	}
	$nodes = mjsair_graph_nodes();
	if ( ! $nodes ) {
		return;
	}
	echo "\n<!-- MJS AI Ready -->\n<script type=\"application/ld+json\">" . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $nodes ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}, 20 );
