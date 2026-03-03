=== Batcache ===
Contributors: automattic, andy, orensol, markjaquith, vnsavage, batmoo, yoavf
Tags: cache, memcache, memcached, redis, object-cache, speed, performance, load, server
Requires at least: 3.2
Tested up to: 5.3.2
Stable tag: 1.6

Batcache uses the WordPress object cache API to store and serve rendered pages (Redis, Memcached, or any compatible backend).

== Description ==

Batcache uses the WordPress object cache API to store and serve rendered pages. It works with any object cache backend compatible with the WordPress Object Cache (e.g. Redis, Memcached). It can also optionally cache redirects. It's not as fast as Donncha's WP-Super-Cache but it can be used where file-based caching is not practical or not desired. For instance, any site that is run on more than one server should use Batcache because it allows all servers to use the same storage.

Development testing showed a 40x reduction in page generation times: pages generated in 200ms were served from the cache in 5ms. Traffic simulations with Siege demonstrate that WordPress can handle up to twenty times more traffic with Batcache installed.

Batcache is aimed at preventing a flood of traffic from breaking your site. It does this by serving old pages to new users. This reduces the demand on the web server CPU and the database. It also means some people may see a page that is a few minutes old. However this only applies to people who have not interacted with your web site before. Once they have logged in or left a comment they will always get fresh pages.

Possible future features:

* Comments, edits, and new posts will trigger cache regeneration
* Online installation assistance
* Configuration page
* Stats

== Installation ==

1. Get a WordPress object cache backend working (Redis, Memcached, or other). See below.

1. Upload `advanced-cache.php` to the `/wp-content/` directory

1. Add this line the top of `wp-config.php` to activate Batcache:

`define('WP_CACHE', true);`

1. Test by reloading a page in your browser several times and then viewing the source. Just above the `</head>` closing tag you should see some Batcache stats.

1. Tweak the options near the top of `advanced-cache.php`

1. *Optional* Upload `batcache.php` to the `/wp-content/plugins/` directory.

= Object cache backend =

Batcache requires a drop-in or plugin that implements the WordPress Object Cache API (e.g. Redis, Memcached, or another compatible backend). Install and configure one before using Batcache.

* **Memcached (reference setup):** Install [memcached](https://memcached.org/) on at least one server (default: `127.0.0.1:11211`), the [PECL memcached extension](http://pecl.php.net/package/memcache), and the [Memcached Object Cache](https://wordpress.org/plugins/memcached/) plugin. This was the original Batcache dependency and remains a common choice.
* **Redis:** Use a Redis object cache drop-in or plugin (e.g. Redis Object Cache) and configure your Redis server.
* Other backends compatible with the WordPress Object Cache API will work as well.

== Frequently Asked Questions ==

= Should I use this? =

Batcache can be used with any WordPress object cache backend (e.g. Redis, Memcached). WP-Super-Cache is preferred for most blogs. If you have more than one web server, try Batcache.

= Why was this written? =

Batcache was written to help WordPress.com cope with the massive and prolonged traffic spike on Gizmodo's live blog during Apple events. Live blogs were famous for failing under the load of traffic. Gizmodo's live blog stays up because of Batcache.

Actually all of WordPress.com stays up during Apple events because of Batcache. The traffic is twice the average during Apple events. But the web servers and databases barely feel the difference.

= What does it have to do with bats? =

Batcache was named "supercache" when it was written. (It's still called that on WordPress.com.) A few months later, while "supercache" was still private, Donncha released the WP-Super-Cache plugin. It wouldn't be fun to dispute the name or create confusion for users so a name change seemed best. The move from "Super" to "Bat" was inspired by comic book heroes. It has nothing to do with the fact that the author's city is home to the [world's largest urban bat colony](http://www.batcon.org/our-work/regions/usa-canada/protect-mega-populations/cab-intro).

== Development ==

= PHP quality tools =

Install dev dependencies with Composer:

`composer install`

* **Lint (PHPCS):** `composer lint` — WordPress Coding Standards (WPCS)
* **Auto-fix (PHPCBF):** `composer format` — fix many lint violations automatically
* **Static analysis (PHPStan):** `composer analyze` — level 5 with WordPress stubs

Configuration: `.phpcs.xml.dist`, `phpstan.neon`, `phpstan-baseline.neon`, `.editorconfig`.

== Changelog ==

= 1.6 =

* Development: add DDEV for local WordPress environment; add `.ddev/config.yaml`, `.ddev/docker-compose.plugin.yaml` (plugin mount at `wp/wp-content/plugins/batcache`)
* Docs: add TESTING.md with DDEV one-time setup, manual testing steps, Redis/Memcached section; document Redis Object Cache installation via WP-CLI and wp-config (`WP_REDIS_HOST`, `WP_CACHE`)
* Fix: in `batcache.php`, guard now checks `isset( $wp_object_cache )` and `is_object( $wp_object_cache )` before `method_exists()` to avoid PHP 8 TypeError when object cache is not available (e.g. CLI, headless)
* Development: no automated tests (manual testing only)
* Admin bar: add "Purge Batcache" button for users with `manage_options`; purges entire batcache group via `wp_cache_flush_group()` when the object cache supports it (e.g. Redis)
* Document compatibility with any WordPress object cache API backend (Redis, Memcached, etc.), not only Memcached
* Update code comments and docs to use "object cache" instead of "memcached" where generic
* Readme: add Object cache backend section; cite Memcached as reference setup and original dependency
* Add PHP quality tooling: Composer dev dependencies (WPCS, PHPCompatibility-WP, PHPStan with WordPress stubs), `.phpcs.xml.dist`, `phpstan.neon`, `.editorconfig`; `composer lint`, `composer format`, `composer analyze`
* Code changes for PHPCS compliance: WordPress Coding Standards (WordPress-Extra, WordPress-Docs), formatting, PHPDoc, and targeted rule exclusions for drop-in layout

= 1.5 =

* Add stats for cache hits
* PHP 4 constructors are deprecated in PHP7
* Removed "HTTP_RAW_POST_DATA" variable replaced with input stream check
* Use Plugins API rather than the global variable
* Set page gen time to request start if possible
* Don't use get_post() when cleaning post cache, use already passed $post object
* Only cache GET or HEAD
* Add Opt-in CORS GET request cache.
= 1.4 =
* Misc updates

= 1.3 =
* Code cleanup, multi-dc support improvements

= 1.2 =
* Add REQUEST_METHOD to the cache keys. Prevents GET requests receiving bodyless HEAD responses. This change invalidates the entire cache at upgrade time.

= 1.1 =
* Many bugfixes and updates from trunk
