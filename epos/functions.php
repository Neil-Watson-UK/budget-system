<?php
/**
 * EPOS — Kadence child theme (EPOS brand).
 *
 * Lives next to the Kadence parent: wp-content/themes/epos + wp-content/themes/kadence
 *
 * @package epos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPOS_THEME_VERSION', '1.0.1' );

/**
 * Load child stylesheet after Kadence global bundle (parent style.css is metadata-only).
 */
function epos_enqueue_styles() {
	wp_enqueue_style(
		'epos-child',
		get_stylesheet_uri(),
		array( 'kadence-global' ),
		EPOS_THEME_VERSION
	);
	wp_enqueue_script(
		'epos-mega-nav',
		get_stylesheet_directory_uri() . '/assets/epos-mega-nav.js',
		array(),
		EPOS_THEME_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'epos_enqueue_styles', 30 );

/**
 * Full-screen dim behind dropdowns (below mega panels).
 */
function epos_nav_overlay_markup() {
	echo '<div id="epos-nav-overlay" class="epos-nav-overlay" aria-hidden="true"></div>' . "\n";
}
add_action( 'wp_body_open', 'epos_nav_overlay_markup', 5 );

/**
 * In Appearance → Menus → CSS classes, add "epos-mega" on a top-level item to enable Kadence mega-column layout
 * (adds kadence-menu-mega-enabled).
 */
function epos_mega_menu_class( $classes, $item, $args, $depth ) {
	if ( isset( $args->theme_location ) && 'primary' === $args->theme_location && 0 === (int) $depth && in_array( 'epos-mega', $classes, true ) && ! in_array( 'kadence-menu-mega-enabled', $classes, true ) ) {
		$classes[] = 'kadence-menu-mega-enabled';
	}
	return $classes;
}
add_filter( 'nav_menu_css_class', 'epos_mega_menu_class', 20, 4 );

/**
 * Append EPOS :root tokens to Kadence's inline CSS so they follow Customizer-generated rules.
 */
function epos_append_brand_css_vars( $css ) {
	$extra = <<<'CSS'
:root {
	--global-palette1: #44ead6;
	--global-palette2: #00353d;
	--global-palette3: #111827;
	--global-palette4: #374151;
	--global-palette5: #6b7280;
	--global-palette6: #e5e7eb;
	--global-palette7: #f3f4f7;
	--global-palette8: #ffffff;
	--global-palette9: #ffffff;
	--global-body-font-family: "EPOS BASIS", inter, system-ui, sans-serif;
	--global-heading-font-family: "EPOS BASIS Light", "EPOS BASIS", sans-serif;
	--global-primary-nav-font-family: "EPOS BASIS", inter, system-ui, sans-serif;
}
CSS;

	return $css . "\n/* EPOS brand tokens */\n" . trim( $extra );
}
add_filter( 'kadence_dynamic_css', 'epos_append_brand_css_vars', 999 );
