<?php
/**
 * Settings: one option, `mjsair_settings`, holding everything on Settings → AI ready.
 *
 * @package mjs-ai-ready
 */

defined( 'ABSPATH' ) || exit;

const MJSAIR_OPTION = 'mjsair_settings';

function mjsair_defaults() {
	return array(
		'name'            => get_bloginfo( 'name' ),
		'type'            => 'LocalBusiness',
		'summary'         => '',
		'logo'            => '',
		'contacts'        => array(),  // [ type, label, value ]
		'street'          => '',
		'locality'        => '',
		'region'          => '',
		'postcode'        => '',
		'country'         => 'GB',
		'show_address'    => 1,        // 0 for businesses that visit customers and don't want the address public
		'areas'           => '',       // one per line
		'hours'           => array(),  // [ days[], opens, closes ]
		'hours_note'      => '',       // e.g. "Evenings by appointment"
		'services'        => array(),  // [ name, description, url ]
		'faqs'            => array(),  // [ q, a ]
		'key_pages'       => array(),  // page IDs
		'same_as'         => '',       // profile links, one per line
		'google_verify'   => '',
		'bing_verify'     => '',
		'robots_mode'     => 'allow_all', // allow_all | search_only | off
		'llms'            => 1,
		'llms_posts'      => 10,
		'schema'          => 1,
		'indexnow'        => 1,
		'home_description'=> 0,        // print a homepage meta description from the summary when no SEO plugin is active
	);
}

/** All settings, or one. */
function mjsair_opt( $key = null ) {
	$saved = get_option( MJSAIR_OPTION, array() );
	$all   = array_merge( mjsair_defaults(), is_array( $saved ) ? $saved : array() );
	if ( null === $key ) {
		return $all;
	}
	return $all[ $key ] ?? null;
}

/** schema.org types to choose from: LocalBusiness and its common subtypes, plus a few for charities and venues. */
function mjsair_business_types() {
	return array(
		'General'                 => array( 'LocalBusiness', 'ProfessionalService', 'Organization', 'NGO' ),
		'Trades and home'         => array( 'HomeAndConstructionBusiness', 'GeneralContractor', 'Electrician', 'Plumber', 'HVACBusiness', 'RoofingContractor', 'HousePainter', 'Locksmith', 'MovingCompany', 'SelfStorage', 'DryCleaningOrLaundry' ),
		'Professional'            => array( 'AccountingService', 'LegalService', 'Notary', 'FinancialService', 'InsuranceAgency', 'RealEstateAgent', 'EmploymentAgency', 'TravelAgency' ),
		'Health and beauty'       => array( 'MedicalBusiness', 'Dentist', 'Physiotherapy', 'Optician', 'Pharmacy', 'HealthAndBeautyBusiness', 'BeautySalon', 'HairSalon', 'NailSalon', 'DaySpa', 'TattooParlor' ),
		'Food, drink and stay'    => array( 'FoodEstablishment', 'Restaurant', 'CafeOrCoffeeShop', 'Bakery', 'BarOrPub', 'Winery', 'LodgingBusiness', 'Hotel', 'BedAndBreakfast', 'Campground' ),
		'Shops'                   => array( 'Store', 'ClothingStore', 'Florist', 'GardenStore', 'HardwareStore', 'HomeGoodsStore', 'JewelryStore', 'PetStore', 'BookStore' ),
		'Motoring'                => array( 'AutomotiveBusiness', 'AutoRepair', 'AutoDealer', 'AutoBodyShop', 'AutoWash' ),
		'Leisure and community'   => array( 'SportsActivityLocation', 'ExerciseGym', 'EntertainmentBusiness', 'ChildCare', 'AnimalShelter', 'Library', 'EventVenue', 'CivicStructure' ),
	);
}

function mjsair_is_known_type( $type ) {
	foreach ( mjsair_business_types() as $types ) {
		if ( in_array( $type, $types, true ) ) {
			return true;
		}
	}
	return false;
}

function mjsair_contact_types() {
	return array(
		'email'    => 'Email',
		'phone'    => 'Phone',
		'whatsapp' => 'WhatsApp',
		'form'     => 'Contact form (page link)',
		'booking'  => 'Online booking (link)',
		'other'    => 'Other',
	);
}

function mjsair_days() {
	return array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
}

function mjsair_lines( $text ) {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ) ) );
}

/**
 * Clean a settings array from the form or `wp ai-ready setup`.
 * Unknown keys are dropped; missing keys keep their defaults.
 */
function mjsair_sanitize( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$d   = mjsair_defaults();
	$out = array();

	foreach ( array( 'name', 'locality', 'region', 'postcode', 'street', 'hours_note' ) as $k ) {
		$out[ $k ] = sanitize_text_field( $in[ $k ] ?? $d[ $k ] );
	}
	$out['country'] = strtoupper( substr( sanitize_text_field( $in['country'] ?? 'GB' ), 0, 2 ) ) ?: 'GB';
	$out['summary'] = sanitize_textarea_field( $in['summary'] ?? '' );
	$out['areas']   = sanitize_textarea_field( is_array( $in['areas'] ?? null ) ? implode( "\n", $in['areas'] ) : ( $in['areas'] ?? '' ) );
	$out['same_as'] = implode( "\n", array_map( 'esc_url_raw', mjsair_lines( is_array( $in['same_as'] ?? null ) ? implode( "\n", $in['same_as'] ) : ( $in['same_as'] ?? '' ) ) ) );
	$out['logo']    = esc_url_raw( $in['logo'] ?? '' );
	$out['type']    = mjsair_is_known_type( $in['type'] ?? '' ) ? $in['type'] : 'LocalBusiness';

	$out['contacts'] = array();
	foreach ( (array) ( $in['contacts'] ?? array() ) as $row ) {
		$value = trim( sanitize_text_field( $row['value'] ?? '' ) );
		if ( '' === $value ) {
			continue;
		}
		$type              = array_key_exists( $row['type'] ?? '', mjsair_contact_types() ) ? $row['type'] : 'other';
		$out['contacts'][] = array( 'type' => $type, 'label' => sanitize_text_field( $row['label'] ?? '' ), 'value' => $value );
	}

	$out['hours'] = array();
	foreach ( (array) ( $in['hours'] ?? array() ) as $row ) {
		$days  = array_values( array_intersect( mjsair_days(), (array) ( $row['days'] ?? array() ) ) );
		$opens = preg_match( '/^\d{1,2}:\d{2}$/', $row['opens'] ?? '' ) ? $row['opens'] : '';
		$close = preg_match( '/^\d{1,2}:\d{2}$/', $row['closes'] ?? '' ) ? $row['closes'] : '';
		if ( $days && $opens && $close ) {
			$out['hours'][] = array( 'days' => $days, 'opens' => $opens, 'closes' => $close );
		}
	}

	$out['services'] = array();
	foreach ( (array) ( $in['services'] ?? array() ) as $row ) {
		$name = sanitize_text_field( $row['name'] ?? '' );
		if ( '' === $name ) {
			continue;
		}
		$url = trim( (string) ( $row['url'] ?? '' ) );
		if ( '' !== $url && '/' === $url[0] ) {
			$url = home_url( $url );
		}
		$out['services'][] = array(
			'name'        => $name,
			'description' => sanitize_text_field( $row['description'] ?? '' ),
			'url'         => esc_url_raw( $url ),
		);
	}

	$out['faqs'] = array();
	foreach ( (array) ( $in['faqs'] ?? array() ) as $row ) {
		$q = sanitize_text_field( $row['q'] ?? ( $row['question'] ?? '' ) );
		$a = wp_kses_post( $row['a'] ?? ( $row['answer'] ?? '' ) );
		if ( '' !== $q && '' !== trim( wp_strip_all_tags( $a ) ) ) {
			$out['faqs'][] = array( 'q' => $q, 'a' => $a );
		}
	}

	$out['key_pages'] = array();
	foreach ( (array) ( $in['key_pages'] ?? array() ) as $p ) {
		$id = mjsair_resolve_page( $p );
		if ( $id && ! in_array( $id, $out['key_pages'], true ) ) {
			$out['key_pages'][] = $id;
		}
	}

	// Verification codes: accept the whole <meta> tag pasted in, keep just the code.
	foreach ( array( 'google_verify', 'bing_verify' ) as $k ) {
		$v = (string) ( $in[ $k ] ?? '' );
		if ( preg_match( '/content=["\']([^"\']+)["\']/i', $v, $m ) ) {
			$v = $m[1];
		}
		$out[ $k ] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $v );
	}

	$out['robots_mode'] = in_array( $in['robots_mode'] ?? '', array( 'allow_all', 'search_only', 'off' ), true ) ? $in['robots_mode'] : 'allow_all';
	$out['llms_posts']  = max( 0, min( 50, (int) ( $in['llms_posts'] ?? 10 ) ) );
	foreach ( array( 'llms', 'schema', 'indexnow', 'show_address', 'home_description' ) as $k ) {
		$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
	}
	return $out;
}

/** A page ID from an ID, a path ("/about/") or a full URL on this site. */
function mjsair_resolve_page( $p ) {
	if ( is_numeric( $p ) ) {
		return get_post( (int) $p ) ? (int) $p : 0;
	}
	$p = trim( (string) $p );
	if ( '' === $p ) {
		return 0;
	}
	if ( 0 === strpos( $p, 'http' ) ) {
		$id = url_to_postid( $p );
		if ( $id ) {
			return $id;
		}
		$p = (string) wp_parse_url( $p, PHP_URL_PATH );
	}
	$page = get_page_by_path( trim( $p, '/' ) );
	return $page ? (int) $page->ID : 0;
}

/** Is a full SEO plugin handling schema and meta? */
function mjsair_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		return 'rankmath';
	}
	if ( defined( 'AIOSEO_VERSION' ) ) {
		return 'aioseo';
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return 'seopress';
	}
	return '';
}

/** Only live, public sites are pinged and advertised. */
function mjsair_is_live() {
	return (bool) get_option( 'blog_public' ) && 'production' === wp_get_environment_type();
}
