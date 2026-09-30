# FWERKOR Auto Push

Asynchronous URL submission for WordPress posts.

## Features

- Baidu URL submission
- Bing Webmaster URL submission
- IndexNow submission
- Pushes new or materially updated published posts asynchronously through WP-Cron
- Bing and IndexNow use HTTPS with TLS verification
- Dynamic IndexNow key endpoint
- Recent submission log
- Existing ggpush credentials can be migrated on first activation without placing secrets in source
- No API keys, tokens, or site-specific hostnames in the repository

Secrets are stored only in the WordPress options table.

Baidu currently documents its URL-submission endpoint over plain HTTP. If Baidu submission is enabled, its token is therefore transmitted without TLS; this limitation is also shown in the WordPress settings page.

## Requirements

WordPress 6.0+ and PHP 8.0+.

## License

GPL-2.0-or-later.
