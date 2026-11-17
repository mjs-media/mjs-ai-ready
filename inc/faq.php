<?php
/**
 * FAQs on the page: the "AI ready FAQs" block and the [ai_ready_faq] shortcode.
 *
 * Both show the questions from Settings → AI ready (all of them, or a chosen
 * few with ids="1,3,4"), and the page gets matching FAQPage structured data.
 * Google only wants FAQ structured data for questions people can see on the
 * page, which is why it's tied to the block/shortcode rather than added everywhere.
 *
 * Works in the block editor, Classic editor, Divi (Code/Text module) and
 * Elementor (Shortcode widget).
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['mjsair_faq_rendered'] = array(); // FAQ indexes shown on this page
$GLOBALS['mjsair_faq_in_head']  = false;   // FAQPage already in the head graph

/** FAQ rows for an ids attribute ("" = all; "1,3" = the 1st and 3rd). */
function mjsair_faqs_for( $ids = '' ) {
	$all = array_values( (array) mjsair_opt( 'faqs' ) );
	$ids = array_filter( array_map( 'intval', explode( ',', (string) $ids ) ) );
	if ( ! $ids ) {
		return $all;
	}
	$out = array();
	foreach ( $ids as $n ) {
		if ( isset( $all[ $n - 1 ] ) ) {
			$out[ $n - 1 ] = $all[ $n - 1 ];
		}
	}
	return $out;
}

function mjsair_faq_render( $atts = array() ) {
	$atts = shortcode_atts( array( 'ids' => '', 'title' => '' ), (array) $atts, 'ai_ready_faq' );
	$faqs = mjsair_faqs_for( $atts['ids'] );
	if ( ! $faqs ) {
		return current_user_can( 'manage_options' ) ? '<p><em>No FAQs yet: add them on Settings → AI ready.</em></p>' : '';
	}
	$GLOBALS['mjsair_faq_rendered'] = array_unique( array_merge( $GLOBALS['mjsair_faq_rendered'], array_keys( $faqs ) ) );

	static $styled = false;
	$html = '';
	if ( ! $styled ) {
		$styled = true;
		$html  .= '<style>.mjsair-faq details{border-bottom:1px solid rgba(127,127,127,.3);padding:.9em 0}.mjsair-faq summary{cursor:pointer;font-weight:600;list-style-position:outside}.mjsair-faq .mjsair-faq__a{margin-top:.6em}.mjsair-faq .mjsair-faq__a>*:last-child{margin-bottom:0}</style>';
	}
	$html .= '<div class="mjsair-faq">';
	if ( '' !== $atts['title'] ) {
		$html .= '<h2 class="mjsair-faq__title">' . esc_html( $atts['title'] ) . '</h2>';
	}
	foreach ( $faqs as $f ) {
		$html .= '<details class="mjsair-faq__item"><summary class="mjsair-faq__q">' . esc_html( $f['q'] ) . '</summary><div class="mjsair-faq__a">' . wpautop( wp_kses_post( $f['a'] ) ) . '</div></details>';
	}
	return $html . '</div>';
}

add_shortcode( 'ai_ready_faq', 'mjsair_faq_render' );

add_action( 'init', function () {
	wp_register_script(
		'mjsair-faq-block',
		MJSAIR_URL . 'assets/faq-block.js',
		array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ),
		MJSAIR_VERSION,
		true
	);
	register_block_type(
		'mjs-ai-ready/faq',
		array(
			'api_version'     => 2,
			'editor_script'   => 'mjsair-faq-block',
			'render_callback' => fn( $attrs ) => mjsair_faq_render( $attrs ),
			'attributes'      => array(
				'ids'   => array( 'type' => 'string', 'default' => '' ),
				'title' => array( 'type' => 'string', 'default' => '' ),
			),
		)
	);
} );

/** Which FAQs does the current page show? Read from the content before it renders, so the head can include them. */
function mjsair_faq_indexes_in_content( $post_id ) {
	$sources = array( (string) get_post_field( 'post_content', $post_id ) );
	$el      = get_post_meta( $post_id, '_elementor_data', true );
	if ( is_string( $el ) ) {
		$sources[] = wp_unslash( $el );
	}
	$found = array();
	foreach ( $sources as $content ) {
		if ( false === strpos( $content, 'ai_ready_faq' ) && false === strpos( $content, 'mjs-ai-ready/faq' ) ) {
			continue;
		}
		if ( preg_match_all( '/\[ai_ready_faq([^\]]*)\]/', $content, $m ) ) {
			foreach ( $m[1] as $attr_text ) {
				$atts  = shortcode_parse_atts( stripslashes( $attr_text ) );
				$found = array_merge( $found, array_keys( mjsair_faqs_for( is_array( $atts ) ? ( $atts['ids'] ?? '' ) : '' ) ) );
			}
		}
		if ( preg_match_all( '/<!-- wp:mjs-ai-ready\/faq(\s+(\{.*?\}))?\s*\/?-->/', $content, $m ) ) {
			foreach ( $m[2] as $json ) {
				$attrs = $json ? json_decode( $json, true ) : array();
				$found = array_merge( $found, array_keys( mjsair_faqs_for( $attrs['ids'] ?? '' ) ) );
			}
		}
	}
	return array_values( array_unique( $found ) );
}

function mjsair_faq_node( $indexes ) {
	$all       = array_values( (array) mjsair_opt( 'faqs' ) );
	$questions = array();
	foreach ( $indexes as $i ) {
		if ( ! isset( $all[ $i ] ) ) {
			continue;
		}
		$questions[] = array(
			'@type'          => 'Question',
			'name'           => $all[ $i ]['q'],
			'acceptedAnswer' => array( '@type' => 'Answer', 'text' => mjsair_plain( $all[ $i ]['a'] ) ),
		);
	}
	if ( ! $questions ) {
		return null;
	}
	return array(
		'@type'      => 'FAQPage',
		'@id'        => ( mjsair_current_url() ?: home_url( '/' ) ) . '#faq',
		'mainEntity' => $questions,
	);
}

function mjsair_faq_node_for_current_page() {
	if ( ! is_singular() ) {
		return null;
	}
	$node = mjsair_faq_node( mjsair_faq_indexes_in_content( get_queried_object_id() ) );
	if ( $node ) {
		$GLOBALS['mjsair_faq_in_head'] = true;
	}
	return $node;
}

// FAQs that appeared some other way (a widget, a template, a page builder we couldn't read): add their FAQPage in the footer.
add_action( 'wp_footer', function () {
	if ( $GLOBALS['mjsair_faq_in_head'] || ! $GLOBALS['mjsair_faq_rendered'] || ! mjsair_opt( 'schema' ) ) {
		return;
	}
	$node = mjsair_faq_node( $GLOBALS['mjsair_faq_rendered'] );
	if ( $node ) {
		echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org' ) + $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
} );
