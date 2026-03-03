<?php
/**
 * Plugin Name: Batcache Manager
 * Plugin URI: https://wordpress.org/plugins/batcache/
 * Description: Optional plugin that improves Batcache. Batcache uses the WordPress object cache API to store and serve rendered pages (Redis, Memcached, or any compatible backend).
 * Version: 1.6
 * Author: Andy Skelton, Automattic
 * Author URI: https://wordpress.org/plugins/batcache/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Batcache
 */

// Do not load if our advanced-cache.php isn't loaded.
if ( ! isset( $batcache ) || ! is_object( $batcache ) || ! isset( $wp_object_cache ) || ! is_object( $wp_object_cache ) || ! method_exists( $wp_object_cache, 'incr' ) ) {
	return;
}

$batcache->configure_groups();

// Regen home and permalink on posts and pages.
add_action( 'clean_post_cache', 'batcache_post', 10, 2 );

// Admin bar: purge all batcache.
add_action( 'admin_bar_menu', 'batcache_admin_bar_menu', 100 );
add_action( 'init', 'batcache_handle_purge_request', 1 );
add_action( 'admin_notices', 'batcache_purge_admin_notice' );

// Optional: regen permalink on comment post, status change, or edit (uncomment add_action calls as needed).

/**
 * Clears batcache for the given URLs when a post is updated.
 *
 * @param int          $post_id Post ID.
 * @param WP_Post|null $post    Post object. Optional, for backwards compatibility.
 */
function batcache_post( $post_id, $post = null ) {
	global $batcache;

	// Get the post for backwards compatibility with earlier versions of WordPress.
	if ( ! $post ) {
		$post = get_post( $post_id );
	}

	if ( ! $post || 'revision' === $post->post_type || ! in_array( get_post_status( $post_id ), array( 'publish', 'trash' ), true ) ) {
		return;
	}

	$home = trailingslashit( get_option( 'home' ) );
	batcache_clear_url( $home );
	batcache_clear_url( $home . 'feed/' );
	batcache_clear_url( get_permalink( $post_id ) );
}

/**
 * Clears batcache for a given URL by incrementing its version key.
 *
 * @param string $url URL to clear from cache.
 * @return int|false Cache version after increment on success, false on failure.
 */
function batcache_clear_url( $url ) {
	global $batcache, $wp_object_cache;

	if ( empty( $url ) ) {
		return false;
	}

	if ( 0 === strpos( $url, 'https://' ) ) {
		$url = str_replace( 'https://', 'http://', $url );
	}
	if ( 0 !== strpos( $url, 'http://' ) ) {
		$url = 'http://' . $url;
	}

	$url_key = md5( $url );
	wp_cache_add( "{$url_key}_version", 0, $batcache->group );
	$retval = wp_cache_incr( "{$url_key}_version", 1, $batcache->group );

	$batcache_no_remote_group_key = array_search( $batcache->group, (array) $wp_object_cache->no_remote_groups, true );
	if ( false !== $batcache_no_remote_group_key ) {
		// The *_version key needs to be replicated remotely, otherwise invalidation won't work.
		// The race condition here should be acceptable.
		unset( $wp_object_cache->no_remote_groups[ $batcache_no_remote_group_key ] );
		$retval = wp_cache_set( "{$url_key}_version", $retval, $batcache->group );
		$wp_object_cache->no_remote_groups[ $batcache_no_remote_group_key ] = $batcache->group;
	}

	return $retval;
}

/**
 * Flushes all batcache entries (entire batcache group).
 *
 * Requires an object cache that supports flush_group (e.g. Redis with WordPress 6.1+).
 *
 * @since 1.5
 * @return bool True on success, false if flush_group is not supported or failed.
 */
function batcache_flush_all() {
	global $batcache;

	if ( ! isset( $batcache->group ) ) {
		return false;
	}

	if ( ! function_exists( 'wp_cache_flush_group' ) || ! wp_cache_supports( 'flush_group' ) ) {
		return false;
	}

	return wp_cache_flush_group( $batcache->group );
}

/**
 * Adds a "Purge Batcache" node to the admin bar.
 *
 * @since 1.5
 *
 * @param WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance.
 * @return void
 */
function batcache_admin_bar_menu( $wp_admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! function_exists( 'wp_cache_flush_group' ) || ! wp_cache_supports( 'flush_group' ) ) {
		return;
	}

	$purge_url = add_query_arg(
		array(
			'batcache_purge' => '1',
			'_wpnonce'       => wp_create_nonce( 'batcache_purge_all' ),
		),
		is_admin() ? admin_url() : home_url( '/' )
	);

	$wp_admin_bar->add_node(
		array(
			'id'    => 'batcache-purge',
			'title' => __( 'Purge Batcache', 'batcache' ),
			'href'  => $purge_url,
			'meta'  => array(
				'title' => __( 'Purge entire page cache', 'batcache' ),
			),
		)
	);
}

/**
 * Handles the purge request from the admin bar link (nonce verification and redirect).
 *
 * Runs on init so the link works from both front-end and admin.
 *
 * @since 1.5
 * @return void
 */
function batcache_handle_purge_request() {
	if ( ! isset( $_GET['batcache_purge'] ) || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'batcache_purge_all' ) ) {
		return;
	}

	$flushed = batcache_flush_all();

	// Always redirect to admin so the success/error notice can be shown.
	$redirect = add_query_arg( 'batcache_purged', $flushed ? '1' : '0', admin_url() );

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Displays an admin notice after a purge action.
 *
 * @since 1.5
 * @return void
 */
function batcache_purge_admin_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect param we set after purge.
	if ( ! isset( $_GET['batcache_purged'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Value is our own redirect param (0 or 1).
	$success = '1' === sanitize_text_field( wp_unslash( $_GET['batcache_purged'] ) );

	if ( $success ) {
		$message = __( 'Batcache has been purged successfully.', 'batcache' );
		$type    = 'success';
	} else {
		$message = __( 'Batcache purge failed or is not supported by the object cache.', 'batcache' );
		$type    = 'error';
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $type ),
		esc_html( $message )
	);
}
