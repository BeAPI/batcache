<?php
/**
 * Plugin name: Batcache Manager
 * Plugin URI: http://wordpress.org/extend/plugins/batcache/
 * Description: This optional plugin improves Batcache.
 * Author: Andy Skelton
 * Author URI: http://andyskelton.com/
 * Version: 1.5
 *
 * @package Batcache
 */

// Do not load if our advanced-cache.php isn't loaded.
if ( ! isset( $batcache ) || ! is_object( $batcache ) || ! method_exists( $wp_object_cache, 'incr' ) ) {
	return;
}

$batcache->configure_groups();

// Regen home and permalink on posts and pages.
add_action( 'clean_post_cache', 'batcache_post', 10, 2 );

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
