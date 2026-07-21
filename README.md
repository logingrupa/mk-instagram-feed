# MK Instagram Feed

Server-side cached Instagram feed for WordPress using the Instagram Graph API (Instagram Login flow). Renders a responsive grid with an Instagram-style lightbox (video playback, likes/comments/views, captions, swipe + keyboard navigation). All HTML/CSS/JS is inlined and self-hosted — no third-party scripts, no front-end API calls.

## Features

- `[mk_instagram count="9" columns="3"]` shortcode
- Gutenberg block **Instagram Feed** (`mk/instagram`) with live server-side preview and count/columns controls
- 2-hour transient cache + last-good fallback (API outages never blank the page)
- Video view counts via the Insights API
- Daily WP-Cron job auto-refreshes the long-lived access token
- Profile header (username + avatar) in the lightbox, cached 24h

## Installation

### Bedrock / Composer (recommended)

Add the repository and require the package:

```json
"repositories": [
  { "type": "vcs", "url": "git@github.com:logingrupa/mk-instagram-feed.git" }
]
```

```sh
composer require logingrupa/mk-instagram-feed
```

The package type is `wordpress-muplugin`, so with Bedrock's default installer paths it lands in `web/app/mu-plugins/mk-instagram-feed/` and is loaded automatically by the Bedrock autoloader — no activation needed.

### Plain WordPress

Copy this folder into `wp-content/plugins/mk-instagram-feed/` and activate it in wp-admin.

## Configuration

The plugin needs a long-lived Instagram Graph API access token (Instagram Login / "Instagram API with Instagram Login" app type). It is read from, in order:

1. The `mk_ig_token` WordPress option:
   ```sh
   wp option update mk_ig_token 'IGAA...'
   ```
2. The `FB_META_IG_KEY` environment variable (e.g. in Bedrock's `.env`):
   ```dotenv
   FB_META_IG_KEY='IGAA...'
   ```

Once a token is set, the daily cron event `mk_ig_refresh_token_event` keeps it refreshed (stores the refreshed token in the `mk_ig_token` option).

## Usage

Shortcode:

```
[mk_instagram count="9" columns="3"]
```

- `count` — number of posts, 1–50 (default 9)
- `columns` — grid columns, 1–6 (default 3)

Or insert the **Instagram Feed** block in the editor.

## Cache

- Feed: 2h transient per requested count (`mk_ig_feed_v3_9`, `mk_ig_feed_v3_30`, …), last-good copy in the `mk_ig_feed_lastgood` option
- Profile: 24h transient (`mk_ig_profile_v1`)
- Outage backoff: 5m transient (`mk_ig_error_backoff`) — while the API is failing the last-good copy is served without retrying on every page view

Force a refresh:

```sh
wp eval 'mk_ig_flush_cache();'
```

The cache is also flushed automatically whenever the daily job stores a refreshed token.

## A note on WP-Cron

Token refresh runs on the daily `mk_ig_refresh_token_event` WP-Cron hook. On sites
where WP-Cron is disabled or not firing (no traffic, `DISABLE_WP_CRON`, a broken
cron option) the token will silently expire after 60 days and the feed will freeze
on its last-good copy. Drive the hook from system cron instead:

```cron
17 4 * * * www-data /usr/bin/php /usr/bin/wp --path=/srv/site cron event run mk_ig_refresh_token_event
```
