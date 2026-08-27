<?php

    namespace GrangeFencing\PhpFileCache;

    use RuntimeException;

    /**
     * Stores PHP arrays on disk and reuses them for repeated endpoint calls.
     *
     * Each cache item is keyed by a stable hash of the endpoint and a normalized
     * representation of the request parameters. Values are written to JSON files in
     * a configured root directory and may optionally be associated with tags for
     * bulk invalidation.
     *
     * The cache is enabled by default unless `GRANGEFENCING_FILECACHE_DISABLED`
     * is set to a truthy value, or `false` is passed to the constructor. When
     * enabled, `GRANGEFENCING_FILECACHE_ROOT` must point to a writable directory.
     * If the directory does not exist, it will be created automatically.
     *
     * @see CacheTtl
     */
    class Cache {

        private ?string $cacheRoot;
        private ?string $tagIndexPath;
        private bool    $enabled;

        /**
         * Creates a cache instance.
         *
         * Resolution order for the enabled state is:
         * 1. explicit `$enabled` argument when provided
         * 2. `GRANGEFENCING_FILECACHE_DISABLED` environment variable
         * 3. default of enabled
         *
         * When the cache is enabled, `GRANGEFENCING_FILECACHE_ROOT` must be set to
         * a directory path. The directory is created automatically if needed, and
         * if it is not writable a `RuntimeException` is thrown.
         *
         * @param bool|null $enabled Explicitly enable or disable caching. When null,
         *     the value is read from `GRANGEFENCING_FILECACHE_DISABLED`.
         *
         * @throws RuntimeException If the cache root is missing, cannot be created,
         *     or is not writable while caching is enabled.
         */
        public function __construct(?bool $enabled = null) {

            $this->enabled = $enabled ?? !filter_var(
                $_ENV["GRANGEFENCING_FILECACHE_DISABLED"] ?? "false",
                FILTER_VALIDATE_BOOLEAN,
            );

            if (!$this->enabled) {
                $this->cacheRoot = null;
                $this->tagIndexPath = null;

                return;
            }

            $this->cacheRoot = $_ENV["GRANGEFENCING_FILECACHE_ROOT"] ?? null;

            if ($this->cacheRoot == null) {
                throw new RuntimeException(
                    "Cache root not provided. Set GRANGEFENCING_FILECACHE_ROOT, or construct with enabled: false.",
                );
            }

            $this->cacheRoot = rtrim($this->cacheRoot, DIRECTORY_SEPARATOR);

            if (!is_dir($this->cacheRoot) && !mkdir($this->cacheRoot, 0755, true) && !is_dir($this->cacheRoot)) {
                throw new RuntimeException("Failed to create cache root: {$this->cacheRoot}");
            }

            if (!is_writable($this->cacheRoot)) {
                throw new RuntimeException("Cache root is not writable: {$this->cacheRoot}");
            }

            $this->tagIndexPath = $this->cacheRoot . DIRECTORY_SEPARATOR . "tag-index.json";
        }

        /**
         * Retrieves a cached response for an endpoint, or computes and stores it.
         *
         * The callback is invoked only when no valid cached item exists, or when
         * the cache is disabled. Cached values must be arrays; scalar and object
         * payloads are rejected to keep the storage format consistent.
         *
         * @param string $endpoint Logical endpoint identifier, such as an API URL
         *     or route name.
         * @param array $params Request parameters used to build the cache key.
         *     The array is normalized before hashing so key ordering is stable.
         * @param array|string $tags One or more tags used to group related entries
         *     for bulk invalidation.
         * @param int|CacheTtl $ttl Expiration policy. Use a positive integer for a
         *     TTL in seconds, or a `CacheTtl` enum value.
         * @param callable $callback A zero-argument callback returning the array
         *     payload to store when a cache miss occurs.
         *
         * @return array<string, mixed>|list<mixed> The cached response or the newly
         *     generated value from the callback.
         *
         * @throws RuntimeException If the cache is disabled and the callback returns
         *     a non-array value, if the callback returns a non-array value while
         *     writing a fresh entry, or if `$ttl` is negative.
         */
        public function remember(
            string       $endpoint,
            array        $params,
            array|string $tags,
            int|CacheTtl $ttl,
            callable     $callback,
        ): array {

            if (is_int($ttl) && $ttl < 0) {
                throw new RuntimeException("TTL must be greater than 0 seconds.");
            }

            if (!$this->enabled) {
                $data = $callback();
                if (!is_array($data)) {
                    throw new RuntimeException("Cache callback must return an array.");
                }

                return $data;
            }

            if ($ttl instanceof CacheTtl) {

                if ($ttl === CacheTtl::EndOfDay) {
                    $ttl = strtotime("tomorrow") - time();
                } else {
                    $ttl = $ttl->value;
                }

            }

            if (is_string($tags)) {
                $tags = [$tags];
            }

            $key = $this->buildKey($endpoint, $params);
            $cached = $this->getByKey($key);

            if ($cached !== null) {
                return $cached;
            }

            $data = $callback();

            if (!is_array($data)) {
                throw new RuntimeException("Cache callback must return an array.");
            }

            $this->setByKey($key, $data, $ttl, $tags);

            return $data;
        }

        /**
         * Invalidates cache entries associated with one or more tags.
         *
         * The tag index is used to locate matching cache keys efficiently without
         * scanning every cached file.
         *
         * @param string $tag The first tag to invalidate.
         * @param string ...$tags Additional tags to invalidate.
         *
         * @return int The number of cache files removed.
         */
        public function invalidateTag(string $tag, string ...$tags): int {

            if (!$this->enabled) {
                return 0;
            }

            $deleted = 0;

            $this->withTagIndexLock(function (array $index) use (&$deleted, $tag, $tags): array {
                foreach ([$tag, ...$tags] as $rawTag) {
                    $normalizedTag = $this->normalizeTag($rawTag);
                    $keys = $index[$normalizedTag] ?? [];

                    foreach ($keys as $key) {
                        $filePath = $this->filePathForKey($key);
                        if (file_exists($filePath) && unlink($filePath)) {
                            $deleted++;
                        }
                    }

                    unset($index[$normalizedTag]);
                }

                return $index;
            });

            return $deleted;
        }

        /**
         * Deletes every cached file and resets the tag index.
         *
         * @return int The number of cache files removed.
         */
        public function invalidateAll(): int {

            if (!$this->enabled) {
                return 0;
            }

            $deleted = 0;

            foreach (glob($this->cacheRoot . DIRECTORY_SEPARATOR . "*.json") ?: [] as $file) {
                if (basename($file) === "tag-index.json") {
                    continue;
                }
                if (unlink($file)) {
                    $deleted++;
                }
            }

            $this->withTagIndexLock(static fn (): array => []);

            return $deleted;
        }

        /**
         * Removes cache files whose expiry time has passed.
         *
         * This method intentionally only checks files that still exist; it also
         * prunes stale references from the tag index.
         *
         * @return int The number of expired cache files removed.
         */
        public function sweepExpired(): int {

            if (!$this->enabled) {
                return 0;
            }

            $deleted = 0;

            foreach (glob($this->cacheRoot . DIRECTORY_SEPARATOR . "*.json") ?: [] as $file) {
                if (basename($file) === "tag-index.json") {
                    continue;
                }
                $content = json_decode((string)file_get_contents($file), true);
                if (
                    is_array($content)
                    && array_key_exists("expiresAt", $content)
                    && $content["expiresAt"] !== null
                    && (int)$content["expiresAt"] < time()
                ) {
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }

            $this->pruneTagIndex();

            return $deleted;
        }

        private function getByKey(string $key): ?array {

            $filePath = $this->filePathForKey($key);

            if (!file_exists($filePath)) {
                return null;
            }

            $content = json_decode((string)file_get_contents($filePath), true);

            if (!is_array($content) || !array_key_exists("expiresAt", $content) || !isset($content["data"])) {
                unlink($filePath);
                $this->pruneTagIndex();

                return null;
            }

            if ($content["expiresAt"] !== null && (int)$content["expiresAt"] < time()) {
                unlink($filePath);
                $this->pruneTagIndex();

                return null;
            }

            if (!is_array($content["data"])) {
                unlink($filePath);
                $this->pruneTagIndex();

                return null;
            }

            return $content["data"];
        }

        private function setByKey(string $key, array $data, int $ttl, array $tags): void {

            $filePath = $this->filePathForKey($key);

            $payload = [
                "createdAt" => time(),
                "expiresAt" => $ttl === CacheTtl::UntilCleared->value ? null : time() + $ttl,
                "tags"      => array_values(array_unique(array_map([$this, "normalizeTag"], $tags))),
                "data"      => $data,
            ];

            $bytes = file_put_contents(
                $filePath,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX,
            );

            if ($bytes === false) {
                throw new RuntimeException("Failed to write cache file: $filePath");
            }

            $this->addKeyToTags($key, $payload["tags"]);
        }

        private function buildKey(string $endpoint, array $params): string {

            $normalized = $this->normalizeArray($params);
            $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                throw new RuntimeException("Failed to encode cache parameters.");
            }

            return "ep:" . md5($endpoint) . ":" . md5($encoded);
        }

        private function normalizeArray(array $value): array {

            if (array_is_list($value)) {
                foreach ($value as $i => $item) {
                    if (is_array($item)) {
                        $value[$i] = $this->normalizeArray($item);
                    }
                }

                return $value;
            }

            ksort($value);
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $value[$k] = $this->normalizeArray($v);
                }
            }

            return $value;
        }

        private function filePathForKey(string $key): string {

            return $this->cacheRoot . DIRECTORY_SEPARATOR . $key . ".json";
        }

        private function normalizeTag(string $tag): string {

            $normalized = trim($tag);
            if ($normalized === "") {
                throw new RuntimeException("Cache tag cannot be empty.");
            }

            return $normalized;
        }

        private function saveTagIndex(array $index): void {

            $handle = fopen($this->tagIndexPath, "c+");
            if ($handle === false) {
                throw new RuntimeException("Failed to open tag index file: $this->tagIndexPath");
            }

            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                throw new RuntimeException("Failed to lock tag index file: $this->tagIndexPath");
            }

            try {
                $encoded = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if ($encoded === false) {
                    throw new RuntimeException("Failed to encode tag index data: $this->tagIndexPath");
                }

                if (ftruncate($handle, 0) === false || rewind($handle) === false) {
                    throw new RuntimeException("Failed to reset tag index file: $this->tagIndexPath");
                }

                $bytes = fwrite($handle, $encoded);
                if ($bytes === false) {
                    throw new RuntimeException("Failed to write tag index file: $this->tagIndexPath");
                }

                fflush($handle);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        private function withTagIndexLock(callable $callback): void {

            $handle = fopen($this->tagIndexPath, "c+");
            if ($handle === false) {
                throw new RuntimeException("Failed to open tag index file: $this->tagIndexPath");
            }

            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                throw new RuntimeException("Failed to lock tag index file: $this->tagIndexPath");
            }

            try {
                $contents = stream_get_contents($handle);
                $index = [];

                if ($contents !== false && trim($contents) !== "") {
                    $decoded = json_decode($contents, true);
                    if (is_array($decoded)) {
                        $index = $decoded;
                    }
                }

                $updated = $callback($index);
                $encoded = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                if ($encoded === false) {
                    throw new RuntimeException("Failed to encode tag index data: $this->tagIndexPath");
                }

                if (ftruncate($handle, 0) === false || rewind($handle) === false) {
                    throw new RuntimeException("Failed to reset tag index file: $this->tagIndexPath");
                }

                $bytes = fwrite($handle, $encoded);
                if ($bytes === false) {
                    throw new RuntimeException("Failed to write tag index file: $this->tagIndexPath");
                }

                fflush($handle);

                return;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        private function addKeyToTags(string $key, array $tags): void {

            if (empty($tags)) {
                return;
            }

            $this->withTagIndexLock(function (array $index) use ($key, $tags): array {
                foreach ($tags as $tag) {
                    $index[$tag] ??= [];
                    if (!in_array($key, $index[$tag], true)) {
                        $index[$tag][] = $key;
                    }
                }

                return $index;
            });
        }

        private function pruneTagIndex(): void {

            $this->withTagIndexLock(function (array $index): array {
                foreach ($index as $tag => $keys) {
                    $validKeys = [];

                    foreach ($keys as $key) {
                        if (file_exists($this->filePathForKey($key))) {
                            $validKeys[] = $key;
                        }
                    }

                    if (empty($validKeys)) {
                        unset($index[$tag]);
                        continue;
                    }

                    $index[$tag] = $validKeys;
                }

                return $index;
            });
        }

    }