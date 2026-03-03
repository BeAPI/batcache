# Testing Batcache with DDEV

This project uses [DDEV](https://ddev.com/) for local development and **manual testing** in an isolated WordPress environment.

## Quick start

```bash
composer install
mkdir -p wp && ddev start
ddev exec wp core download --path=/var/www/html/wp
ddev exec wp config create --path=/var/www/html/wp --dbname=db --dbuser=db --dbpass=db
ddev exec wp core install --path=/var/www/html/wp --url=https://batcache.ddev.site --title=Batcache --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
ddev exec wp plugin activate batcache --path=/var/www/html/wp
```

## Prerequisites

- **DDEV** – [ddev.com](https://ddev.com/) (includes Docker)
- **Composer** – [getcomposer.org](https://getcomposer.org/)

## One-time setup

1. Install PHP dependencies (optional, for lint/static analysis):

```bash
composer install
```

2. Create the WordPress docroot and start DDEV:

```bash
mkdir -p wp
ddev start
```

3. Install WordPress in `wp/` (first time only):

```bash
ddev exec wp core download --path=/var/www/html/wp
ddev exec wp config create --path=/var/www/html/wp --dbname=db --dbuser=db --dbpass=db
ddev exec wp core install --path=/var/www/html/wp --url=https://batcache.ddev.site --title=Batcache --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
ddev exec wp plugin activate batcache --path=/var/www/html/wp
# Optional: install Redis Object Cache for Batcache backend (requires Redis, see below)
ddev exec wp plugin install redis-cache --path=/var/www/html/wp --activate
```

The plugin (this repo) is mounted at `wp/wp-content/plugins/batcache` via `.ddev/docker-compose.plugin.yaml`.

## Manual testing

There are no automated unit tests. Verify Batcache manually:

1. **Object cache required.** Ensure an object cache backend is running (e.g. Redis with Redis Object Cache plugin) and that Batcache’s `advanced-cache.php` drop-in is in place and `WP_CACHE` is set. See [Redis or Memcached](#redis-or-memcached) below.

2. **Cache serving.** Load a public page (e.g. the homepage) several times. View the page source: just above the `</head>` closing tag you should see Batcache stats (e.g. generation time, cache hit). See the main [readme](readme.txt) for details.

3. **Cache invalidation.** With the optional `batcache.php` plugin active, publish or update a post and confirm the relevant URLs are no longer served from cache (or are regenerated) as expected.

4. **Redis/Memcached.** If using Redis Object Cache, run `ddev exec wp redis status --path=/var/www/html/wp` to confirm the connection. Exercise the site in the browser and in WP-CLI to ensure no errors.

## Managing the environment

```bash
ddev start   # Start
ddev stop    # Stop
```

## Accessing the site

When DDEV is running, the site is available at **https://batcache.ddev.site** (or the URL shown by `ddev describe`).

Default admin credentials (after `wp core install` above): `admin` / `admin`.

## Redis or Memcached

Batcache needs an object cache backend (e.g. [Redis Object Cache](https://wordpress.org/plugins/redis-cache/)). DDEV supports Redis via an add-on.

### Redis (recommended)

1. Add the Redis service and install the plugin:

```bash
# Add Redis container
ddev get ddev/ddev-redis
ddev restart

# Install and activate Redis Object Cache plugin (WP-CLI)
ddev exec wp plugin install redis-cache --path=/var/www/html/wp --activate

# Enable the object cache drop-in
ddev exec wp redis enable --path=/var/www/html/wp
```

2. **Configure wp-config for Redis.** WordPress must know the Redis host and have the cache constant set. In DDEV, add these to `wp/wp-config-ddev.php` (or to `wp-config.php` after the block that includes it):

```php
define( 'WP_REDIS_HOST', 'ddev-batcache-redis' );
define( 'WP_CACHE', true );
```

The host name is `ddev-<projectname>-redis` (here `batcache`). If you edit `wp-config-ddev.php`, note that DDEV may overwrite it; remove the `#ddev-generated` comment at the top if you want to keep your changes, or put these defines in `wp-config.php` instead.

3. Check status: `ddev exec wp redis status --path=/var/www/html/wp`

To disable the object cache (e.g. for imports or WP-CLI batch jobs): `ddev exec wp redis disable --path=/var/www/html/wp`.

### Memcached

If a Memcached add-on is available, install it and use a Memcached object cache plugin; configure it to use the service (e.g. `memcached:11211`).

## Configuration files

- **`.ddev/config.yaml`** – DDEV project config (WordPress, docroot `wp`, PHP 8.3)
- **`.ddev/docker-compose.plugin.yaml`** – Mounts the repo as `wp/wp-content/plugins/batcache`

## Troubleshooting

**DDEV not installed:** Install [DDEV](https://ddev.com/docs/install/) (Docker is required).

**`ddev start` fails (e.g. docroot missing):** Ensure `wp/` exists: `mkdir -p wp`. If WordPress is not in `wp/` yet, run the `wp core download` and `wp config create` / `wp core install` steps above.

**Batcache not serving cached pages:** Ensure `WP_CACHE` is true, `advanced-cache.php` is in `wp-content/`, and an object cache (e.g. Redis with Redis Object Cache) is installed and enabled.
