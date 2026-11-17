<?php
/**
 * The crawlers we care about, and a small robots.txt reader.
 *
 * This file and inc/audit.php are also sent over SSH by the report tool to
 * audit sites that don't have the plugin yet (`wp eval-file -`), so they must
 * not depend on anything else in the plugin.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'mjsair_bots' ) ) {

	/**
	 * Search and AI search crawlers (checked November 2026, same list as the
	 * Express Removals and MJS Websites themes).
	 *
	 * 'search'   – fetches pages to show them in search or AI answers.
	 * 'training' – collects pages to train AI models. Blocking these does not
	 *              stop the business appearing in AI search answers.
	 */
	function mjsair_bots() {
		return array(
			'Googlebot'         => array( 'owner' => 'Google', 'kind' => 'search', 'label' => 'Google Search' ),
			'Bingbot'           => array( 'owner' => 'Microsoft', 'kind' => 'search', 'label' => 'Bing, Copilot and ChatGPT search results' ),
			'OAI-SearchBot'     => array( 'owner' => 'OpenAI', 'kind' => 'search', 'label' => 'ChatGPT search' ),
			'ChatGPT-User'      => array( 'owner' => 'OpenAI', 'kind' => 'search', 'label' => 'ChatGPT visiting a page for a user' ),
			'GPTBot'            => array( 'owner' => 'OpenAI', 'kind' => 'training', 'label' => 'OpenAI model training' ),
			'PerplexityBot'     => array( 'owner' => 'Perplexity', 'kind' => 'search', 'label' => 'Perplexity search' ),
			'Perplexity-User'   => array( 'owner' => 'Perplexity', 'kind' => 'search', 'label' => 'Perplexity visiting a page for a user' ),
			'ClaudeBot'         => array( 'owner' => 'Anthropic', 'kind' => 'training', 'label' => 'Anthropic model training' ),
			'Claude-SearchBot'  => array( 'owner' => 'Anthropic', 'kind' => 'search', 'label' => 'Claude search' ),
			'Claude-User'       => array( 'owner' => 'Anthropic', 'kind' => 'search', 'label' => 'Claude visiting a page for a user' ),
			'Google-Extended'   => array( 'owner' => 'Google', 'kind' => 'training', 'label' => 'Gemini model training (not Google Search)' ),
			'Applebot'          => array( 'owner' => 'Apple', 'kind' => 'search', 'label' => 'Siri and Spotlight' ),
			'Applebot-Extended' => array( 'owner' => 'Apple', 'kind' => 'training', 'label' => 'Apple model training' ),
			'DuckDuckBot'       => array( 'owner' => 'DuckDuckGo', 'kind' => 'search', 'label' => 'DuckDuckGo' ),
		);
	}

	/**
	 * Parse robots.txt into groups: array( array( 'agents' => [...], 'rules' => [ [allow|disallow, path], ... ] ) ).
	 */
	function mjsair_robots_parse( $txt ) {
		$groups  = array();
		$current = null;
		$in_ua   = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $txt ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( ! $in_ua ) {
					$groups[] = array( 'agents' => array(), 'rules' => array() );
					$current  = count( $groups ) - 1;
				}
				$groups[ $current ]['agents'][] = strtolower( $value );
				$in_ua = true;
			} elseif ( in_array( $field, array( 'allow', 'disallow' ), true ) ) {
				$in_ua = false;
				if ( null !== $current ) {
					$groups[ $current ]['rules'][] = array( $field, $value );
				}
			} else {
				$in_ua = false; // Sitemap:, Crawl-delay: etc. end a run of User-agent lines.
			}
		}
		return $groups;
	}

	/**
	 * May this bot fetch $path? Follows Google's rules: the most specific
	 * user-agent group wins, then the longest matching rule (Allow wins a tie).
	 *
	 * @return array( 'allowed' => bool, 'group' => 'GPTBot'|'*'|null )
	 */
	function mjsair_robots_allows( $txt, $bot, $path = '/' ) {
		$groups = mjsair_robots_parse( $txt );
		$bot_lc = strtolower( $bot );
		$rules  = array();
		$which  = null;
		foreach ( $groups as $g ) {
			if ( in_array( $bot_lc, $g['agents'], true ) ) {
				$rules = array_merge( $rules, $g['rules'] );
				$which = $bot;
			}
		}
		if ( null === $which ) {
			foreach ( $groups as $g ) {
				if ( in_array( '*', $g['agents'], true ) ) {
					$rules = array_merge( $rules, $g['rules'] );
					$which = '*';
				}
			}
		}
		$best     = -1;
		$allowed  = true;
		foreach ( $rules as $r ) {
			list( $type, $rule_path ) = $r;
			if ( '' === $rule_path ) {
				continue; // "Disallow:" with nothing after it allows everything.
			}
			$regex = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $rule_path, '#' ) ) . '#';
			if ( ! preg_match( $regex, $path ) ) {
				continue;
			}
			$len = strlen( $rule_path );
			if ( $len > $best || ( $len === $best && 'allow' === $type ) ) {
				$best    = $len;
				$allowed = ( 'allow' === $type );
			}
		}
		return array( 'allowed' => $allowed, 'group' => $which );
	}
}
