<?php
/**
 * WP-CLI integration for Batcache Manager.
 *
 * @package Batcache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Batcache.
 */
class Batcache_CLI_Command extends WP_CLI_Command {

	/**
	 * Flush the object cache, or a single object cache group.
	 *
	 * By default, runs `wp_cache_flush()` (entire object cache).
	 *
	 * When `[--group=<name>]` is set, only that group is flushed via `wp_cache_flush_group()`
	 * (Redis or another backend that advertises `flush_group` support).
	 *
	 * ## OPTIONS
	 *
	 * [--group=<name>]
	 * : Object cache group to flush only. Omit to flush the whole object cache.
	 *
	 * ## EXAMPLES
	 *
	 *     # Flush the entire object cache.
	 *     wp batcache flush
	 *
	 *     # Flush one object cache group by name (Redis).
	 *     wp batcache flush --group=batcache --url=https://example.com/
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args {
	 *     Optional. Associative arguments.
	 *
	 *     @type string $group Object cache group name passed as `--group=<name>`.
	 * }
	 * @return void
	 */
	public function flush( $args = [], $assoc_args = [] ): void {
		$group = '';
		if ( isset( $assoc_args['group'] ) && is_string( $assoc_args['group'] ) ) {
			$group = trim( $assoc_args['group'] );
		}

		if ( '' !== $group ) {
			if ( ! function_exists( 'wp_cache_flush_group' ) || ! wp_cache_supports( 'flush_group' ) ) {
				WP_CLI::error( 'Object cache does not support flushing a single group (flush_group).' );
			}

			$ok = wp_cache_flush_group( $group );
			if ( $ok ) {
				WP_CLI::success( sprintf( 'Object cache group "%s" flushed.', $group ) );
				return;
			}

			WP_CLI::error( sprintf( 'Failed to flush object cache group "%s".', $group ) );
		}

		wp_cache_flush();
		WP_CLI::success( 'Object cache flushed.' );
	}
}

