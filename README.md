# php-file-cache

A lightweight, dependency-free PHP file cache for storing array payloads such as API responses, database query results, and other JSON-friendly data.

## Features

- Stores values as JSON files on disk
- Uses a stable key derived from the endpoint and normalized request parameters
- Supports optional tag-based invalidation
- Supports TTL-based expiry or indefinite retention until manually cleared
- Can be globally disabled at runtime
- Creates the cache directory automatically when missing

## Installation

```bash
composer require grange-fencing/php-file-cache
```

## Requirements

- PHP 8.1+
- A writable directory for the cache root

## Quick start

```php
<?php

use GrangeFencing\PhpFileCache\Cache;
use GrangeFencing\PhpFileCache\CacheTtl;

$cache = new Cache();

$customers = $cache->remember(
    'https://api.example.com/customers',
    ['page' => 1, 'limit' => 25],
    ['customers', 'dashboard'],
    CacheTtl::Default,
    static fn () => [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ],
);
```

The first call executes the callback and stores the result. Subsequent calls with the same endpoint and parameters return the cached array until it expires.

## Configuration

The cache can be enabled or disabled in one of two ways:

- Pass `true` or `false` to the constructor
- Set the environment variable `GRANGEFENCING_FILECACHE_DISABLED`

When caching is enabled, the required environment variable is:

- `GRANGEFENCING_FILECACHE_ROOT` - path to a writable directory for storing cache files

Example:

```bash
export GRANGEFENCING_FILECACHE_DISABLED=false
export GRANGEFENCING_FILECACHE_ROOT=/var/tmp/php-file-cache
```

If you construct the cache with `new Cache(false)`, the env vars are ignored for the instance.

## API

### `new Cache(?bool $enabled = null)`

Creates a cache instance.

Resolution order:

1. explicit constructor argument when passed
2. `GRANGEFENCING_FILECACHE_DISABLED`
3. defaults to enabled

If caching is enabled and the root directory does not exist, it will be created automatically. If the root is not writable, a `RuntimeException` is thrown.

### `remember(string $endpoint, array $params, array|string $tags, CacheTtl|int $ttl, callable $callback): array`

Loads a cached array for the given endpoint and parameter set. If the item is missing or expired, the callback is called and the resulting array is stored.

Parameters:

- `$endpoint`: A stable endpoint identifier used as part of the key
- `$params`: Request parameters; the array is normalized so ordering does not affect the cache key
- `$tags`: One tag or a list of tags used for group invalidation
- `$ttl`: TTL in seconds or a `CacheTtl` enum value
- `$callback`: A zero-argument function returning the array to cache

Rules:

- The callback must return an array
- Negative TTL values are rejected
- If the cache is disabled, the callback still runs every time and the return value is not stored

### `invalidateTag(string ...$tag): int`

Deletes cache files associated with one or more tags using the tag index.

Returns the number of cache files removed.

### `invalidateAll(): int`

Deletes all cached files and resets the tag index.

Returns the number of cache files removed.

### `sweepExpired(): int`

Deletes expired cache files and prunes stale tag references.

Returns the number of expired files removed.

## TTL options

`CacheTtl` is an enum with three values:

- `CacheTtl::Default` = 3600 seconds
- `CacheTtl::UntilCleared` = no expiry; file remains until invalidated
- `CacheTtl::EndOfDay` = remaining seconds until midnight

Example:

```php
$cache->remember(
    'https://api.example.com/report',
    ['month' => '2026-08'],
    'reports',
    CacheTtl::UntilCleared,
    static fn () => ['ok' => true],
);
```

## Tag invalidation

Tags let you invalidate related entries without knowing every cache key.

```php
$cache->remember(
    'https://api.example.com/users',
    ['status' => 'active'],
    ['users', 'admin'],
    CacheTtl::Default,
    static fn () => [['id' => 1]],
);

$cache->invalidateTag('users');
```

This removes every cached entry tagged with `users` and updates the tag index accordingly.

## Storage format

Each cached item is written as a JSON file under the configured cache root, with a structure roughly like:

```json
{
  "createdAt": 1724690000,
  "expiresAt": 1724693600,
  "tags": ["customers", "dashboard"],
  "data": [
    {"id": 1, "name": "Alice"}
  ]
}
```

The tag index is stored in `tag-index.json` and maps each tag to the list of cache keys that belong to it.

## Important notes

- The library caches only arrays. If your callback returns a scalar, object, or other non-array value, it will throw a `RuntimeException`.
- The cache key is based on a normalized copy of `$params`, so associative key order does not matter.
- The library intentionally avoids external dependencies and uses only the PHP standard library and filesystem functions.
- When caching is disabled, `remember()` still executes the callback but never writes to disk.

## Example: disabling the cache in a test environment

```php
$cache = new Cache(false);

$results = $cache->remember(
    'https://api.example.com/health',
    [],
    'healthcheck',
    CacheTtl::Default,
    static fn () => ['status' => 'ok'],
);
```

This keeps the code path the same while bypassing file-based persistence.
