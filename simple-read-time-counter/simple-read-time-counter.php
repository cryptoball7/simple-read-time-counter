<?php
/**
 * Plugin Name: Simple Read Time Counter (SRTC)
 * Description: Prepends an estimated reading time to the start of single posts. Includes a shortcode and developer hooks.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * License: GPLv3
 * Text Domain: srtc
 * Requires at least: 5.2
 * Requires PHP: 7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Block direct access.
	exit;
}

/**
 * Load plugin text domain.
 */
function srtc_load_textdomain() {
	load_plugin_textdomain( 'srtc', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'srtc_load_textdomain' );

/**
 * Default Words Per Minute. Filterable via `srtc_wpm`.
 *
 * @return int
 */
function srtc_wpm() : int {
	$default = 200; // A common average adult silent reading speed.
	return (int) apply_filters( 'srtc_wpm', $default );
}

/**
 * Count words in a UTF-8 safe way.
 *
 * @param string $text
 * @return int
 */
function srtc_count_words( string $text ) : int {
	// Remove HTML and shortcodes first.
	$text = strip_shortcodes( $text );
	$text = wp_strip_all_tags( $text );
	$text = trim( wp_specialchars_decode( $text ) );

	if ( $text === '' ) {
		return 0;
	}

	// Split on any unicode whitespace.
	$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	return $words ? count( $words ) : 0;
}

/**
 * Calculate minutes (rounded up) and raw words.
 *
 * @param string $content
 * @return array{minutes:int,words:int}
 */
function srtc_calculate( string $content ) : array {
	$words = srtc_count_words( $content );
	$wpm   = max( 1, srtc_wpm() );
	$minutes = (int) max( 1, (int) ceil( $words / $wpm ) );

	/**
	 * Filter the computed reading time values before rendering.
	 *
	 * @param array{minutes:int,words:int} $data
	 */
	return apply_filters( 'srtc_calculated', [ 'minutes' => $minutes, 'words' => $words ] );
}

/**
 * Build the HTML snippet. Filterable via `srtc_output_html` and `srtc_label`.
 *
 * @param int $minutes
 * @param int $words
 * @return string
 */
function srtc_render_html( int $minutes, int $words ) : string {
	$label = apply_filters( 'srtc_label', __( 'Read time', 'srtc' ) );

	// Human-friendly minute text.
	$minute_text = ( 1 === $minutes )
		? _x( '1 min', 'one minute read', 'srtc' )
		: sprintf( _x( '%d mins', 'x minutes read', 'srtc' ), $minutes );

	$html  = '<div class="srtc-readtime" aria-label="' . esc_attr__( 'Estimated read time', 'srtc' ) . '">';
	$html .= '<span class="srtc-label">' . esc_html( $label ) . ':</span> ';
	$html .= '<strong class="srtc-time">' . esc_html( $minute_text ) . '</strong>';
	$html .= '<span class="screen-reader-text"> (' . intval( $words ) . ' ' . esc_html__( 'words', 'srtc' ) . ')</span>';
	$html .= '</div>';

	/**
	 * Filter the final HTML that will be printed.
	 *
	 * @param string $html
	 * @param int    $minutes
	 * @param int    $words
	 */
	return (string) apply_filters( 'srtc_output_html', $html, $minutes, $words );
}

/**
 * Gate: should we display on this request? Filterable via `srtc_should_display`.
 * Only show on the main query for singular posts (not pages or CPTs by default),
 * and only on the front-end.
 *
 * @return bool
 */
function srtc_should_display() : bool {
	$show = ( ! is_admin() && is_singular( 'post' ) );
	/**
	 * Filter whether SRTC should render for the current request.
	 *
	 * @param bool $show
	 */
	return (bool) apply_filters( 'srtc_should_display', $show );
}

/**
 * Prepend read-time to content.
 *
 * @param string $content
 * @return string
 */
function srtc_prepend_to_content( string $content ) : string {
	if ( ! srtc_should_display() ) {
		return $content;
	}

	$calc = srtc_calculate( $content );
	$top  = srtc_render_html( (int) $calc['minutes'], (int) $calc['words'] );

	return $top . $content;
}
add_filter( 'the_content', 'srtc_prepend_to_content', 9 );

/**
 * Shortcode: [read_time] or [read_time before="Reading time: "]
 *
 * @param array $atts
 * @param string|null $content
 * @return string
 */
function srtc_shortcode( $atts = [], $content = null ) : string {
	global $post;
	if ( ! $post ) {
		return '';
	}

	$atts = shortcode_atts( [
		'before' => '',
	], $atts, 'read_time' );

	$calc = srtc_calculate( $post->post_content );
	$label = trim( (string) $atts['before'] );

	$minutes = (int) $calc['minutes'];
	$words   = (int) $calc['words'];

	// Simple text-only variant for shortcode contexts.
	$minute_text = ( 1 === $minutes )
		? _x( '1 min', 'one minute read', 'srtc' )
		: sprintf( _x( '%d mins', 'x minutes read', 'srtc' ), $minutes );

	$out = '';
	if ( $label !== '' ) {
		$out .= esc_html( $label ) . ' ';
	}
	$out .= esc_html( $minute_text );

	/**
	 * Filter the shortcode output text.
	 *
	 * @param string $out
	 * @param int    $minutes
	 * @param int    $words
	 */
	return (string) apply_filters( 'srtc_shortcode_output', $out, $minutes, $words );
}
add_shortcode( 'read_time', 'srtc_shortcode' );

/**
 * Minimal CSS for frontend (optional). Can be dequeued/overridden.
 */
function srtc_enqueue_styles() {
	if ( ! srtc_should_display() ) {
		return;
	}
	$css = '.srtc-readtime{display:flex;align-items:center;gap:.35rem;margin:0 0 .75rem 0;font-size:.95em;opacity:.85}.srtc-readtime .srtc-label{font-weight:600;text-transform:uppercase;letter-spacing:.02em}';
	wp_register_style( 'srtc-inline', false );
	wp_enqueue_style( 'srtc-inline' );
	wp_add_inline_style( 'srtc-inline', $css );
}
add_action( 'wp_enqueue_scripts', 'srtc_enqueue_styles' );

/**
 * Add settings link (docs) on the Plugins page.
 * (No settings page; this just explains filters & shortcode.)
 */
function srtc_action_links( array $links ) : array {
	$docs = '<a href="https://example.com" target="_blank" rel="noopener">' . esc_html__( 'Docs', 'srtc' ) . '</a>';
	array_unshift( $links, $docs );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'srtc_action_links' );

/**
 * ===== Developer Notes =====
 * Hooks you can use:
 * - filter `srtc_wpm`                -> change words-per-minute (default 200)
 * - filter `srtc_calculated`         -> modify minutes/words array
 * - filter `srtc_label`              -> change the "Read time" label
 * - filter `srtc_output_html`        -> customize the HTML snippet
 * - filter `srtc_shortcode_output`   -> customize the shortcode output
 * - filter `srtc_should_display`     -> gate display logic
 *
 * Example (in theme's functions.php):
 *   add_filter( 'srtc_wpm', fn() => 225 );
 *   add_filter( 'srtc_label', fn() => __( 'Reading time', 'mytheme' ) );
 */
