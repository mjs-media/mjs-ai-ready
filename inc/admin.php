<?php
/**
 * Settings → AI ready.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', function () {
	register_setting(
		'mjsair',
		MJSAIR_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'mjsair_sanitize',
			'default'           => array(),
		)
	);
} );

add_action( 'admin_menu', function () {
	add_options_page( 'AI ready', 'AI ready', 'manage_options', 'mjs-ai-ready', 'mjsair_settings_page' );
} );

add_filter( 'plugin_action_links_' . plugin_basename( MJSAIR_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=mjs-ai-ready' ) ) . '">Settings</a>' );
	return $links;
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'settings_page_mjs-ai-ready' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'mjsair-admin', MJSAIR_URL . 'assets/admin.css', array(), MJSAIR_VERSION );
	wp_enqueue_script( 'mjsair-admin', MJSAIR_URL . 'assets/admin.js', array(), MJSAIR_VERSION, true );
} );

// A robots.txt file on the server silently overrides the robots.txt settings: say so.
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || 'off' === mjsair_opt( 'robots_mode' ) ) {
		return;
	}
	if ( ! file_exists( mjsair_audit_home_path() . 'robots.txt' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins', 'settings_page_mjs-ai-ready' ), true ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>AI ready:</strong> there is a <code>robots.txt</code> file in the site\'s folder, so the web server sends that instead of the one WordPress builds, and the AI ready crawler settings have no effect. Ask MJS Media to run <code>wp ai-ready fix-robots</code> (it keeps a backup), or edit the file by hand.</p></div>';
} );

/* --------------------------------------------------------------------------
 * The page.
 * ----------------------------------------------------------------------- */

function mjsair_field_name( $key ) {
	return MJSAIR_OPTION . '[' . $key . ']';
}

function mjsair_settings_page() {
	$o    = mjsair_opt();
	$full = ! empty( $_GET['mjsair_check'] ); // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wrap mjsair">
		<h1>AI ready</h1>
		<p class="mjsair-intro">These details help Google, Bing and AI search tools such as ChatGPT, Claude and Perplexity understand and describe <?php echo esc_html( $o['name'] ); ?> correctly. They're turned into structured data on every page, a plain-text summary at <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank">/llms.txt</a>, and crawler rules in <a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank">robots.txt</a>. Write as you'd explain the business to a new customer.</p>

		<?php mjsair_render_audit( $full ); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'mjsair' ); ?>

			<h2>The business</h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="mjsair-name">Business name</label></th>
					<td><input id="mjsair-name" class="regular-text" name="<?php echo esc_attr( mjsair_field_name( 'name' ) ); ?>" value="<?php echo esc_attr( $o['name'] ); ?>"></td></tr>
				<tr><th><label for="mjsair-type">Type of business</label></th>
					<td><select id="mjsair-type" name="<?php echo esc_attr( mjsair_field_name( 'type' ) ); ?>">
						<?php foreach ( mjsair_business_types() as $group => $types ) : ?>
							<optgroup label="<?php echo esc_attr( $group ); ?>">
								<?php foreach ( $types as $t ) : ?>
									<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $o['type'], $t ); ?>><?php echo esc_html( trim( preg_replace( '/(?<!^)([A-Z])/', ' $1', $t ) ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
					<p class="description">The closest match on schema.org. If nothing fits, choose Local Business (or NGO for a charity).</p></td></tr>
				<tr><th><label for="mjsair-summary">Summary</label></th>
					<td><textarea id="mjsair-summary" class="large-text" rows="4" name="<?php echo esc_attr( mjsair_field_name( 'summary' ) ); ?>"><?php echo esc_textarea( $o['summary'] ); ?></textarea>
					<p class="description">Two or three plain sentences: what you do, for whom, where, and what makes you different. Facts, not slogans.</p></td></tr>
				<tr><th><label for="mjsair-logo">Logo image address</label></th>
					<td><input id="mjsair-logo" class="regular-text" name="<?php echo esc_attr( mjsair_field_name( 'logo' ) ); ?>" value="<?php echo esc_attr( $o['logo'] ); ?>" placeholder="Leave empty to use the site logo">
				</td></tr>
			</table>

			<h2>Services</h2>
			<p class="description">One line each, with the page that describes it.</p>
			<?php
			mjsair_repeater(
				'services',
				$o['services'],
				array(
					'name'        => array( 'Service', 'text', 'e.g. House removals' ),
					'description' => array( 'One-line description', 'text', 'e.g. Full or part loads across Sussex and Kent, with packing if you need it' ),
					'url'         => array( 'Page', 'url', '/services/house-removals/' ),
				),
				'Add a service'
			);
			?>

			<h2>Where</h2>
			<table class="form-table" role="presentation">
				<tr><th>Address</th>
					<td class="mjsair-address">
						<input class="regular-text" name="<?php echo esc_attr( mjsair_field_name( 'street' ) ); ?>" value="<?php echo esc_attr( $o['street'] ); ?>" placeholder="Street">
						<input name="<?php echo esc_attr( mjsair_field_name( 'locality' ) ); ?>" value="<?php echo esc_attr( $o['locality'] ); ?>" placeholder="Town">
						<input name="<?php echo esc_attr( mjsair_field_name( 'region' ) ); ?>" value="<?php echo esc_attr( $o['region'] ); ?>" placeholder="County">
						<input class="small-text" name="<?php echo esc_attr( mjsair_field_name( 'postcode' ) ); ?>" value="<?php echo esc_attr( $o['postcode'] ); ?>" placeholder="Postcode">
						<input class="tiny-text" name="<?php echo esc_attr( mjsair_field_name( 'country' ) ); ?>" value="<?php echo esc_attr( $o['country'] ); ?>" maxlength="2" title="Country code">
						<p><label><input type="checkbox" name="<?php echo esc_attr( mjsair_field_name( 'show_address' ) ); ?>" value="1" <?php checked( $o['show_address'] ); ?>> Show the full address (untick if you work from home and visit customers: only the town is shown)</label></p>
					</td></tr>
				<tr><th><label for="mjsair-areas">Areas served</label></th>
					<td><textarea id="mjsair-areas" rows="4" class="regular-text" name="<?php echo esc_attr( mjsair_field_name( 'areas' ) ); ?>"><?php echo esc_textarea( $o['areas'] ); ?></textarea>
					<p class="description">One per line: towns, counties or "United Kingdom".</p></td></tr>
			</table>

			<h2>Opening hours</h2>
			<?php mjsair_hours_repeater( $o['hours'] ); ?>
			<p><input class="regular-text" name="<?php echo esc_attr( mjsair_field_name( 'hours_note' ) ); ?>" value="<?php echo esc_attr( $o['hours_note'] ); ?>" placeholder="Note, e.g. Closed bank holidays. Evenings by appointment."></p>

			<h2>How to get in touch</h2>
			<?php
			mjsair_repeater(
				'contacts',
				$o['contacts'],
				array(
					'type'  => array( 'Type', mjsair_contact_types(), '' ),
					'label' => array( 'Label (optional)', 'text', 'e.g. Office' ),
					'value' => array( 'Number, address or link', 'text', '' ),
				),
				'Add a contact method'
			);
			?>
			<table class="form-table" role="presentation">
				<tr><th><label for="mjsair-same">Profiles elsewhere</label></th>
					<td><textarea id="mjsair-same" rows="3" class="large-text" name="<?php echo esc_attr( mjsair_field_name( 'same_as' ) ); ?>"><?php echo esc_textarea( $o['same_as'] ); ?></textarea>
					<p class="description">One link per line: Google Business Profile, Facebook, Instagram, LinkedIn, trade bodies. Helps tools be sure they've found the right business.</p></td></tr>
			</table>

			<h2>Frequently asked questions</h2>
			<p class="description">Real questions customers ask, answered briefly. Show them on a page with the <strong>AI ready FAQs</strong> block or the <code>[ai_ready_faq]</code> shortcode (<code>[ai_ready_faq ids="1,3"]</code> for some of them); that page then gets FAQ structured data.</p>
			<?php
			mjsair_repeater(
				'faqs',
				$o['faqs'],
				array(
					'q' => array( 'Question', 'text', '' ),
					'a' => array( 'Answer', 'textarea', '' ),
				),
				'Add a question',
				true
			);
			?>

			<h2>Key pages</h2>
			<p class="description">The pages that best explain the business. They go first in llms.txt.</p>
			<div class="mjsair-pages">
				<?php
				$pages = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'menu_order,post_title' ) );
				foreach ( $pages as $p ) :
					?>
					<label><input type="checkbox" name="<?php echo esc_attr( mjsair_field_name( 'key_pages' ) ); ?>[]" value="<?php echo (int) $p->ID; ?>" <?php checked( in_array( (int) $p->ID, $o['key_pages'], true ) ); ?>> <?php echo esc_html( str_repeat( '— ', count( get_post_ancestors( $p ) ) ) . ( $p->post_title ?: '(no title)' ) ); ?></label>
				<?php endforeach; ?>
			</div>

			<h2>Search engines and AI tools</h2>
			<table class="form-table" role="presentation">
				<tr><th>robots.txt</th>
					<td>
						<?php
						$modes = array(
							'allow_all'   => 'Welcome search engines and all AI tools (recommended)',
							'search_only' => 'Welcome search engines and AI search, but ask AI training crawlers to stay out (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended)',
							'off'         => 'Leave robots.txt alone',
						);
						foreach ( $modes as $v => $label ) :
							?>
							<label class="mjsair-radio"><input type="radio" name="<?php echo esc_attr( mjsair_field_name( 'robots_mode' ) ); ?>" value="<?php echo esc_attr( $v ); ?>" <?php checked( $o['robots_mode'], $v ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description">Blocking training crawlers doesn't stop the business appearing in AI search answers. If the site is behind Cloudflare, its "Block AI bots" setting overrides this and has to be changed in the Cloudflare dashboard.</p>
					</td></tr>
				<tr><th>llms.txt</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( mjsair_field_name( 'llms' ) ); ?>" value="1" <?php checked( $o['llms'] ); ?>> Publish <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank">/llms.txt</a></label>
					with the latest <input class="tiny-text" type="number" min="0" max="50" name="<?php echo esc_attr( mjsair_field_name( 'llms_posts' ) ); ?>" value="<?php echo (int) $o['llms_posts']; ?>"> posts
					<p class="description">llms.txt is a proposed standard: some AI tools read it, others don't yet.</p></td></tr>
				<tr><th>Structured data</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( mjsair_field_name( 'schema' ) ); ?>" value="1" <?php checked( $o['schema'] ); ?>> Add business, service and FAQ structured data</label>
					<?php if ( in_array( mjsair_seo_plugin(), array( 'yoast', 'rankmath' ), true ) ) : ?>
						<p class="description">Added to <?php echo 'yoast' === mjsair_seo_plugin() ? 'Yoast' : 'Rank Math'; ?>'s structured data, not printed twice.</p>
					<?php endif; ?></td></tr>
				<tr><th>IndexNow</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( mjsair_field_name( 'indexnow' ) ); ?>" value="1" <?php checked( $o['indexnow'] ); ?>> Tell Bing (and ChatGPT search, which uses Bing) when a page is published or updated</label>
					<?php
					$last = get_option( MJSAIR_INDEXNOW_LOG );
					if ( is_array( $last ) ) {
						echo '<p class="description">Last sent ' . esc_html( human_time_diff( $last['time'] ) ) . ' ago: ' . esc_html( $last['message'] ) . ' (' . (int) $last['count'] . ' pages).</p>';
					} elseif ( ! mjsair_is_live() ) {
						echo '<p class="description">Only sends from the live site.</p>';
					}
					?>
					</td></tr>
				<tr><th>Homepage description</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( mjsair_field_name( 'home_description' ) ); ?>" value="1" <?php checked( $o['home_description'] ); ?> <?php disabled( (bool) mjsair_seo_plugin() ); ?>> Use the summary as the homepage's meta description</label>
					<p class="description"><?php echo mjsair_seo_plugin() ? 'Your SEO plugin handles descriptions.' : 'Only if the theme doesn\'t already add one (the check above tells you).'; ?></p></td></tr>
				<tr><th><label for="mjsair-g">Google Search Console</label></th>
					<td><input id="mjsair-g" class="regular-text" name="<?php echo esc_attr( mjsair_field_name( 'google_verify' ) ); ?>" value="<?php echo esc_attr( $o['google_verify'] ); ?>" placeholder="Verification code or the whole meta tag"></td></tr>
				<tr><th><label for="mjsair-b">Bing Webmaster Tools</label></th>
					<td><input id="mjsair-b" class="regular-text" name="<?php echo esc_attr( mjsair_field_name( 'bing_verify' ) ); ?>" value="<?php echo esc_attr( $o['bing_verify'] ); ?>" placeholder="Verification code or the whole meta tag">
					<p class="description">Usually not needed: Bing can import the site straight from Search Console.</p></td></tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * A repeating group of fields. $fields: key => array( label, type|options, placeholder ).
 */
function mjsair_repeater( $key, $rows, $fields, $add_label, $numbered = false ) {
	$render = function ( $i, $row ) use ( $key, $fields, $numbered ) {
		$out = '<div class="mjsair-row' . ( $numbered ? ' is-numbered' : '' ) . '">';
		foreach ( $fields as $f => $def ) {
			list( $label, $type, $ph ) = $def;
			$name = mjsair_field_name( $key ) . '[' . $i . '][' . $f . ']';
			$val  = $row[ $f ] ?? '';
			$out .= '<label class="mjsair-field mjsair-field--' . esc_attr( $f ) . '"><span>' . esc_html( $label ) . '</span>';
			if ( is_array( $type ) ) {
				$out .= '<select name="' . esc_attr( $name ) . '">';
				foreach ( $type as $v => $l ) {
					$out .= '<option value="' . esc_attr( $v ) . '"' . selected( $val, $v, false ) . '>' . esc_html( $l ) . '</option>';
				}
				$out .= '</select>';
			} elseif ( 'textarea' === $type ) {
				$out .= '<textarea rows="3" name="' . esc_attr( $name ) . '">' . esc_textarea( $val ) . '</textarea>';
			} else {
				$out .= '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $ph ) . '">';
			}
			$out .= '</label>';
		}
		return $out . '<button type="button" class="button-link mjsair-remove" aria-label="Remove">Remove</button></div>';
	};
	echo '<div class="mjsair-repeater" data-key="' . esc_attr( $key ) . '">';
	echo '<div class="mjsair-rows">';
	foreach ( array_values( (array) $rows ) as $i => $row ) {
		echo $render( $i, $row ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}
	echo '</div><template>' . $render( '__i__', array() ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<p><button type="button" class="button mjsair-add">' . esc_html( $add_label ) . '</button></p></div>';
}

function mjsair_hours_repeater( $rows ) {
	$render = function ( $i, $row ) {
		$base = mjsair_field_name( 'hours' ) . '[' . $i . ']';
		$out  = '<div class="mjsair-row mjsair-hours"><span class="mjsair-days">';
		foreach ( mjsair_days() as $d ) {
			$out .= '<label><input type="checkbox" name="' . esc_attr( $base ) . '[days][]" value="' . esc_attr( $d ) . '"' . checked( in_array( $d, (array) ( $row['days'] ?? array() ), true ), true, false ) . '> ' . esc_html( substr( $d, 0, 3 ) ) . '</label>';
		}
		$out .= '</span> <input type="time" name="' . esc_attr( $base ) . '[opens]" value="' . esc_attr( $row['opens'] ?? '09:00' ) . '"> to <input type="time" name="' . esc_attr( $base ) . '[closes]" value="' . esc_attr( $row['closes'] ?? '17:00' ) . '">';
		return $out . ' <button type="button" class="button-link mjsair-remove">Remove</button></div>';
	};
	echo '<div class="mjsair-repeater" data-key="hours"><div class="mjsair-rows">';
	foreach ( array_values( (array) $rows ) as $i => $row ) {
		echo $render( $i, $row ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div><template>' . $render( '__i__', array() ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<p><button type="button" class="button mjsair-add">Add opening hours</button> <span class="description">e.g. Mon–Fri 08:00 to 17:30, then Sat 09:00 to 12:00 as a second line.</span></p></div>';
}

function mjsair_render_audit( $full ) {
	$audit  = mjsair_audit_run( $full );
	$labels = array( 'pass' => 'Good', 'warn' => 'Worth fixing', 'fail' => 'Needs fixing', 'info' => 'Note' );
	?>
	<div class="mjsair-audit">
		<h2>Check <span class="mjsair-score"><?php echo (int) $audit['summary']['pass']; ?> good · <?php echo (int) $audit['summary']['warn']; ?> worth fixing · <?php echo (int) $audit['summary']['fail']; ?> need fixing</span></h2>
		<table class="widefat striped">
			<tbody>
			<?php foreach ( $audit['checks'] as $c ) : ?>
				<tr class="is-<?php echo esc_attr( $c['status'] ); ?>">
					<td class="mjsair-status"><span><?php echo esc_html( $labels[ $c['status'] ] ); ?></span></td>
					<td><strong><?php echo esc_html( $c['label'] ); ?></strong><br><?php echo esc_html( $c['detail'] ); ?>
						<?php if ( $c['fix'] && 'pass' !== $c['status'] ) : ?><br><em>Fix: <?php echo esc_html( $c['fix'] ); ?></em><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<?php if ( $full ) : ?>
				Checked the live homepage, llms.txt and sitemap just now.
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'mjsair_check', 1 ) ); ?>">Check the homepage too</a> <span class="description">Loads the homepage, llms.txt and sitemap to check structured data, description and headline.</span>
			<?php endif; ?>
		</p>
	</div>
	<?php
}
