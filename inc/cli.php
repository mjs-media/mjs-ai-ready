<?php
/**
 * WP-CLI: `wp ai-ready …`, for running over SSH.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

/**
 * Make a WordPress site easy for search engines and AI search tools to read.
 */
class MJSAIR_CLI {

	/**
	 * Check how ready the site is for search engines and AI search.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json. json is what the report tool reads.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--[no-]fetch]
	 * : Load the homepage, llms.txt and sitemap over HTTP (default). --no-fetch skips that.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-ready audit
	 *     wp ai-ready audit --format=json
	 */
	public function audit( $args, $assoc ) {
		$audit = mjsair_audit_run( \WP_CLI\Utils\get_flag_value( $assoc, 'fetch', true ) );
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			WP_CLI::line( wp_json_encode( $audit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$rows = array_map(
			fn( $c ) => array(
				'status' => strtoupper( $c['status'] ),
				'check'  => $c['label'],
				'detail' => $c['detail'] . ( $c['fix'] && 'pass' !== $c['status'] ? '  → ' . $c['fix'] : '' ),
			),
			$audit['checks']
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'check', 'detail' ) );
		$s = $audit['summary'];
		WP_CLI::line( sprintf( '%d good, %d worth fixing, %d need fixing, %d notes.', $s['pass'], $s['warn'], $s['fail'], $s['info'] ) );
	}

	/**
	 * Fill in the settings from a JSON file (same shape as `wp ai-ready export`).
	 *
	 * Key pages can be IDs, paths ("/about/") or full URLs. Service URLs can be paths.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON file on the server, or - to read from STDIN (handy over SSH: `ssh host wp ai-ready setup - < client.json`).
	 *
	 * [--replace]
	 * : Replace all settings. By default, keys in the file overwrite and the rest are kept.
	 *
	 * [--dry-run]
	 * : Show what would be saved.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-ready setup ai-ready.json
	 *     cat client.json | wp ai-ready setup -
	 */
	public function setup( $args, $assoc ) {
		$raw = '-' === $args[0] ? stream_get_contents( STDIN ) : ( is_readable( $args[0] ) ? file_get_contents( $args[0] ) : false ); // phpcs:ignore
		if ( false === $raw ) {
			WP_CLI::error( 'Cannot read ' . $args[0] );
		}
		$in = json_decode( $raw, true );
		if ( ! is_array( $in ) ) {
			WP_CLI::error( 'Not valid JSON: ' . json_last_error_msg() );
		}
		$base = isset( $assoc['replace'] ) ? array() : mjsair_opt();
		$new  = mjsair_sanitize( array_merge( $base, $in ) );

		foreach ( (array) ( $in['key_pages'] ?? array() ) as $p ) {
			if ( ! mjsair_resolve_page( $p ) ) {
				WP_CLI::warning( 'Key page not found: ' . $p );
			}
		}
		$dropped = array_diff( array_keys( $in ), array_keys( mjsair_defaults() ) );
		if ( $dropped ) {
			WP_CLI::warning( 'Ignored unknown keys: ' . implode( ', ', $dropped ) );
		}
		if ( isset( $assoc['dry-run'] ) ) {
			WP_CLI::line( wp_json_encode( $new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		// Store already-clean data without running the sanitize callback twice.
		remove_filter( 'sanitize_option_' . MJSAIR_OPTION, 'mjsair_sanitize' );
		update_option( MJSAIR_OPTION, $new );
		delete_transient( MJSAIR_LLMS_CACHE );
		WP_CLI::success( sprintf( 'Saved: %d services, %d FAQs, %d key pages, %d contact methods.', count( $new['services'] ), count( $new['faqs'] ), count( $new['key_pages'] ), count( $new['contacts'] ) ) );
	}

	/**
	 * Print the settings as JSON (edit and feed back to `setup`).
	 *
	 * [--paths]
	 * : Key pages as paths instead of IDs, so the file works on another copy of the site.
	 */
	public function export( $args, $assoc ) {
		$o = mjsair_opt();
		if ( isset( $assoc['paths'] ) ) {
			$o['key_pages'] = array_map( fn( $id ) => wp_make_link_relative( get_permalink( $id ) ), $o['key_pages'] );
		}
		WP_CLI::line( wp_json_encode( $o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * One-screen summary of what the plugin is doing on this site.
	 */
	public function status() {
		$o    = mjsair_opt();
		$last = get_option( MJSAIR_INDEXNOW_LOG );
		$seo  = array( 'yoast' => 'Yoast SEO (schema added to its graph)', 'rankmath' => 'Rank Math (schema added to its graph)', 'aioseo' => 'All in One SEO (own schema block printed)', 'seopress' => 'SEOPress (own schema block printed)' );
		$file = file_exists( mjsair_audit_home_path() . 'robots.txt' );
		$rows = array(
			array( 'item' => 'Version', 'value' => MJSAIR_VERSION . ( mjsair_is_dev_copy() ? ' (development copy, no auto-updates)' : '' ) ),
			array( 'item' => 'Site', 'value' => home_url( '/' ) . ' (' . wp_get_environment_type() . ( get_option( 'blog_public' ) ? '' : ', search engines discouraged' ) . ')' ),
			array( 'item' => 'Business', 'value' => $o['name'] . ' — ' . $o['type'] . ( $o['locality'] ? ', ' . $o['locality'] : '' ) ),
			array( 'item' => 'Details', 'value' => sprintf( 'summary %s, %d services, %d FAQs, %d key pages, %d contacts, %d opening-hours lines', $o['summary'] ? 'yes' : 'NO', count( $o['services'] ), count( $o['faqs'] ), count( $o['key_pages'] ), count( $o['contacts'] ), count( $o['hours'] ) ) ),
			array( 'item' => 'robots.txt', 'value' => array( 'allow_all' => 'Allow search and all AI crawlers', 'search_only' => 'Allow search and AI search, block AI training', 'off' => 'Not changed' )[ $o['robots_mode'] ] . ( $file ? ' — BUT a robots.txt file on the server overrides it (wp ai-ready fix-robots)' : '' ) ),
			array( 'item' => 'llms.txt', 'value' => $o['llms'] ? home_url( '/llms.txt' ) : 'Off' ),
			array( 'item' => 'Structured data', 'value' => $o['schema'] ? ( $seo[ mjsair_seo_plugin() ] ?? 'Own JSON-LD block' ) : 'Off' ),
			array( 'item' => 'IndexNow', 'value' => $o['indexnow'] ? 'Key ' . mjsair_indexnow_key_url() . ( mjsair_is_live() ? '' : ' (not sending: not a live production site)' ) . ( is_array( $last ) ? '; last: ' . $last['message'] . ', ' . human_time_diff( $last['time'] ) . ' ago' : '' ) : 'Off' ),
			array( 'item' => 'Verification', 'value' => 'Google ' . ( $o['google_verify'] ? 'set' : '-' ) . ', Bing ' . ( $o['bing_verify'] ? 'set' : '-' ) ),
			array( 'item' => 'Updates', 'value' => mjsair_github_token() ? 'From GitHub (token set)' : 'No GitHub token in wp-config.php: no automatic updates' ),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'value' ) );
	}

	/**
	 * Back up and remove a physical robots.txt so WordPress (and this plugin) control robots.txt.
	 *
	 * The file is copied to robots.txt.bak-<date> next to it first. Any custom
	 * rules in it are listed so you can decide whether they matter.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @subcommand fix-robots
	 */
	public function fix_robots( $args, $assoc ) {
		$file = mjsair_audit_home_path() . 'robots.txt';
		if ( ! file_exists( $file ) ) {
			WP_CLI::success( 'No robots.txt file on the server: WordPress already builds it.' );
			return;
		}
		$old = (string) file_get_contents( $file ); // phpcs:ignore
		WP_CLI::line( "Current robots.txt file:\n----\n" . $old . "\n----" );
		$custom = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $old ) as $line ) {
			$t = trim( $line );
			if ( preg_match( '/^(dis)?allow:\s*(.+)$/i', $t, $m ) && ! preg_match( '#^/?wp-admin/?(admin-ajax\.php)?$#', trim( $m[2] ) ) && '/' !== trim( $m[2] ) ) {
				$custom[] = $t;
			}
		}
		if ( $custom ) {
			WP_CLI::warning( "These rules will no longer apply (WordPress's robots.txt won't have them):\n  " . implode( "\n  ", $custom ) );
		}
		WP_CLI::confirm( 'Back up and remove this file?', $assoc );
		$backup = $file . '.bak-' . gmdate( 'Ymd-His' );
		if ( ! copy( $file, $backup ) ) {
			WP_CLI::error( 'Could not write the backup ' . $backup . ': nothing changed.' );
		}
		if ( ! unlink( $file ) ) { // phpcs:ignore
			WP_CLI::error( 'Backed up to ' . $backup . ' but could not remove ' . $file );
		}
		WP_CLI::success( 'Backed up to ' . basename( $backup ) . ' and removed. To undo: mv ' . escapeshellarg( $backup ) . ' ' . escapeshellarg( $file ) );
		WP_CLI::line( 'If the site uses a cache (LiteSpeed, Cloudflare), purge /robots.txt. New robots.txt:' );
		WP_CLI::line( mjsair_virtual_robots() );
	}

	/**
	 * Print llms.txt as it is now.
	 */
	public function llms() {
		WP_CLI::line( mjsair_build_llms_txt() );
	}

	/**
	 * Send pages to IndexNow now.
	 *
	 * [<url>...]
	 * : Addresses to send.
	 *
	 * [--key-pages]
	 * : Send the homepage, key pages and service pages (e.g. straight after setup).
	 */
	public function indexnow( $args, $assoc ) {
		$urls = $args;
		if ( isset( $assoc['key-pages'] ) ) {
			$urls[] = home_url( '/' );
			foreach ( mjsair_opt( 'key_pages' ) as $id ) {
				$urls[] = get_permalink( $id );
			}
			foreach ( mjsair_opt( 'services' ) as $s ) {
				if ( $s['url'] ) {
					$urls[] = strtok( $s['url'], '#' );
				}
			}
		}
		if ( ! $urls ) {
			WP_CLI::error( 'Give some addresses, or --key-pages.' );
		}
		if ( ! mjsair_is_live() ) {
			WP_CLI::warning( 'This is not a live production site; sending anyway because you asked.' );
		}
		$r = mjsair_indexnow_send( $urls );
		$r['code'] >= 200 && $r['code'] < 300
			? WP_CLI::success( $r['message'] . ' (' . $r['count'] . ' addresses)' )
			: WP_CLI::error( $r['message'] );
	}
}

WP_CLI::add_command( 'ai-ready', 'MJSAIR_CLI' );
