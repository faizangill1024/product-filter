<?php
/**
 * Plugin Name: Product Filter AJAX
 * Plugin URI:  https://example.com/universal-ajax-filter-lite
 * Description: Minimal demo plugin showing an AJAX‑powered, shortcode‑based post/product filter that works with any taxonomy (categories, tags, product attributes, etc.). Built for the Upwork screening challenge.
 * Version:     0.1.0
 * Author:      Faizan
 * License:     GPL‑2.0+
 * Text Domain: uafl
 */

if ( ! defined( 'ABSPATH' ) ) {
	return; // Exit if accessed directly.
}

/**
 * Enqueue JS/CSS only when the shortcode is present on the page for maximum performance.
 */
function uafl_enqueue_assets() {
	// The handle is set on enqueue in the shortcode callback to avoid front‑loading assets site‑wide.
}
add_action( 'wp_enqueue_scripts', 'uafl_enqueue_assets' );

/**
 * Shortcode: [uafl_filter post_type="post" taxonomy="category" per_page="10"]
 */
function uafl_filter_shortcode( $atts ) {
	$atts = shortcode_atts( [
		'post_type' => 'post',          // post, product, course …
		'taxonomy'  => 'category',      // category, tag, pa_color …
		'per_page'  => 10,
	], $atts, 'uafl_filter' );

	$handle = 'uafl-filter';
	$src    = plugin_dir_url( __FILE__ ) . 'uafl.js';
	wp_enqueue_script( $handle, $src, [ 'jquery' ], '0.1.0', true );
	wp_localize_script( $handle, 'uafl', [
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'uafl_nonce' ),
		'postType' => esc_attr( $atts['post_type'] ),
		'taxonomy' => esc_attr( $atts['taxonomy'] ),
		'perPage'  => (int) $atts['per_page'],
	] );

	// Build a simple dropdown of terms (top‑level only for demo brevity).
	$terms = get_terms( [
		'taxonomy'   => $atts['taxonomy'],
		'hide_empty' => false,
		'parent'     => 0,
	] );

	ob_start();
	?>
	<div class="uafl-wrap" data-handle="<?php echo esc_attr( $handle ); ?>">
		<form class="uafl-form">
			<select name="term" class="uafl-term">
				<option value=""><?php esc_html_e( 'Any', 'uafl' ); ?></option>
				<?php foreach ( $terms as $term ) : ?>
					<option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="s" class="uafl-search" placeholder="<?php esc_attr_e( 'Search…', 'uafl' ); ?>">
			<button type="submit" class="uafl-submit"><?php esc_html_e( 'Filter', 'uafl' ); ?></button>
		</form>
		<ul class="uafl-results"></ul>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'uafl_filter', 'uafl_filter_shortcode' );

/**
 * AJAX handler — returns a JSON payload with rendered list items.
 */
function uafl_ajax_do_filter() {
	check_ajax_referer( 'uafl_nonce', 'nonce' );

	$post_type = sanitize_key( $_POST['post_type'] ?? 'post' );
	$taxonomy  = sanitize_key( $_POST['taxonomy'] ?? 'category' );
	$term_id   = absint( $_POST['term'] ?? 0 );
	$search    = sanitize_text_field( $_POST['s'] ?? '' );
	$paged     = absint( $_POST['paged'] ?? 1 );
	$per_page  = absint( $_POST['per_page'] ?? 10 );

	$args = [
		'post_type'      => $post_type,
		'posts_per_page' => $per_page,
		'paged'          => max( 1, $paged ),
		'no_found_rows'  => true,             // Huge perf win — we handle pagination client‑side.
		'suppress_filters' => false,          // Allow 3rd‑party hooks; useful when demonstrating compatibility.
		'fields'         => 'ids',            // Return only IDs to minimise memory usage.
		's'              => $search,
	];

	if ( $term_id ) {
		$args['tax_query'] = [ [
			'taxonomy' => $taxonomy,
			'field'    => 'term_id',
			'terms'    => $term_id,
		] ];
	}

	$query = new WP_Query( $args );

	$html = '';
	foreach ( $query->posts as $post_id ) {
		$html .= '<li class="uafl-item"><a href="' . esc_url( get_permalink( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a></li>';
	}

	wp_send_json_success( [
		'html'     => $html ?: '<li>' . __( 'No results found.', 'uafl' ) . '</li>',
		'max_page' => $query->max_num_pages,
	] );
}
add_action( 'wp_ajax_uafl_filter',        'uafl_ajax_do_filter' );
add_action( 'wp_ajax_nopriv_uafl_filter', 'uafl_ajax_do_filter' );

/* -------------------------------------------------------------------------
 * Inline JS — kept in the same file for the demo; in production split to a
 * separate file and add a build step (Rollup/ESBuild) for tree‑shaking.
 * ------------------------------------------------------------------------- */
add_action( 'wp_print_footer_scripts', function () {
	?>
	<script id="uafl-inline-js" type="text/javascript">
		jQuery( function ( $ ) {
			const cfg   = window.uafl || {};
			const wrap  = $( '.uafl-wrap' );
			if ( ! wrap.length ) { return; }

			function fetchResults( page = 1 ) {
				$.post( cfg.ajaxUrl, {
					action:    'uafl_filter',
					nonce:     cfg.nonce,
					post_type: cfg.postType,
					taxonomy:  cfg.taxonomy,
					per_page:  cfg.perPage,
					paged:     page,
					term:      wrap.find( '.uafl-term' ).val(),
					s:         wrap.find( '.uafl-search' ).val().trim(),
				}, function ( res ) {
					if ( res.success ) {
						wrap.find( '.uafl-results' ).html( res.data.html );
					}
				} );
			}

			wrap.on( 'submit', '.uafl-form', function ( e ) {
				e.preventDefault();
				fetchResults();
			} );

			// Initial load for UX.
			fetchResults();
		} );
	</script>
	<style id="uafl-inline-css">
		.uafl-wrap{margin:1em 0}
		.uafl-form{display:flex;gap:8px;margin-bottom:1em}
		.uafl-results{list-style:none;padding:0;margin:0}
		.uafl-item{margin:0 0 6px}
	</style>
	<?php
} );
