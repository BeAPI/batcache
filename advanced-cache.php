<?php
/**
 * Plugin Name: Batcache (Advanced Cache Drop-in)
 * Plugin URI: https://wordpress.org/plugins/batcache/
 * Description: Page cache drop-in using the WordPress object cache API. Caches full HTML responses and serves them on cache hit, reducing database and PHP load. Loaded automatically when WP_CACHE is true. Handles cache key generation, output buffering, cache variants (e.g. by user-agent), and cache expiration. Works with any object cache backend (Memcached, Redis, etc.).
 * Version: 1.5
 * Author: Andy Skelton
 * Author URI: https://andyskelton.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * This file is the advanced-cache drop-in. It is loaded by WordPress when
 * WP_CACHE is true (typically after being copied to wp-content/advanced-cache.php
 * by the Batcache Manager plugin or manually). It does not run as a standalone
 * plugin; the main plugin file is batcache.php.
 *
 * @package Batcache
 */

if ( is_readable( __DIR__ . '/batcache-stats.php' ) ) {
	require_once __DIR__ . '/batcache-stats.php';
}

if ( ! function_exists( 'batcache_stats' ) ) {
	/**
	 * No-op stats implementation when batcache-stats.php is not present.
	 *
	 * @param string $name  Stat name.
	 * @param string $value Stat value.
	 * @param int    $num   Optional. Count. Default 1.
	 * @param mixed  $today Optional. Default false.
	 * @param mixed  $hour  Optional. Default false.
	 */
	function batcache_stats( $name, $value, $num = 1, $today = false, $hour = false ) { }
}

// Batcache drop-in loaded.

/**
 * Cancels the current batcache output (e.g. to bypass cache for this request).
 */
function batcache_cancel() {
	global $batcache;

	if ( is_object( $batcache ) ) {
		$batcache->cancel = true;
	}
}

// Variants can be set by functions which use early-set globals like $_SERVER to run simple tests.
// Functions defined in WordPress, plugins, and themes are not available and MUST NOT be used.
// Example: vary_cache_on_function('return preg_match("/feedburner/i", $_SERVER["HTTP_USER_AGENT"]);');
// This will cause batcache to cache a variant for requests from Feedburner.
// Tips for writing $callback: do not use theme/plugin functions (fatal); only is_admin() and is_multisite() are safe.
/**
 * Registers a variant callback to split cache by return value (e.g. by user-agent).
 *
 * @param string $function PHP code string for a return expression (used in eval). Must reference $_.
 */
function vary_cache_on_function( $function ) { // phpcs:ignore Generic.NamingConventions.ReservedKeywordUsedAsFunctionName.Found -- API.
	global $batcache;

	if ( preg_match( '/include|require|echo|(?<!s)print|dump|export|open|sock|unlink|`|eval/i', $function ) ) {
		die( 'Illegal word in variant determiner.' );
	}

	if ( ! preg_match( '/\$_/', $function ) ) {
		die( 'Variant determiner should refer to at least one $_ variable.' );
	}

	$batcache->add_variant( $function );
}

/**
 * Batcache handler: output buffering, cache keys, and cache serve logic.
 *
 * phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital -- Legacy lowercase name for backward compatibility.
 */
class batcache {

	/**
	 * Expire batcache items aged this many seconds (zero to disable batcache).
	 *
	 * @var int
	 */
	public $max_age = 300;

	/**
	 * Zero disables sending buffers to remote datacenters (req/sec is never sent).
	 *
	 * @var int
	 */
	public $remote = 0;

	/**
	 * Only batcache a page after it is accessed this many times (two or more).
	 *
	 * @var int
	 */
	public $times = 2;
	/**
	 * In this many seconds (zero to ignore and use batcache immediately).
	 *
	 * @var int
	 */
	public $seconds = 120;

	/**
	 * Name of object cache group. Change to simulate a cache flush.
	 *
	 * @var string
	 */
	public $group = 'batcache';

	/**
	 * If you conditionally serve different content, put the variable values here.
	 *
	 * @var array
	 */
	public $unique = array();

	/**
	 * Variant callback strings. Return value is added to $unique.
	 *
	 * @var array
	 */
	public $vary = array();

	/**
	 * Headers as name=>value or name=>array(values). Sent with every cached response.
	 *
	 * @var array
	 */
	public $headers = array();

	/**
	 * Status header line.
	 *
	 * @var string|false
	 */
	public $status_header = false;
	/**
	 * Set true to enable redirect caching.
	 *
	 * @var bool
	 */
	public $cache_redirects = false;
	/**
	 * Set to the response code during a redirect.
	 *
	 * @var int|false
	 */
	public $redirect_status = false;
	/**
	 * Set to the redirect location.
	 *
	 * @var string|false
	 */
	public $redirect_location = false;

	/**
	 * Is it ok to return stale cached response when updating the cache?
	 *
	 * @var bool
	 */
	public $use_stale = true;
	/**
	 * These headers will never be cached. Apply strtolower.
	 *
	 * @var array
	 */
	public $uncached_headers = array( 'transfer-encoding' );

	/**
	 * Set false to hide the batcache info HTML comment.
	 *
	 * @var bool
	 */
	public $debug = true;

	/**
	 * Set false to disable Last-Modified and Cache-Control headers.
	 *
	 * @var bool
	 */
	public $cache_control = true;

	/**
	 * Set true to cancel the output buffer (e.g. via batcache_cancel()).
	 *
	 * @var bool
	 */
	public $cancel = false;

	/**
	 * Cookie name to check.
	 *
	 * @var string
	 */
	public $cookie = '';
	/**
	 * Names of cookies that, if present, do not bypass cache.
	 *
	 * @var array
	 */
	public $noskip_cookies = array( 'wordpress_test_cookie' );
	/**
	 * Whitelist of HTTP Origin host[:port] names allowed as cache variations.
	 *
	 * @var array
	 */
	public $cacheable_origin_hostnames = array();

	/**
	 * Current Origin header.
	 *
	 * @var string|null
	 */
	public $origin = null;
	/**
	 * Query args.
	 *
	 * @var array
	 */
	public $query = array();
	/**
	 * Query args to ignore when building cache key.
	 *
	 * @var array
	 */
	public $ignored_query_args = array();
	/**
	 * Genlock.
	 *
	 * @var bool|int
	 */
	public $genlock = false;
	/**
	 * Whether to regenerate cache.
	 *
	 * @var bool
	 */
	public $do = false;

	/**
	 * Cached entry.
	 *
	 * @var array
	 */
	public $cache = array();
	/**
	 * Cache key.
	 *
	 * @var string
	 */
	public $key = '';
	/**
	 * Key components.
	 *
	 * @var array
	 */
	public $keys = array();
	/**
	 * Permalink.
	 *
	 * @var string
	 */
	public $permalink = '';
	/**
	 * Position.
	 *
	 * @var int
	 */
	public $pos = 0;
	/**
	 * Request key.
	 *
	 * @var string
	 */
	public $req_key = '';
	/**
	 * Request count.
	 *
	 * @var int
	 */
	public $requests = 0;
	/**
	 * HTTP status code.
	 *
	 * @var int|null
	 */
	public $status_code = null;
	/**
	 * URL key.
	 *
	 * @var string
	 */
	public $url_key = '';
	/**
	 * URL version.
	 *
	 * @var int|null
	 */
	public $url_version = null;

	/**
	 * Constructor.
	 *
	 * @param array|null $settings Optional. Key-value overrides for defaults.
	 */
	public function __construct( $settings ) {
		if ( is_array( $settings ) ) {
			foreach ( $settings as $k => $v ) {
				$this->$k = $v;
			}
		}
	}

	/**
	 * Whether the request is over HTTPS.
	 *
	 * @return bool
	 */
	public function is_ssl() {
		if ( isset( $_SERVER['HTTPS'] ) ) {
			if ( 'on' === strtolower( $_SERVER['HTTPS'] ) ) {
				return true;
			}
			if ( '1' === $_SERVER['HTTPS'] ) {
				return true;
			}
		} elseif ( isset( $_SERVER['SERVER_PORT'] ) && '443' === $_SERVER['SERVER_PORT'] ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether the client Accept header requests only JSON.
	 *
	 * @return bool
	 */
	public function client_accepts_only_json() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}

		$is_json_only = false;

		foreach ( explode( ',', $_SERVER['HTTP_ACCEPT'] ) as $mime_type ) {
			$pos = strpos( $mime_type, ';' );
			if ( false !== $pos ) {
				$mime_type = substr( $mime_type, 0, $pos );
			}

			$mime_type = trim( $mime_type );

			if ( '/json' === substr( $mime_type, -5 ) || '+json' === substr( $mime_type, -5 ) ) {
				$is_json_only = true;
				continue;
			}

			return false;
		}

		return $is_json_only;
	}

	/**
	 * Whether the given Origin header is in the cacheable whitelist.
	 *
	 * @param string $origin Origin header value.
	 * @return bool
	 */
	public function is_cacheable_origin( $origin ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Loaded before WordPress; wp_parse_url may be unavailable.
		$parsed_origin = function_exists( 'wp_parse_url' ) ? wp_parse_url( $origin ) : parse_url( $origin );

		if ( false === $parsed_origin ) {
			return false;
		}

		$origin_host   = ! empty( $parsed_origin['host'] ) ? strtolower( $parsed_origin['host'] ) : null;
		$origin_scheme = ! empty( $parsed_origin['scheme'] ) ? strtolower( $parsed_origin['scheme'] ) : null;
		$origin_port   = ! empty( $parsed_origin['port'] ) ? $parsed_origin['port'] : null;

		return $origin
			&& $origin_host
			&& ( 'http' === $origin_scheme || 'https' === $origin_scheme )
			&& ( null === $origin_port || 80 === $origin_port || 443 === $origin_port )
			&& in_array( $origin_host, $this->cacheable_origin_hostnames, true );
	}

	/**
	 * Filters status_header to capture status and code.
	 *
	 * @param string $status_header Header line.
	 * @param int    $status_code   HTTP status code.
	 * @return string
	 */
	public function status_header( $status_header, $status_code ) {
		$this->status_header = $status_header;
		$this->status_code   = $status_code;

		return $status_header;
	}

	/**
	 * Filters wp_redirect_status to capture redirect status and location.
	 *
	 * @param int    $status   Redirect status code.
	 * @param string $location Redirect location.
	 * @return int
	 */
	public function redirect_status( $status, $location ) {
		if ( $this->cache_redirects ) {
			$this->redirect_status   = $status;
			$this->redirect_location = $location;
		}

		return $status;
	}

	/**
	 * Sends merged headers to the client.
	 *
	 * @param array $headers1 First set of headers.
	 * @param array $headers2 Optional. Second set. Default empty.
	 */
	public function do_headers( $headers1, $headers2 = array() ) {
		// Merge the arrays of headers into one.
		$headers = array();
		$keys    = array_unique( array_merge( array_keys( $headers1 ), array_keys( $headers2 ) ) );
		foreach ( $keys as $k ) {
			$headers[ $k ] = array();
			if ( isset( $headers1[ $k ] ) && isset( $headers2[ $k ] ) ) {
				$headers[ $k ] = array_merge( (array) $headers2[ $k ], (array) $headers1[ $k ] );
			} elseif ( isset( $headers2[ $k ] ) ) {
				$headers[ $k ] = (array) $headers2[ $k ];
			} else {
				$headers[ $k ] = (array) $headers1[ $k ];
			}
			$headers[ $k ] = array_unique( $headers[ $k ] );
		}
		// These headers take precedence over any previously sent with the same names.
		foreach ( $headers as $k => $values ) {
			$clobber = true;
			foreach ( $values as $v ) {
				header( "$k: $v", $clobber );
				$clobber = false;
			}
		}
	}

	/**
	 * Configures object cache groups (no-remote, global).
	 */
	public function configure_groups() {
		// Configure the object cache client.
		if ( ! $this->remote ) {
			if ( function_exists( 'wp_cache_add_no_remote_groups' ) ) {
				wp_cache_add_no_remote_groups( array( $this->group ) );
			}
		}
		if ( function_exists( 'wp_cache_add_global_groups' ) ) {
			wp_cache_add_global_groups( array( $this->group ) );
		}
	}

	/**
	 * Returns (and optionally echoes) elapsed time since $timestart.
	 *
	 * Defined here because WordPress timer_stop() calls number_format_i18n() which may not be loaded.
	 *
	 * @param int $display   Optional. 1 to echo. Default 0.
	 * @param int $precision Optional. Decimal places. Default 3.
	 * @return string Formatted elapsed time.
	 */
	public function timer_stop( $display = 0, $precision = 3 ) {
		global $timestart, $timeend;

		$mtime = microtime();
		$mtime = explode( ' ', $mtime );
		$mtime = $mtime[1] + $mtime[0];
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required for WordPress timer_stop() compatibility.
		$timeend   = $mtime;
		$timetotal = $timeend - $timestart;
		$r         = number_format( $timetotal, $precision );
		if ( $display ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Numeric timer value.
			echo $r;
		}
		return $r;
	}

	/**
	 * Output buffer callback: stores page in cache and returns output.
	 *
	 * @param string $output Buffered output.
	 * @return string|null Output or null when skipping.
	 */
	public function ob( $output ) {
		// PHP5 and objects disappearing before output buffers?
		wp_cache_init();

		// Remember, $wp_object_cache was clobbered in wp-settings.php so we have to repeat this.
		$this->configure_groups();

		if ( false !== $this->cancel ) {
			wp_cache_delete( "{$this->url_key}_genlock", $this->group );
			return $output;
		}

		// Do not batcache blank pages unless they are HTTP redirects.
		$output = trim( $output );
		if ( '' === $output && ( ! $this->redirect_status || ! $this->redirect_location ) ) {
			wp_cache_delete( "{$this->url_key}_genlock", $this->group );
			return null;
		}

		// Do not cache 5xx responses.
		if ( isset( $this->status_code ) && 5 === (int) ( $this->status_code / 100 ) ) {
			wp_cache_delete( "{$this->url_key}_genlock", $this->group );
			return $output;
		}

		$this->do_variants( $this->vary );
		$this->generate_keys();

		// Construct and save the batcache.
		$this->cache = array(
			'output'            => $output,
			'time'              => isset( $_SERVER['REQUEST_TIME'] ) ? $_SERVER['REQUEST_TIME'] : time(),
			'timer'             => $this->timer_stop( 0, 3 ),
			'headers'           => array(),
			'status_header'     => $this->status_header,
			'redirect_status'   => $this->redirect_status,
			'redirect_location' => $this->redirect_location,
			'version'           => $this->url_version,
		);

		foreach ( headers_list() as $header ) {
			list($k, $v)                    = array_map( 'trim', explode( ':', $header, 2 ) );
			$this->cache['headers'][ $k ][] = $v;
		}

		if ( ! empty( $this->cache['headers'] ) && ! empty( $this->uncached_headers ) ) {
			foreach ( $this->uncached_headers as $header ) {
				unset( $this->cache['headers'][ $header ] );
			}
		}

		foreach ( $this->cache['headers'] as $header => $values ) {
			// Do not cache if cookies were set.
			if ( 'set-cookie' === strtolower( $header ) ) {
				wp_cache_delete( "{$this->url_key}_genlock", $this->group );
				return $output;
			}

			foreach ( (array) $values as $value ) {
				if ( preg_match( '/^Cache-Control:.*max-?age=(\d+)/i', "$header: $value", $matches ) ) {
					$this->max_age = intval( $matches[1] );
				}
			}
		}

		$this->cache['max_age'] = $this->max_age;

		wp_cache_set( $this->key, $this->cache, $this->group, $this->max_age + $this->seconds + 30 );

		// Unlock regeneration.
		wp_cache_delete( "{$this->url_key}_genlock", $this->group );

		if ( $this->cache_control ) {
			// Don't clobber Last-Modified header if already set, e.g. by WP::send_headers().
			if ( ! isset( $this->cache['headers']['Last-Modified'] ) ) {
				header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $this->cache['time'] ) . ' GMT', true );
			}
			if ( ! isset( $this->cache['headers']['Cache-Control'] ) ) {
				header( "Cache-Control: max-age=$this->max_age, must-revalidate", false );
			}
		}

		$this->do_headers( $this->headers );

		// Add some debug info just before </head>.
		if ( $this->debug ) {
			$this->add_debug_just_cached();
		}

		// Pass output to next ob handler.
		batcache_stats( 'batcache', 'total_page_views' );
		return $this->cache['output'];
	}

	/**
	 * Adds a variant callback string.
	 *
	 * @param string $function PHP code string for variant (used in eval).
	 *
	 * phpcs:ignore Generic.NamingConventions.ReservedKeywordUsedAsFunctionName.Found -- Parameter name matches API.
	 */
	public function add_variant( $function ) {
		$key                = md5( $function );
		$this->vary[ $key ] = $function;
	}

	/**
	 * Runs variant callbacks and populates $this->keys.
	 *
	 * @param array|false $dimensions Optional. Variant dimensions from cache or false.
	 */
	public function do_variants( $dimensions = false ) {
		// Called without arguments early in the page load, then with arguments during the OB handler.
		if ( false === $dimensions ) {
			$dimensions = wp_cache_get( "{$this->url_key}_vary", $this->group );
		} else {
			wp_cache_set( "{$this->url_key}_vary", $dimensions, $this->group, $this->max_age + 10 );
		}

		if ( is_array( $dimensions ) ) {
			ksort( $dimensions );
			foreach ( $dimensions as $key => $function ) { // phpcs:ignore Generic.NamingConventions.ReservedKeywordUsedAsFunctionName.Found -- Variant callback string.
				// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Variant callbacks require eval in early load context.
				eval( '$fun = function() { ' . $function . '; };' );
				$value              = call_user_func( $fun );
				$this->keys[ $key ] = $value;
			}
		}
	}

	/**
	 * Generates cache key from $this->keys.
	 */
	public function generate_keys() {
		// ksort($this->keys); uncomment when traffic is slow.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Cache key; no user input.
		$this->key     = md5( serialize( $this->keys ) );
		$this->req_key = $this->key . '_reqs';
	}

	/**
	 * Appends "just cached" debug HTML to output.
	 */
	public function add_debug_just_cached() {
		$generation = $this->cache['timer'];
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Debug output; internal data.
		$bytes = strlen( serialize( $this->cache ) );
		$html  = <<<HTML
<!--
	generated in $generation seconds
	$bytes bytes batcached for {$this->max_age} seconds
-->

HTML;
		$this->add_debug_html_to_output( $html );
	}

	/**
	 * Appends "served from cache" debug HTML to output.
	 */
	public function add_debug_from_cache() {
		$seconds_ago = time() - $this->cache['time'];
		$generation  = $this->cache['timer'];
		$serving     = $this->timer_stop( 0, 3 );
		$expires     = $this->cache['max_age'] - time() + $this->cache['time'];
		$html        = <<<HTML
<!--
	generated $seconds_ago seconds ago
	generated in $generation seconds
	served from batcache in $serving seconds
	expires in $expires seconds
-->

HTML;
		$this->add_debug_html_to_output( $html );
	}

	/**
	 * Injects debug HTML into cached output before </head>.
	 *
	 * @param string $debug_html HTML fragment to inject.
	 */
	public function add_debug_html_to_output( $debug_html ) {
		// Casing on the Content-Type header is inconsistent.
		foreach ( array( 'Content-Type', 'Content-type' ) as $key ) {
			if ( isset( $this->cache['headers'][ $key ][0] ) && 0 !== strpos( $this->cache['headers'][ $key ][0], 'text/html' ) ) {
				return;
			}
		}

		$head_position = strpos( $this->cache['output'], '<head' );
		if ( false === $head_position ) {
			return;
		}
		$this->cache['output'] .= "\n$debug_html";
	}

	/**
	 * Parses query string and stores in $this->query (excluding ignored args).
	 *
	 * @param string $query_string Query string.
	 */
	public function set_query( $query_string ) {
		parse_str( $query_string, $this->query );

		foreach ( $this->ignored_query_args as $arg ) {
			unset( $this->query[ $arg ] );
		}

		// Normalize query parameters for better cache hits.
		ksort( $this->query );
	}
}

global $batcache;
// Pass in the global variable which may be an array of settings to override defaults.
$batcache = new batcache( $batcache );

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	return;
}

// Never batcache interactive scripts or API endpoints.
if ( in_array(
	basename( $_SERVER['SCRIPT_FILENAME'] ),
	array( 'wp-app.php', 'xmlrpc.php', 'wp-cron.php' ),
	true
) ) {
	return;
}

// Never batcache WP javascript generators.
if ( strstr( $_SERVER['SCRIPT_FILENAME'], 'wp-includes/js' ) ) {
	return;
}

// Only cache HEAD and GET requests.
if ( isset( $_SERVER['REQUEST_METHOD'] ) && ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) {
	return;
}

// Never batcache a request with X-WP-Nonce header.
if ( ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
	batcache_stats( 'batcache', 'x_wp_nonce_skip' );
	return;
}

// Never batcache when cookies indicate a cache-exempt visitor.
if ( is_array( $_COOKIE ) && ! empty( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $batcache->cookie ) {
		if ( ! in_array( $batcache->cookie, $batcache->noskip_cookies, true ) && ( 'wp' === substr( $batcache->cookie, 0, 2 ) || 'WordPress' === substr( $batcache->cookie, 0, 9 ) || 'comment_author' === substr( $batcache->cookie, 0, 14 ) ) ) {
			batcache_stats( 'batcache', 'cookie_skip' );
			return;
		}
	}
}

// Never batcache a response for a request with an Origin request header.
// *Unless* that Origin header is in the configured whitelist of allowed origins with restricted schemes and ports.
if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
	if ( ! $batcache->is_cacheable_origin( $_SERVER['HTTP_ORIGIN'] ) ) {
		batcache_stats( 'batcache', 'origin_skip' );
		return;
	}

	$batcache->origin = $_SERVER['HTTP_ORIGIN'];
}

if ( ! include_once WP_CONTENT_DIR . '/object-cache.php' ) {
	return;
}

wp_cache_init(); // Note: wp-settings.php calls wp_cache_init() which clobbers the object made here.

if ( empty( $wp_object_cache ) || ! is_object( $wp_object_cache ) ) {
	return;
}

// Now that the defaults are set, you might want to use different settings under certain conditions.

/*
Example: if your documents have a mobile variant (a different document served by the same URL) you must tell batcache about the variance. Otherwise you might accidentally cache the mobile version and serve it to desktop users, or vice versa.
$batcache->unique['mobile'] = is_mobile_user_agent();
*/

/*
Example: never batcache for this host
if ( $_SERVER['HTTP_HOST'] == 'do-not-batcache-me.com' )
	return;
*/

/*
Example: batcache everything on this host regardless of traffic level
if ( $_SERVER['HTTP_HOST'] == 'always-batcache-me.com' )
	return;
*/

/*
Example: If you sometimes serve variants dynamically (e.g. referrer search term highlighting) you probably don't want to batcache those variants. Remember this code is run very early in wp-settings.php so plugins are not yet loaded. You will get a fatal error if you try to call an undefined function. Either include your plugin now or define a test function in this file.
if ( include_once( 'plugins/searchterm-highlighter.php') && referrer_has_search_terms() )
	return;
*/

// Disabled.
if ( $batcache->max_age < 1 ) {
	return;
}

// Make sure we can increment. If not, turn off the traffic sensor.
if ( ! method_exists( $GLOBALS['wp_object_cache'], 'incr' ) ) {
	$batcache->times = 0;
}

// Necessary to prevent clients using cached version after login cookies set. If this is a problem, comment it out and remove all Last-Modified headers.
header( 'Vary: Cookie', false );

// Things that define a unique page.
if ( isset( $_SERVER['QUERY_STRING'] ) ) {
	$batcache->set_query( $_SERVER['QUERY_STRING'] );
}

$batcache->pos  = strpos( $_SERVER['REQUEST_URI'], '?' );
$batcache->keys = array(
	'host'   => $_SERVER['HTTP_HOST'],
	'method' => $_SERVER['REQUEST_METHOD'],
	'path'   => ( false !== $batcache->pos ) ? substr( $_SERVER['REQUEST_URI'], 0, $batcache->pos ) : $_SERVER['REQUEST_URI'],
	'query'  => $batcache->query,
	'extra'  => $batcache->unique,
);
if ( isset( $batcache->origin ) ) {
	$batcache->keys['origin'] = $batcache->origin;
}

if ( $batcache->is_ssl() ) {
	$batcache->keys['ssl'] = true;
}

// Some plugins return html or json based on the Accept value for the same URL.
if ( $batcache->client_accepts_only_json() ) {
	$batcache->keys['json'] = true;
}

// Recreate the permalink from the URL.
$batcache->permalink = 'http://' . $batcache->keys['host'] . $batcache->keys['path'] . ( isset( $batcache->keys['query']['p'] ) ? '?p=' . $batcache->keys['query']['p'] : '' );
$batcache->url_key   = md5( $batcache->permalink );
$batcache->configure_groups();
$batcache->url_version = (int) wp_cache_get( "{$batcache->url_key}_version", $batcache->group );
$batcache->do_variants();
$batcache->generate_keys();

// Get the batcache.
$batcache->cache = wp_cache_get( $batcache->key, $batcache->group );
$is_cached       = is_array( $batcache->cache ) && isset( $batcache->cache['time'] );
$has_expired     = $is_cached && time() > $batcache->cache['time'] + $batcache->cache['max_age'];

if ( isset( $batcache->cache['version'] ) && $batcache->cache['version'] !== $batcache->url_version ) {
	// Always refresh the cache if a newer version is available.
	$batcache->do = true;
} elseif ( $batcache->seconds < 1 || $batcache->times < 2 ) {
	// Cache is empty or has expired and we're caching all requests.
	$batcache->do = ! $is_cached || $has_expired;
} elseif ( ! $is_cached || time() >= $batcache->cache['time'] + $batcache->max_age - $batcache->seconds ) {
	// No batcache item found, or ready to sample traffic again at the end of the batcache life?
	wp_cache_add( $batcache->req_key, 0, $batcache->group );
	$batcache->requests = wp_cache_incr( $batcache->req_key, 1, $batcache->group );

	if (
		$batcache->requests >= $batcache->times && // Visited enough times.
		(
			! $is_cached || // No cache.
			time() >= $batcache->cache['time'] + $batcache->cache['max_age'] // Or cache expired.
		)
	) {
		wp_cache_delete( $batcache->req_key, $batcache->group );
		$batcache->do = true;
	} else {
		$batcache->do = false;
	}
}

// Obtain cache generation lock.
if ( $batcache->do ) {
	$batcache->genlock = wp_cache_add( "{$batcache->url_key}_genlock", 1, $batcache->group, 10 );
}

if (
	$is_cached && // We have cache.
	! $batcache->genlock && // We have not obtained cache regeneration lock.
	(
		! $has_expired || // Batcached page that hasn't expired.
		( $batcache->do && $batcache->use_stale ) // Regenerating in another request; can use stale cache.
	)
) {
	// Issue redirect if cached and enabled.
	if ( $batcache->cache['redirect_status'] && $batcache->cache['redirect_location'] && $batcache->cache_redirects ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Redirect header vars for this request only.
		$status   = $batcache->cache['redirect_status'];
		$location = $batcache->cache['redirect_location'];
		// From vars.php.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- IIS detection for header format.
		$is_IIS = ( strpos( $_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS' ) !== false || strpos( $_SERVER['SERVER_SOFTWARE'], 'ExpressionDevServer' ) !== false );

		$batcache->do_headers( $batcache->headers );
		if ( $is_IIS ) {
			header( "Refresh: 0;url=$location" );
		} else {
			if ( 'cgi-fcgi' !== php_sapi_name() ) {
				$texts    = array(
					300 => 'Multiple Choices',
					301 => 'Moved Permanently',
					302 => 'Found',
					303 => 'See Other',
					304 => 'Not Modified',
					305 => 'Use Proxy',
					306 => 'Reserved',
					307 => 'Temporary Redirect',
				);
				$protocol = $_SERVER['SERVER_PROTOCOL'];
				if ( 'HTTP/1.1' !== $protocol && 'HTTP/1.0' !== $protocol ) {
					$protocol = 'HTTP/1.0';
				}
				if ( isset( $texts[ $status ] ) ) {
					header( "$protocol $status " . $texts[ $status ] );
				} else {
					header( "$protocol 302 Found" );
				}
			}
			header( "Location: $location" );
		}
		exit;
	}

	// Respect ETags served with feeds.
	$three04 = false;
	if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && isset( $batcache->cache['headers']['ETag'][0] ) && $_SERVER['HTTP_IF_NONE_MATCH'] === $batcache->cache['headers']['ETag'][0] ) {
		$three04 = true;
	} elseif ( $batcache->cache_control && isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		// Respect If-Modified-Since.
		$client_time = strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		if ( isset( $batcache->cache['headers']['Last-Modified'][0] ) ) {
			$cache_time = strtotime( $batcache->cache['headers']['Last-Modified'][0] );
		} else {
			$cache_time = $batcache->cache['time'];
		}

		if ( $client_time >= $cache_time ) {
			$three04 = true;
		}
	}

	// Use the batcache save time for Last-Modified so we can issue "304 Not Modified"; don't clobber a cached Last-Modified header.
	if ( $batcache->cache_control && ! isset( $batcache->cache['headers']['Last-Modified'][0] ) ) {
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $batcache->cache['time'] ) . ' GMT', true );
		header( 'Cache-Control: max-age=' . ( $batcache->cache['max_age'] - time() + $batcache->cache['time'] ) . ', must-revalidate', true );
	}

	// Add some debug info just before </head>.
	if ( $batcache->debug ) {
		$batcache->add_debug_from_cache();
	}

	$batcache->do_headers( $batcache->headers, $batcache->cache['headers'] );

	if ( $three04 ) {
		header( 'HTTP/1.1 304 Not Modified', true, 304 );
		die;
	}

	if ( ! empty( $batcache->cache['status_header'] ) ) {
		header( $batcache->cache['status_header'], true );
	}

	batcache_stats( 'batcache', 'total_cached_views' );

	// Have you ever heard a death rattle before?
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cached HTML output.
	die( $batcache->cache['output'] );
}

// Didn't meet the minimum condition?
if ( ! $batcache->do || ! $batcache->genlock ) {
	return;
}

// WordPress 4.7 changes how filters are hooked. Since 4.6 add_filter can be used here; below is for WP < 4.6.
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'status_header', array( &$batcache, 'status_header' ), 10, 2 );
	add_filter( 'wp_redirect_status', array( &$batcache, 'redirect_status' ), 10, 2 );
} else {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required for WP < 4.6 filter registration.
	$wp_filter['status_header'][10]['batcache'] = array(
		'function'      => array( &$batcache, 'status_header' ),
		'accepted_args' => 2,
	);
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required for WP < 4.6 filter registration.
	$wp_filter['wp_redirect_status'][10]['batcache'] = array(
		'function'      => array( &$batcache, 'redirect_status' ),
		'accepted_args' => 2,
	);
}

ob_start( array( &$batcache, 'ob' ) );

// It is safer to omit the final PHP closing tag.
