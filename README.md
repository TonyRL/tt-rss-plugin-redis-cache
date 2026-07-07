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

| Variable                       | Default | Description                                                                                                                                                                                                        |
| ------------------------------ | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `TTRSS_REDIS_COUNTERS_TTL`     | `120`   | Unread/starred/label counts shown in the feed tree sidebar. Polled by the client every \~60s. A low TTL keeps badge counts responsive; a higher value reduces DB load from the aggregation queries.                |
| `TTRSS_REDIS_VIEW_TTL`         | `60`    | The full headline list response (first page, non-search). Cached per feed/category/view-mode combination. Invalidated automatically on catchup, mark, and publish actions.                                         |
| `TTRSS_REDIS_RUNTIME_INFO_TTL` | `30`    | Lightweight metadata returned with every response: feed count, daemon status, error log count, and label definitions. Kept short so daemon health checks stay accurate; 30s matches the daemon-stamp recheck interval.                             |
| `TTRSS_REDIS_LABELS_TTL`       | `300`   | The list of label definitions (id, caption, colors) for a user, used in runtime info and article rendering. Invalidated on label assign/remove.                                                                    |
| `TTRSS_REDIS_INIT_PARAMS_TTL`  | `300`   | Startup parameters sent during sanity check: theme, user prefs, enabled plugins, hotkeys. Only changes when preferences are saved or plugins are toggled.                                                          |
| `TTRSS_REDIS_FEED_TREE_TTL`    | `300`   | The full feed/category tree structure shown in the sidebar and preferences. Invalidated on feed subscribe/remove and category add/remove/rename/reorder.                                                                                           |
| `TTRSS_REDIS_FEED_ICONS_TTL`   | `86400` | A map of feed ID to favicon file mtime, used when rebuilding counters. Avoids one file_exists()/filemtime() call per feed. Invalidated on feed structure changes; a stale entry only delays favicon refresh in the client.                         |

