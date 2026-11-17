<?php
/**
 * IndexNow: tell Bing (and through it ChatGPT search, Copilot, Yandex, Seznam
 * and Naver) straight away when a page is published or updated. No account.
 *
 * The key file is served by WordPress at /<key>.txt. Pings go out from
 * WP-Cron a few seconds after saving, so editors never wait for them, and
 * only from live, public sites (production environment, search engines allowed).
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

const MJSAIR_INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
const MJSAIR_INDEXNOW_QUEUE    = 'mjsair_indexnow_queue';
const MJSAIR_INDEXNOW_LOG      = 'mjsair_indexnow_last';

function mjsair_indexnow_key() {
	$key = (string) get_option( 'mjsair_indexnow_key', '' );
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
		$key = bin2hex( random_bytes( 16 ) );
		update_option( 'mjsair_indexnow_key', $key, false );
	}
	return $key;
}

function mjsair_indexnow_key_url() {
	return home_url( '/' . mjsair_indexnow_key() . '.txt' );
}

// Serve the key file.
add_action( 'parse_request', function () {
	if ( ! mjsair_opt( 'indexnow' ) ) {
		return;
	}
	$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	if ( $path !== $home . '/' . mjsair_indexnow_key() . '.txt' ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo mjsair_indexnow_key(); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}, 1 );

/** Queue a URL; a single cron event a few seconds later sends the batch. */
function mjsair_indexnow_queue( $url ) {
	$queue   = (array) get_option( MJSAIR_INDEXNOW_QUEUE, array() );
	$queue[] = $url;
	update_option( MJSAIR_INDEXNOW_QUEUE, array_values( array_unique( $queue ) ), false );
	if ( ! wp_next_scheduled( 'mjsair_indexnow_send' ) ) {
		wp_schedule_single_event( time() + 10, 'mjsair_indexnow_send' );
	}
}

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( ! mjsair_opt( 'indexnow' ) || ! mjsair_is_live() || 'publish' !== $new ) {
		return;
	}
	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || post_password_required( $post ) ) {
		return;
	}
	$type = get_post_type_object( $post->post_type );
	if ( ! $type || ! $type->public || 'attachment' === $post->post_type ) {
		return;
	}
	if ( function_exists( 'mjsair_is_noindex' ) && mjsair_is_noindex( $post->ID ) ) {
		return;
	}
	mjsair_indexnow_queue( get_permalink( $post ) );
}, 10, 3 );

// A page taken down: tell them too, so it drops out sooner.
add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'publish' === $old && 'publish' !== $new && mjsair_opt( 'indexnow' ) && mjsair_is_live() && is_post_type_viewable( $post->post_type ) ) {
		mjsair_indexnow_queue( get_permalink( $post ) );
	}
}, 10, 3 );

add_action( 'mjsair_indexnow_send', 'mjsair_indexnow_send' );

/**
 * Send queued URLs (or the ones given). Returns array( code, message, count ).
 */
function mjsair_indexnow_send( $urls = null ) {
	if ( null === $urls ) {
		$urls = (array) get_option( MJSAIR_INDEXNOW_QUEUE, array() );
		delete_option( MJSAIR_INDEXNOW_QUEUE );
	}
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$urls = array_values( array_filter( array_unique( (array) $urls ), fn( $u ) => wp_parse_url( $u, PHP_URL_HOST ) === $host ) );
	if ( ! $urls ) {
		return array( 'code' => 0, 'message' => 'Nothing to send', 'count' => 0 );
	}
	$res  = wp_remote_post(
		MJSAIR_INDEXNOW_ENDPOINT,
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode(
				array(
					'host'        => $host,
					'key'         => mjsair_indexnow_key(),
					'keyLocation' => mjsair_indexnow_key_url(),
					'urlList'     => array_slice( $urls, 0, 10000 ),
				)
			),
		)
	);
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	// 200 = received, 202 = received, key still being checked. 403 = key file not reachable, 422 = URL not on this host.
	$messages = array(
		200 => 'Received',
		202 => 'Received (key being checked)',
		400 => 'Bad request',
		403 => 'Key not accepted: is the key file reachable?',
		422 => 'URLs do not belong to this site',
		429 => 'Too many requests',
	);
	$log = array(
		'time'    => time(),
		'code'    => $code,
		'message' => is_wp_error( $res ) ? $res->get_error_message() : ( $messages[ $code ] ?? 'HTTP ' . $code ),
		'count'   => count( $urls ),
		'urls'    => array_slice( $urls, 0, 5 ),
	);
	update_option( MJSAIR_INDEXNOW_LOG, $log, false );
	return $log;
}
