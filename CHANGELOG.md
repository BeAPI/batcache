# Changelog

## 1.7
- Add WP CLI command to purge entire batcache group

## 1.6

- Forked by Be API
- Development: add DDEV for local WordPress environment; add `.ddev/config.yaml`, `.ddev/docker-compose.plugin.yaml` (plugin mount at `wp/wp-content/plugins/batcache`)
- Docs: add TESTING.md with DDEV one-time setup, manual testing steps, Redis/Memcached section; document Redis Object Cache installation via WP-CLI and wp-config (`WP_REDIS_HOST`, `WP_CACHE`)
- Fix: in `batcache.php`, guard now checks `isset( $wp_object_cache )` and `is_object( $wp_object_cache )` before `method_exists()` to avoid PHP 8 TypeError when object cache is not available (e.g. CLI, headless)
- Development: no automated tests (manual testing only)
- Admin bar: add "Purge Batcache" button for users with `manage_options`; purges entire batcache group via `wp_cache_flush_group()` when the object cache supports it (e.g. Redis)
- Document compatibility with any WordPress object cache API backend (Redis, Memcached, etc.), not only Memcached
- Update code comments and docs to use "object cache" instead of "memcached" where generic
- Readme: add Object cache backend section; cite Memcached as reference setup and original dependency
- Add PHP quality tooling: Composer dev dependencies (WPCS, PHPCompatibility-WP, PHPStan with WordPress stubs), `.phpcs.xml.dist`, `phpstan.neon`, `.editorconfig`; `composer lint`, `composer format`, `composer analyze`
- Code changes for PHPCS compliance: WordPress Coding Standards (WordPress-Extra, WordPress-Docs), formatting, PHPDoc, and targeted rule exclusions for drop-in layout

## 1.5

- Add stats for cache hits
- PHP 4 constructors are deprecated in PHP7
- Removed "HTTP_RAW_POST_DATA" variable replaced with input stream check
- Use Plugins API rather than the global variable
- Set page gen time to request start if possible
- Don't use get_post() when cleaning post cache, use already passed $post object
- Only cache GET or HEAD
- Add Opt-in CORS GET request cache.

## 1.4

- Misc updates

## 1.3

- Code cleanup, multi-dc support improvements

## 1.2

- Add REQUEST_METHOD to the cache keys. Prevents GET requests receiving bodyless HEAD responses. This change invalidates the entire cache at upgrade time.

## 1.1

- Many bugfixes and updates from trunk
