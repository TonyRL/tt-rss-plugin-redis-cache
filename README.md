# Redis Cache Plugin for Tiny Tiny RSS

This plugin uses Valkey (Redis) to cache frequently accessed data, speeding up web page loading times.

## Requirements

Your Docker image or server must have the **php-redis extension** installed.

## Installation

This is a system plugin, so it must be enabled globally using [`TTRSS_PLUGINS`](https://tt-rss.org/docs/Plugins.html#about-plugins). For example:

```bash
TTRSS_PLUGINS="auth_internal,redis_cache"
```

## Configuration

Set these environment variables in your `docker-compose.yml`.

### Connection Settings

Connect using either a single URL or individual parameters. When `TTRSS_REDIS_URL` is set, it takes priority over the individual settings.

| Variable               | Description                      | Default     |
| ---------------------- | -------------------------------- | ----------- |
| `TTRSS_REDIS_URL`      | Full connection URL              | *(empty)*   |
| `TTRSS_REDIS_HOST`     | Server hostname or IP address    | `localhost` |
| `TTRSS_REDIS_PORT`     | Server port                      | `6379`      |
| `TTRSS_REDIS_DB`       | Database number (0–15)           | `0`         |
| `TTRSS_REDIS_PASSWORD` | Authentication password          | *(empty)*   |

URL format: `redis://[user:password@]host[:port][/db]`

Examples:

```bash
TTRSS_REDIS_URL=redis://valkey:6379/0
TTRSS_REDIS_URL=redis://:secretpass@valkey:6379/2
TTRSS_REDIS_URL=redis://default:secretpass@redis.example.com:6380/0
```

### Cache TTL Settings (seconds)

| Variable                        | Description                      | Default             |
| ------------------------------- | -------------------------------- | ------------------- |
| `TTRSS_REDIS_COUNTERS_TTL`      | Article counters                 | `30`                |
| `TTRSS_REDIS_VIEW_TTL`          | Headlines view                   | `60` (1 minute)     |
| `TTRSS_REDIS_RUNTIME_INFO_TTL`  | Runtime info                     | `10`                |
| `TTRSS_REDIS_LABELS_TTL`        | User labels                      | `60` (1 minute)     |
| `TTRSS_REDIS_INIT_PARAMS_TTL`   | Init params                      | `300` (5 minutes)   |
| `TTRSS_REDIS_FEED_TREE_TTL`     | Feed tree / category hierarchy   | `300` (5 minutes)   |
| `TTRSS_REDIS_FEED_ICONS_TTL`    | Feed icon existence              | `3600` (1 hour)     |
| `TTRSS_REDIS_TRANSLATIONS_TTL`  | Translation strings              | `86400` (24 hours)  |

