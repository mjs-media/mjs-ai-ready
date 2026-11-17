<?php
/**
 * The AI-readiness audit: `wp ai-ready audit`, the box on Settings → AI ready,
 * and the report tool on Matt's Mac.
 *
 * Standalone on purpose: the report tool sends inc/bots.php + this file over
 * SSH (`wp eval-file -`) to audit sites that don't have the plugin yet, so
 * nothing here may need the rest of the plugin. Plugin functions are used only
 * when they exist.
 *
 * Each check: id (stable, the report keys its copy on it), label, status
 * (pass | warn | fail | info), detail, and fix (what to do, for us).
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'mjsair_audit_run' ) ) {

	/** The site's sitemap, whoever makes it. */
	function mjsair_sitemap_url() {
		if ( defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Options' ) && WPSEO_Options::get( 'enable_xml_sitemap' ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		if ( defined( 'RANK_MATH_VERSION' ) && class_exists( '\RankMath\Helper' ) && \RankMath\Helper::is_module_active( 'sitemap' ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return home_url( '/sitemap.xml' );
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return home_url( '/sitemaps.xml' );
		}
		if ( function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ) {
			return home_url( '/wp-sitemap.xml' );
		}
		return '';
	}

	function mjsair_audit_home_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return trailingslashit( get_home_path() );
	}

	function mjsair_audit_fetch( $url ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'sslverify'   => false,
				'redirection' => 3,
				'headers'     => array( 'User-Agent' => 'Mozilla/5.0 (compatible; MJS-AI-Ready-Audit/1.0; +https://mjswebsites.co.uk/)' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'body' => '', 'type' => '', 'error' => $res->get_error_message() );
		}
		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $res ),
			'body'  => (string) wp_remote_retrieve_body( $res ),
			'type'  => (string) wp_remote_retrieve_header( $res, 'content-type' ),
			'error' => '',
		);
	}

	/** LocalBusiness and the subtypes a small business is likely to use. */
	function mjsair_audit_local_types() {
		return array( 'LocalBusiness', 'ProfessionalService', 'HomeAndConstructionBusiness', 'GeneralContractor', 'Electrician', 'Plumber', 'HVACBusiness', 'RoofingContractor', 'HousePainter', 'Locksmith', 'MovingCompany', 'SelfStorage', 'DryCleaningOrLaundry', 'AccountingService', 'LegalService', 'Attorney', 'Notary', 'FinancialService', 'InsuranceAgency', 'RealEstateAgent', 'EmploymentAgency', 'TravelAgency', 'MedicalBusiness', 'Dentist', 'Physiotherapy', 'Optician', 'Pharmacy', 'HealthAndBeautyBusiness', 'BeautySalon', 'HairSalon', 'NailSalon', 'DaySpa', 'TattooParlor', 'FoodEstablishment', 'Restaurant', 'CafeOrCoffeeShop', 'Bakery', 'BarOrPub', 'Winery', 'LodgingBusiness', 'Hotel', 'BedAndBreakfast', 'Campground', 'Store', 'ClothingStore', 'Florist', 'GardenStore', 'HardwareStore', 'HomeGoodsStore', 'JewelryStore', 'PetStore', 'BookStore', 'AutomotiveBusiness', 'AutoRepair', 'AutoDealer', 'AutoBodyShop', 'AutoWash', 'SportsActivityLocation', 'ExerciseGym', 'EntertainmentBusiness', 'ChildCare', 'AnimalShelter', 'Library', 'EducationalOrganization', 'NGO', 'EventVenue', 'CivicStructure', 'PlaceOfWorship', 'Church', 'Museum', 'TouristAttraction', 'School' );
	}

	/** Facts from a page's HTML: JSON-LD types, meta tags, headings. */
	function mjsair_audit_parse_html( $html ) {
		$facts = array(
			'schema_types'     => array(),
			'schema_blocks'    => 0,
			'schema_errors'    => 0,
			'meta_description' => '',
			'title'            => '',
			'noindex'          => false,
			'h1'               => array(),
			'google_verify'    => false,
			'bing_verify'      => false,
		);
		if ( '' === trim( $html ) ) {
			return $facts;
		}
		if ( preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$facts['schema_blocks']++;
				$data = json_decode( trim( html_entity_decode( $json ) ), true );
				if ( null === $data ) {
					$data = json_decode( trim( $json ), true );
				}
				if ( null === $data ) {
					$facts['schema_errors']++;
					continue;
				}
				$nodes = isset( $data['@graph'] ) ? $data['@graph'] : ( isset( $data[0] ) ? $data : array( $data ) );
				foreach ( (array) $nodes as $n ) {
					if ( is_array( $n ) && isset( $n['@graph'] ) ) {
						$n = $n['@graph'];
					} else {
						$n = array( $n );
					}
					foreach ( $n as $node ) {
						foreach ( (array) ( $node['@type'] ?? array() ) as $t ) {
							$facts['schema_types'][] = (string) $t;
						}
					}
				}
			}
		}
		$facts['schema_types'] = array_values( array_unique( $facts['schema_types'] ) );

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			$name    = strtolower( $meta->getAttribute( 'name' ) );
			$content = $meta->getAttribute( 'content' );
			if ( 'description' === $name && '' === $facts['meta_description'] ) {
				$facts['meta_description'] = trim( $content );
			} elseif ( 'robots' === $name && false !== stripos( $content, 'noindex' ) ) {
				$facts['noindex'] = true;
			} elseif ( 'google-site-verification' === $name ) {
				$facts['google_verify'] = true;
			} elseif ( 'msvalidate.01' === $name ) {
				$facts['bing_verify'] = true;
			}
		}
		$t = $dom->getElementsByTagName( 'title' );
		if ( $t->length ) {
			$facts['title'] = trim( $t->item( 0 )->textContent );
		}
		foreach ( $dom->getElementsByTagName( 'h1' ) as $h ) {
			$facts['h1'][] = array(
				'text'  => trim( preg_replace( '/\s+/', ' ', $h->textContent ) ),
				'image' => $h->getElementsByTagName( 'img' )->length > 0 || $h->getElementsByTagName( 'svg' )->length > 0,
			);
		}
		return $facts;
	}

	function mjsair_audit_robots_content() {
		$file = mjsair_audit_home_path() . 'robots.txt';
		if ( file_exists( $file ) ) {
			return array( 'source' => 'file', 'content' => (string) file_get_contents( $file ) ); // phpcs:ignore
		}
		if ( function_exists( 'mjsair_virtual_robots' ) ) {
			return array( 'source' => 'wordpress', 'content' => mjsair_virtual_robots() );
		}
		$public = (bool) get_option( 'blog_public' );
		$output = "User-agent: *\n" . ( $public ? "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" : "Disallow: /\n" );
		return array( 'source' => 'wordpress', 'content' => (string) apply_filters( 'robots_txt', $output, $public ) );
	}

	function mjsair_audit_check( $id, $label, $status, $detail, $fix = '' ) {
		return compact( 'id', 'label', 'status', 'detail', 'fix' );
	}

	/**
	 * Run every check.
	 *
	 * @param bool $fetch Fetch the homepage, llms.txt and sitemap over HTTP (from the server to itself).
	 */
	function mjsair_audit_run( $fetch = true ) {
		$plugin = function_exists( 'mjsair_opt' );
		$o      = $plugin ? mjsair_opt() : array();
		$seo    = '';
		foreach ( array( 'WPSEO_VERSION' => 'Yoast SEO', 'RANK_MATH_VERSION' => 'Rank Math', 'AIOSEO_VERSION' => 'All in One SEO', 'SEOPRESS_VERSION' => 'SEOPress' ) as $const => $name ) {
			if ( defined( $const ) ) {
				$seo = $name;
				break;
			}
		}
		$theme   = wp_get_theme();
		$builder = defined( 'ELEMENTOR_VERSION' ) ? 'Elementor' : ( ( defined( 'ET_BUILDER_VERSION' ) || 'Divi' === $theme->get_template() || 'Divi' === $theme->get( 'Name' ) ) ? 'Divi' : ( defined( 'FL_BUILDER_VERSION' ) ? 'Beaver Builder' : ( defined( 'WPB_VC_VERSION' ) ? 'WPBakery' : '' ) ) );
		$home    = home_url( '/' );
		$checks  = array();
		$facts   = array();

		// 1. Search engines allowed at all.
		$public = (bool) get_option( 'blog_public' );
		$checks[] = $public
			? mjsair_audit_check( 'blog_public', 'Search engines allowed', 'pass', 'Settings → Reading lets search engines in.' )
			: mjsair_audit_check( 'blog_public', 'Search engines allowed', 'fail', '"Discourage search engines from indexing this site" is ticked, so robots.txt tells every crawler to stay out.', 'wp option update blog_public 1' );

		// 2. HTTPS.
		$checks[] = 0 === strpos( $home, 'https://' )
			? mjsair_audit_check( 'https', 'Secure (https) address', 'pass', $home )
			: mjsair_audit_check( 'https', 'Secure (https) address', 'warn', 'The site address starts with http://, not https://.', 'Install an SSL certificate, then wp search-replace http://… https://…' );

		// 3. A real robots.txt file stops WordPress (and this plugin) controlling robots.txt.
		$robots   = mjsair_audit_robots_content();
		$facts['robots'] = array( 'source' => $robots['source'], 'content' => $robots['content'], 'bots' => array() );
		$checks[] = 'file' === $robots['source']
			? mjsair_audit_check( 'robots_file', 'robots.txt controlled by WordPress', 'warn', 'There is a robots.txt file in the site folder, so the web server sends that file and WordPress settings (including this plugin) can\'t change it.', 'wp ai-ready fix-robots (backs the file up first)' )
			: mjsair_audit_check( 'robots_file', 'robots.txt controlled by WordPress', 'pass', 'No robots.txt file on the server; WordPress builds it.' );

		// 4. Who robots.txt lets in.
		$blocked_search   = array();
		$blocked_training = array();
		$named            = 0;
		foreach ( mjsair_bots() as $bot => $info ) {
			$r = mjsair_robots_allows( $robots['content'], $bot, '/' );
			$facts['robots']['bots'][ $bot ] = $r['allowed'];
			if ( $r['group'] === $bot && 'search' === $info['kind'] ) {
				$named++;
			}
			if ( ! $r['allowed'] ) {
				if ( 'search' === $info['kind'] ) {
					$blocked_search[] = $bot;
				} else {
					$blocked_training[] = $bot;
				}
			}
		}
		if ( $blocked_search ) {
			$checks[] = mjsair_audit_check( 'robots_rules', 'Search and AI search crawlers allowed', 'fail', 'robots.txt blocks: ' . implode( ', ', $blocked_search ) . '.', $plugin ? 'Settings → AI ready → robots.txt' : 'Install MJS AI Ready' );
		} else {
			$training = $blocked_training ? ' AI training crawlers asked to stay out: ' . implode( ', ', $blocked_training ) . '.' : ' AI training crawlers are allowed too.';
			$checks[] = mjsair_audit_check( 'robots_rules', 'Search and AI search crawlers allowed', 'pass', ( $named >= 10 ? 'All search and AI search crawlers are allowed, by name.' : 'All search and AI search crawlers are allowed (' . ( $named ? $named . ' by name, the rest' : 'none named individually; all' ) . ' by the general rule).' ) . $training );
		}

		// 5. Business details (plugin only).
		if ( $plugin ) {
			$missing = array();
			if ( ! $o['summary'] ) {
				$missing[] = 'summary';
			}
			if ( ! $o['services'] ) {
				$missing[] = 'services';
			}
			if ( ! $o['locality'] && ! mjsair_lines( $o['areas'] ) ) {
				$missing[] = 'town or areas served';
			}
			if ( ! $o['contacts'] ) {
				$missing[] = 'contact methods';
			}
			$checks[] = $missing
				? mjsair_audit_check( 'business_details', 'Business details filled in', 'warn', 'Missing: ' . implode( ', ', $missing ) . '.', 'Settings → AI ready, or wp ai-ready setup <file.json>' )
				: mjsair_audit_check( 'business_details', 'Business details filled in', 'pass', count( $o['services'] ) . ' services, ' . count( $o['faqs'] ) . ' FAQs, ' . count( $o['key_pages'] ) . ' key pages.' );
		}

		// 6. Sitemap.
		$sitemap          = mjsair_sitemap_url();
		$facts['sitemap'] = $sitemap;
		if ( ! $sitemap ) {
			$checks[] = mjsair_audit_check( 'sitemap', 'XML sitemap', 'fail', 'No sitemap found (WordPress\'s own is switched off and no SEO plugin makes one).', 'Turn on WordPress sitemaps or the SEO plugin\'s sitemap' );
		} elseif ( $fetch ) {
			$r  = mjsair_audit_fetch( $sitemap );
			$ok = 200 === $r['code'] && preg_match( '/<(sitemapindex|urlset)/i', $r['body'] );
			$checks[] = $ok
				? mjsair_audit_check( 'sitemap', 'XML sitemap', 'pass', $sitemap )
				: mjsair_audit_check( 'sitemap', 'XML sitemap', 'warn', $sitemap . ' did not load (' . ( $r['error'] ?: 'HTTP ' . $r['code'] ) . ').', 'Re-save Settings → Permalinks, then check again' );
		} else {
			$checks[] = mjsair_audit_check( 'sitemap', 'XML sitemap', 'info', $sitemap . ' (not fetched)' );
		}

		// 7. llms.txt.
		$llms_file = file_exists( mjsair_audit_home_path() . 'llms.txt' );
		$facts['llms'] = array( 'file' => $llms_file, 'plugin' => $plugin && ! empty( $o['llms'] ) );
		if ( $fetch ) {
			$r  = mjsair_audit_fetch( home_url( '/llms.txt' ) );
			$ok = 200 === $r['code'] && 0 === strpos( ltrim( $r['body'] ), '#' ) && false === stripos( $r['type'], 'html' );
			$facts['llms']['code']  = $r['code'];
			$facts['llms']['lines'] = $ok ? substr_count( $r['body'], "\n" ) : 0;
			$checks[] = $ok
				? mjsair_audit_check( 'llms_txt', 'llms.txt', 'pass', home_url( '/llms.txt' ) . ' (' . $facts['llms']['lines'] . ' lines' . ( $llms_file ? ', a file on the server' : '' ) . ').' )
				: mjsair_audit_check( 'llms_txt', 'llms.txt', 'warn', 'No llms.txt at ' . home_url( '/llms.txt' ) . ' (' . ( $r['error'] ?: 'HTTP ' . $r['code'] ) . ').', $plugin ? 'Settings → AI ready → turn on llms.txt' : 'Install MJS AI Ready' );
		} else {
			$checks[] = mjsair_audit_check( 'llms_txt', 'llms.txt', ( $llms_file || $facts['llms']['plugin'] ) ? 'pass' : 'warn', $llms_file ? 'A file on the server.' : ( $facts['llms']['plugin'] ? 'Built by MJS AI Ready.' : 'None.' ) );
		}

		// 8–12. The homepage as visitors and crawlers see it.
		$page = $fetch ? mjsair_audit_fetch( $home ) : array( 'code' => 0, 'body' => '', 'error' => 'not fetched' );
		$hf   = mjsair_audit_parse_html( $page['body'] );
		$facts['homepage'] = array( 'code' => $page['code'], 'error' => $page['error'] ) + $hf;
		if ( 200 !== $page['code'] ) {
			$checks[] = mjsair_audit_check( 'homepage', 'Homepage loads', $fetch ? 'warn' : 'info', 'Could not fetch the homepage from the server (' . ( $page['error'] ?: 'HTTP ' . $page['code'] ) . '), so the schema, description and headline checks were skipped. The report tool checks these from outside.' );
		} else {
			$local = array_values( array_intersect( $hf['schema_types'], mjsair_audit_local_types() ) );
			if ( $local ) {
				$checks[] = mjsair_audit_check( 'schema_business', 'Business structured data', 'pass', 'Homepage describes the business as: ' . implode( ', ', $local ) . '. All types found: ' . implode( ', ', $hf['schema_types'] ) . '.' );
			} elseif ( in_array( 'Organization', $hf['schema_types'], true ) ) {
				$checks[] = mjsair_audit_check( 'schema_business', 'Business structured data', 'warn', 'Only a general Organization (no LocalBusiness with address, areas and hours). Types found: ' . implode( ', ', $hf['schema_types'] ) . '.', 'Fill in Settings → AI ready' );
			} else {
				$checks[] = mjsair_audit_check( 'schema_business', 'Business structured data', 'fail', $hf['schema_types'] ? 'No business details. Types found: ' . implode( ', ', $hf['schema_types'] ) . '.' : 'No structured data on the homepage.', 'Fill in Settings → AI ready' );
			}
			if ( $hf['schema_errors'] ) {
				$checks[] = mjsair_audit_check( 'schema_errors', 'Structured data readable', 'warn', $hf['schema_errors'] . ' JSON-LD block(s) on the homepage could not be read (broken JSON).', 'Check with https://validator.schema.org/' );
			}
			$checks[] = in_array( 'Service', $hf['schema_types'], true )
				? mjsair_audit_check( 'schema_services', 'Services in structured data', 'pass', 'Service entries found on the homepage.' )
				: mjsair_audit_check( 'schema_services', 'Services in structured data', 'warn', 'No Service entries on the homepage.', 'Add services on Settings → AI ready' );
			$checks[] = mjsair_audit_check( 'schema_faq', 'FAQ structured data', 'info', in_array( 'FAQPage', $hf['schema_types'], true ) ? 'FAQPage found on the homepage.' : 'No FAQPage on the homepage (fine if the FAQs are on another page).' );

			$checks[] = $hf['noindex']
				? mjsair_audit_check( 'noindex', 'Homepage can appear in search', 'fail', 'The homepage has a "noindex" robots tag, so search engines are told to leave it out.', 'Check the SEO plugin and Settings → Reading' )
				: mjsair_audit_check( 'noindex', 'Homepage can appear in search', 'pass', 'No noindex tag.' );

			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $hf['meta_description'] ) : strlen( $hf['meta_description'] );
			if ( ! $len ) {
				$checks[] = mjsair_audit_check( 'meta_description', 'Homepage description', 'warn', 'No meta description on the homepage, so search engines and AI tools pick their own snippet.', $seo ? 'Write one in ' . $seo : 'Turn on "homepage description" in Settings → AI ready' );
			} else {
				$checks[] = mjsair_audit_check( 'meta_description', 'Homepage description', ( $len < 50 || $len > 170 ) ? 'warn' : 'pass', '"' . $hf['meta_description'] . '" (' . $len . ' characters' . ( $len < 50 ? ', a little short' : ( $len > 170 ? ', may be cut off' : '' ) ) . ').' );
			}

			$h1 = $hf['h1'];
			if ( ! $h1 ) {
				$checks[] = mjsair_audit_check( 'headline', 'Homepage headline is text', 'warn', 'No main heading (h1) on the homepage. If the headline is part of a picture or slider, search engines and AI tools can\'t read it.', 'Make the main headline a real H1 text heading' );
			} elseif ( '' === $h1[0]['text'] ) {
				$checks[] = mjsair_audit_check( 'headline', 'Homepage headline is text', 'warn', 'The main heading is an image with no words in it.', 'Replace the image heading with text' );
			} else {
				$checks[] = mjsair_audit_check( 'headline', 'Homepage headline is text', 'pass', '"' . $h1[0]['text'] . '"' . ( count( $h1 ) > 1 ? ' (and ' . ( count( $h1 ) - 1 ) . ' more H1 headings)' : '' ) . '.' );
			}

			$both     = $hf['google_verify'] && $hf['bing_verify'];
			$checks[] = mjsair_audit_check( 'verification', 'Search Console and Bing verification tags', $both ? 'pass' : 'info', 'Google: ' . ( $hf['google_verify'] ? 'yes' : 'no' ) . ', Bing: ' . ( $hf['bing_verify'] ? 'yes' : 'no' ) . '. (A site can also be verified by DNS or a file, which this can\'t see.)' );
		}

		// 13. Meta descriptions on other pages (only measurable with Yoast or Rank Math).
		$meta_key = defined( 'WPSEO_VERSION' ) ? '_yoast_wpseo_metadesc' : ( defined( 'RANK_MATH_VERSION' ) ? 'rank_math_description' : '' );
		if ( $meta_key ) {
			$ids  = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids' ) );
			$with = 0;
			foreach ( $ids as $id ) {
				if ( '' !== trim( (string) get_post_meta( $id, $meta_key, true ) ) ) {
					$with++;
				}
			}
			$pct      = $ids ? round( 100 * $with / count( $ids ) ) : 100;
			$checks[] = mjsair_audit_check( 'page_descriptions', 'Page descriptions', $pct >= 80 ? 'pass' : 'warn', $with . ' of ' . count( $ids ) . ' published pages have their own description in ' . $seo . '.', 'Write descriptions for the main pages in ' . $seo );
		}

		// 14. IndexNow.
		if ( $plugin && ! empty( $o['indexnow'] ) ) {
			$last     = get_option( 'mjsair_indexnow_last' );
			$detail   = is_array( $last ) ? 'Last ping ' . human_time_diff( $last['time'] ) . ' ago: ' . $last['message'] . '.' : 'On. Nothing sent yet.';
			$live     = function_exists( 'mjsair_is_live' ) && mjsair_is_live();
			$checks[] = mjsair_audit_check( 'indexnow', 'IndexNow (instant updates to Bing)', ( is_array( $last ) && $last['code'] >= 300 ) ? 'warn' : 'pass', $detail . ( $live ? '' : ' Not sending: this copy is not a live production site.' ) );
		} else {
			$checks[] = mjsair_audit_check( 'indexnow', 'IndexNow (instant updates to Bing)', 'info', 'Not set up.', 'MJS AI Ready does this' );
		}

		// What we know about the business, for the AI questions in the report.
		$name = $plugin && $o['name'] ? $o['name'] : get_bloginfo( 'name' );
		$facts['business'] = array(
			'name'     => html_entity_decode( $name, ENT_QUOTES ),
			'tagline'  => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'locality' => $plugin ? $o['locality'] : '',
			'areas'    => $plugin ? mjsair_lines( $o['areas'] ) : array(),
			'services' => $plugin ? array_column( $o['services'], 'name' ) : array(),
			'type'     => $plugin ? $o['type'] : '',
		);

		$score = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );
		foreach ( $checks as $c ) {
			$score[ $c['status'] ]++;
		}
		return array(
			'audit_version'  => 1,
			'generated'      => gmdate( 'c' ),
			'site'           => $home,
			'wp_version'     => get_bloginfo( 'version' ),
			'plugin_version' => defined( 'MJSAIR_VERSION' ) ? MJSAIR_VERSION : null,
			'seo_plugin'     => $seo,
			'page_builder'   => $builder,
			'theme'          => $theme->get( 'Name' ),
			'environment'    => wp_get_environment_type(),
			'summary'        => $score,
			'checks'         => $checks,
			'facts'          => $facts,
		);
	}
}
