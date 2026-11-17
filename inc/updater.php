<?php
/**
 * Updates from GitHub.
 *
 * Each site checks github.com/mjs-media/mjs-ai-ready for the latest release
 * (or, if there are no releases, the highest vX.Y.Z tag) and offers it on
 * Dashboard → Updates and the Plugins screen like any other plugin. The plugin is
 * opted into WordPress auto-updates, so sites update themselves within a day.
 *
 * Releases carry a built mjs-ai-ready.zip (bin/build-zip), which is what sites
 * install. The repo is public, so no token is needed. A token in wp-config.php
 * (MJSAIR_GITHUB_TOKEN, or the Client Kit's MJSCK_GITHUB_TOKEN) is still sent if
 * present, which only matters if the repo ever goes private again; when GitHub
 * refuses it, the request is retried without it.
 *
 * The plugin header's `Update URI: https://github.com/mjs-media/mjs-ai-ready`
 * keeps WordPress.org from ever matching a plugin of the same name, and makes
 * WordPress ask us through the `update_plugins_github.com` filter.
 *
 * Copies with a .git folder or a symlinked plugin folder (local development)
 * are never offered updates, so an update can't overwrite the working repo.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

const MJSAIR_REPO      = 'mjs-media/mjs-ai-ready';
const MJSAIR_SLUG      = 'mjs-ai-ready';
const MJSAIR_CACHE_KEY = 'mjsair_github_release';

/** "mjs-ai-ready/mjs-ai-ready.php" */
function mjsair_basename() {
	return plugin_basename( MJSAIR_FILE );
}

/** Git checkout or symlink: development copy, leave it alone. */
function mjsair_is_dev_copy() {
	return is_dir( MJSAIR_DIR . '/.git' ) || is_link( WP_PLUGIN_DIR . '/' . MJSAIR_SLUG );
}

function mjsair_github_token() {
	if ( defined( 'MJSAIR_GITHUB_TOKEN' ) ) {
		return (string) MJSAIR_GITHUB_TOKEN;
	}
	return defined( 'MJSCK_GITHUB_TOKEN' ) ? (string) MJSCK_GITHUB_TOKEN : '';
}

/** GET from the GitHub API, with the token if there is one. */
function mjsair_github_get( $path, $args = array(), $accept = 'application/vnd.github+json' ) {
	$headers = array(
		'Accept'               => $accept,
		'X-GitHub-Api-Version' => '2022-11-28',
	);
	$url  = 'https://api.github.com/' . ltrim( $path, '/' );
	$args = array_merge( array( 'timeout' => 10 ), $args );
	if ( mjsair_github_token() ) {
		$res = wp_remote_get( $url, array_merge( $args, array( 'headers' => $headers + array( 'Authorization' => 'Bearer ' . mjsair_github_token() ) ) ) );
		// A token without access to this repo (or an expired one) mustn't block public updates.
		if ( ! in_array( wp_remote_retrieve_response_code( $res ), array( 401, 403, 404 ), true ) ) {
			return $res;
		}
	}
	return wp_remote_get( $url, array_merge( $args, array( 'headers' => $headers ) ) );
}

/**
 * The newest release: array( version, package, url, notes, date ), or null.
 * Cached for 6 hours (1 hour after a failure); "Check again" on
 * Dashboard → Updates refreshes it.
 */
function mjsair_latest_release( $force = false ) {
	$cached = get_site_transient( MJSAIR_CACHE_KEY );
	if ( ! $force && is_array( $cached ) ) {
		return $cached['release'];
	}

	$release = null;
	$res     = mjsair_github_get( 'repos/' . MJSAIR_REPO . '/releases/latest' );
	$code    = wp_remote_retrieve_response_code( $res );
	if ( 200 === $code ) {
		$r = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! empty( $r['tag_name'] ) && ! empty( $r['zipball_url'] ) ) {
			// Prefer the built mjs-ai-ready.zip attached to the release (plugin files only);
			// fall back to GitHub's source zip, which also carries the Mac report tool.
			$package = $r['zipball_url'];
			foreach ( (array) ( $r['assets'] ?? array() ) as $asset ) {
				if ( MJSAIR_SLUG . '.zip' === ( $asset['name'] ?? '' ) && ! empty( $asset['url'] ) ) {
					$package = $asset['url'];
				}
			}
			$release = array(
				'version' => ltrim( $r['tag_name'], 'vV' ),
				'package' => $package,
				'url'     => $r['html_url'] ?? 'https://github.com/' . MJSAIR_REPO,
				'notes'   => (string) ( $r['body'] ?? '' ),
				'date'    => (string) ( $r['published_at'] ?? '' ),
			);
		}
	} elseif ( 404 === $code ) {
		// No releases yet (or no access): fall back to the highest vX.Y.Z tag.
		$res = mjsair_github_get( 'repos/' . MJSAIR_REPO . '/tags?per_page=100' );
		if ( 200 === wp_remote_retrieve_response_code( $res ) ) {
			foreach ( (array) json_decode( wp_remote_retrieve_body( $res ), true ) as $t ) {
				if ( empty( $t['name'] ) || ! preg_match( '/^v?\d+\.\d+(\.\d+)?$/i', $t['name'] ) ) {
					continue;
				}
				$v = ltrim( $t['name'], 'vV' );
				if ( ! $release || version_compare( $v, $release['version'], '>' ) ) {
					$release = array(
						'version' => $v,
						'package' => $t['zipball_url'],
						'url'     => 'https://github.com/' . MJSAIR_REPO . '/releases/tag/' . rawurlencode( $t['name'] ),
						'notes'   => '',
						'date'    => '',
					);
				}
			}
		}
	}

	set_site_transient( MJSAIR_CACHE_KEY, array( 'release' => $release ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $release;
}

/** Tell WordPress about the latest version (it compares versions itself). */
add_filter( 'update_plugins_github.com', function ( $update, $plugin_data, $plugin_file ) {
	if ( mjsair_basename() !== $plugin_file || mjsair_is_dev_copy() ) {
		return $update;
	}
	$release = mjsair_latest_release( is_admin() && ! empty( $_GET['force-check'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $release ) {
		return $update;
	}
	return array(
		'id'           => 'github.com/' . MJSAIR_REPO,
		'slug'         => MJSAIR_SLUG,
		'version'      => $release['version'],
		'url'          => 'https://github.com/' . MJSAIR_REPO,
		'package'      => $release['package'],
		'requires_php' => $plugin_data['RequiresPHP'] ?? '',
		'autoupdate'   => true,
	);
}, 10, 3 );

/** Always auto-update the plugin (it's our plugin, released deliberately). */
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	return ( isset( $item->plugin ) && mjsair_basename() === $item->plugin ) ? true : $update;
}, 10, 2 );

/** "View details" on the Plugins and Updates screens. */
add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || MJSAIR_SLUG !== $args->slug ) {
		return $result;
	}
	$release = mjsair_latest_release();
	$data    = get_plugin_data( MJSAIR_FILE, false, false );
	$notes   = $release && '' !== $release['notes']
		? wpautop( esc_html( $release['notes'] ) )
		: '<p>See the <a href="' . esc_url( $release['url'] ?? 'https://github.com/' . MJSAIR_REPO ) . '">release on GitHub</a>.</p>';

	return (object) array(
		'name'          => $data['Name'],
		'slug'          => MJSAIR_SLUG,
		'version'       => $release['version'] ?? $data['Version'],
		'author'        => '<a href="https://www.mjsmedia.co.uk/">MJS Media</a>',
		'homepage'      => 'https://github.com/' . MJSAIR_REPO,
		'requires'      => $data['RequiresWP'],
		'requires_php'  => $data['RequiresPHP'],
		'last_updated'  => $release['date'] ?? '',
		'download_link' => $release['package'] ?? '',
		'sections'      => array(
			'description' => '<p>' . esc_html( $data['Description'] ) . '</p>',
			'changelog'   => '<h4>' . esc_html( $release['version'] ?? $data['Version'] ) . '</h4>' . $notes,
		),
	);
}, 10, 3 );

/**
 * Download the zip. GitHub answers api.github.com/…/zipball/… with a redirect
 * to a short-lived signed codeload.github.com link; the token must go to the
 * API only, so follow that one redirect ourselves and fetch the signed link
 * without it.
 */
add_filter( 'upgrader_pre_download', function ( $reply, $package ) {
	if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, 'https://api.github.com/repos/' . MJSAIR_REPO . '/' ) ) {
		return $reply;
	}
	$is_asset = false !== strpos( $package, '/releases/assets/' );
	$res      = mjsair_github_get( substr( $package, strlen( 'https://api.github.com/' ) ), array( 'redirection' => 0 ), $is_asset ? 'application/octet-stream' : 'application/vnd.github+json' );
	$code     = wp_remote_retrieve_response_code( $res );
	$location = wp_remote_retrieve_header( $res, 'location' );
	if ( is_wp_error( $res ) || $code < 300 || $code >= 400 || ! $location ) {
		$why = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;
		return new WP_Error( 'mjsair_download', 'Could not download MJS AI Ready from GitHub: ' . $why );
	}
	return download_url( $location, 300 );
}, 10, 2 );

/** GitHub's zip unpacks as "mjs-media-mjs-ai-ready-<sha>/": rename it so the plugin stays in mjs-ai-ready/. */
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;
	$ours = ( $hook_extra['plugin'] ?? '' ) === mjsair_basename()
		|| 0 === strpos( basename( $source ), 'mjs-media-mjs-ai-ready-' );
	if ( ! $ours || ! $wp_filesystem ) {
		return $source;
	}
	$target = trailingslashit( $remote_source ) . MJSAIR_SLUG . '/';
	if ( untrailingslashit( $source ) === untrailingslashit( $target ) ) {
		return $source;
	}
	if ( ! $wp_filesystem->move( $source, $target, true ) ) {
		return new WP_Error( 'mjsair_rename', 'Could not rename the downloaded MJS AI Ready folder.' );
	}
	return $target;
}, 10, 4 );

/** Clear the cache after an update so the screen doesn't show stale info. */
add_action( 'upgrader_process_complete', function ( $upgrader, $extra ) {
	if ( 'plugin' === ( $extra['type'] ?? '' ) && in_array( mjsair_basename(), (array) ( $extra['plugins'] ?? array( $extra['plugin'] ?? '' ) ), true ) ) {
		delete_site_transient( MJSAIR_CACHE_KEY );
	}
}, 10, 2 );

/** On the Plugins screen, say why updates aren't arriving. */
add_filter( 'plugin_row_meta', function ( $meta, $file ) {
	if ( mjsair_basename() !== $file || ! current_user_can( 'update_plugins' ) ) {
		return $meta;
	}
	if ( mjsair_is_dev_copy() ) {
		$meta[] = 'Development copy: updates from GitHub are off';
	} elseif ( ! mjsair_latest_release() ) {
		$meta[] = '<strong>Can\'t check GitHub for updates right now</strong>';
	}
	return $meta;
}, 10, 2 );
