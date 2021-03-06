<?php

//require __DIR__ . '/inc/optimize.php';
require __DIR__ . '/inc/pwa.php';
require __DIR__ . '/inc/class-asset-seeker.php';

/**
 * Setup Theme.
 */
function aetherium_setup() {
	add_editor_style( get_stylesheet_uri() );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );

	add_theme_support( 'custom-logo', array(
		'height'      => 150,
		'width'       => 150,
		'flex-width'  => true,
		'flex-height' => true,
	) );

	add_theme_support( 'html5', array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
	) );
}

add_action( 'after_setup_theme', 'aetherium_setup' );


/**
 * Register assets.
 */
function aetherium_enqueue_scripts() {
	wp_enqueue_style( 'vendor', get_theme_file_uri( 'dist/vendor.css' ), [], '0.0.1' );
	wp_enqueue_style( 'main', get_theme_file_uri( 'dist/main.css' ), [], '0.0.1' );
	wp_enqueue_script( 'wp-api' );
	wp_enqueue_script( 'vendor', get_theme_file_uri( 'dist/vendor.bundle.js' ), [ 'wp-api' ], '0.0.1', true );
	wp_enqueue_script( 'main', get_theme_file_uri( 'dist/main.bundle.js' ), [ 'vendor' ], '0.0.1', true );

	/**
	 * @var WP_Rewrite $wp_rewrite
	 */
	global $wp_rewrite;
	$data = [
		'isUserLoggedIn'      => is_user_logged_in(),
		'permastructs'        => aetherium_get_permastructs(),
		'postsPerPage'        => absint( get_option( 'posts_per_page' ) ),
		'pageForPosts'        => absint( get_option( 'page_for_posts' ) ),
		'pageOnFront'         => absint( get_option( 'page_on_front' ) ),
		'useVerbosePageRules' => $wp_rewrite->use_verbose_page_rules
	];
	$js   = sprintf( 'window.themeSettings = %s;', wp_json_encode( $data ) );
	wp_script_add_data( 'wp-api', 'data', $js );
}

add_action( 'wp_enqueue_scripts', 'aetherium_enqueue_scripts' );

function aetherium_setup_assets_cache() {
	if ( $assets = get_transient( 'aetherium_assets_check' ) ) {
		return $assets;
	}
	$seeker = new Assets_Seeker();
	$assets = $seeker->get_assets();
	update_option( 'aetherium_assets', $assets );
	set_transient( 'aetherium_assets_check', current_time( 'timestamp' ), HOUR_IN_SECONDS );
}

add_action( 'wp_enqueue_scripts', 'aetherium_setup_assets_cache', 9999 );

/**
 * @param WP_REST_Response $response
 *
 * @return WP_REST_Response
 */
function aetherium_wp_api_setting( WP_REST_Response $response ) {
	$data                             = $response->get_data();
	$data['authentication']['cookie'] = [
		'root'          => esc_url_raw( get_rest_url() ),
		'nonce'         => ( wp_installing() && ! is_multisite() ) ? '' : wp_create_nonce( 'wp_rest' ),
		'versionString' => 'wp/v2/',
	];
	$response->set_data( $data );

	return $response;
}

add_filter( 'rest_index', 'aetherium_wp_api_setting' );

/**
 * Permastruct Lists.
 *
 * @return array
 */
function aetherium_get_permastructs() {
	/**
	 * @var WP_Rewrite $wp_rewrite
	 */
	global $wp_rewrite;

	if ( get_option( 'page_for_posts' ) ) {
		$home_structs = [
			'front-page' => '/',
			'home'       => get_page_uri( get_option( 'page_for_posts' ) )
		];
	} else {
		$home_structs = [
			'home' => '/'
		];
	}

	$extra_permastructs = array_map( function ( $permastruct ) {
		return $permastruct['struct'];
	}, $wp_rewrite->extra_permastructs );

	$permastructs = [
		'search' => $wp_rewrite->get_search_permastruct(),
		'author' => $wp_rewrite->get_author_permastruct(),
		'date'   => $wp_rewrite->get_date_permastruct(),
		'month'  => $wp_rewrite->get_month_permastruct(),
		'year'   => $wp_rewrite->get_year_permastruct(),
	];

	if ( $wp_rewrite->use_verbose_page_rules ) {

		$permastructs['page'] = $wp_rewrite->get_page_permastruct();
		$permastructs['post'] = $wp_rewrite->permalink_structure;

	} else {
		$permastructs['post'] = $wp_rewrite->permalink_structure;
		$permastructs['page'] = $wp_rewrite->get_page_permastruct();
	}

	$permastructs = array_merge( $extra_permastructs, $home_structs, $permastructs );

	$structs = [];
	foreach ( $permastructs as $key => $value ) {
		$struct = trim( preg_replace( '/%([^\/]+)%/', ':$1', $value ), '/\\' );
		$struct = str_replace(
			[
				':year',
				':monthnum',
				':day',
				':post_id'
			],
			[
				':year(\\d{4})',
				':monthnum(\\d{1,2})',
				':day(\\d{1,2})',
				':post_id(\\d+)'
			],
			$struct
		);

		// for singular
		if ( in_array( $key, get_post_types( [ 'public' => true ] ) ) ) {
			$post_type = get_post_type_object( $key );

			// for pages.
			if ( $post_type->hierarchical ) {
				$structs[] = [
					'name' => $key,
					'path' => untrailingslashit( '/' . $struct ) . '(.+?)' . '/(\\d*)?'
				];
				continue;
			}

			//for posts.
			$structs[] = [
				'name' => $key,
				'path' => untrailingslashit( '/' . $struct ) . '/(\\d*)?'
			];
			continue;
		}
		// for hierarchical taxonomies.
		if ( in_array( $key, get_taxonomies( [ 'public' => true ] ) ) ) {
			$taxonomy = get_taxonomy( $key );
			if ( $taxonomy->hierarchical ) {
				$structs[] = [
					'name' => $key,
					'path' => untrailingslashit( '/' . $struct ) . '(.+?)' . '/page/:page(\\d*)?',
				];
				$structs[] = [
					'name' => $key,
					'path' => untrailingslashit( '/' . $struct ) . '(.+?)',
				];
				continue;
			}
		}
		//for archives.
		$structs[] = [
			'name' => $key,
			'path' => untrailingslashit( '/' . $struct ) . '/page/:page(\\d*)?'
		];
		$structs[] = [
			'name' => $key,
			'path' => untrailingslashit( '/' . $struct ),
		];
	}

	return $structs;
}
