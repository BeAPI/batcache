<?php
/**
 * Example Batcache stats implementation (copy and customize as batcache-stats.php).
 *
 * This is a copy of our Batcache-stats.php file but it is suffixed so that you don't use it by default.
 * You probably don't want huge log files filling up your server by default, but this gives you an idea
 * of how we use this function. We have a separate parsing script that comes along and reads these files
 * and enters them into our internal stats.
 *
 * @package Batcache
 */

if ( ! function_exists( 'batcache_stats' ) ) {
	/**
	 * Logs a stat value to a local file (example implementation).
	 *
	 * Batcache never loads wpdb, so stats are written asynchronously to a file.
	 *
	 * @param string       $name  Stat name.
	 * @param string       $value Stat value.
	 * @param int          $num   Optional. Count. Default 1.
	 * @param string|false $today Optional. Date for filename. Default false (today).
	 * @param string|false $hour  Optional. Hour for log line. Default false (current hour).
	 */
	function batcache_stats( $name, $value, $num = 1, $today = false, $hour = false ) {
		if ( ! $today ) {
			$today = gmdate( 'Y-m-d' );
		}
		if ( ! $hour ) {
			$hour = gmdate( 'Y-m-d H:00:00' );
		}

		// Batcache never loads wpdb so always do this async.
		if ( ! file_exists( '/var/spool/wpcom/extra' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Example: custom stats path outside WP.
			mkdir( '/var/spool/wpcom/extra', 0777 );
		}

		$value          = rawurlencode( $value );
		$stats_filename = "/var/spool/wpcom/extra/{$today}_" . gmdate( 'H-i' );
		if ( ! file_exists( $stats_filename ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Example: custom stats file.
			touch( $stats_filename );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Example: custom stats file.
			chmod( $stats_filename, 0777 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Example: append-only stats log.
		$fp = fopen( $stats_filename, 'a' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Example: append-only stats log.
		fwrite( $fp, "{$hour}\t{$name}\t{$value}\t{$num}" . chr( 10 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Example: append-only stats log.
		fclose( $fp );
	}
}
