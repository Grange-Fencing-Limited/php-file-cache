<?php

    namespace GrangeFencing\PhpFileCache;

    use RuntimeException;

    /**
     * File-based cache for API query results.
     *
     * Entries are keyed by endpoint and parameters, stored as JSON files, and
     * optionally grouped by tags for invalidation.
     */
    class Cache {

        private ?string $cacheRoot;
        private ?string $tagIndexPath;
        private bool    $enabled;

        /**
         * Creates a cache instance.
         *
         * Uses `GRANGEFENCING_FILECACHE_DISABLED` and `GRANGEFENCING_FILECACHE_ROOT`
         * when `$enabled` is not provided.
         *
         * @param bool|null $enabled Explicitly enable or disable the cache, or defer to env vars.
         *
         * @throws RuntimeException If the cache root cannot be created or is not writable.
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
         * Returns a cached array for the given endpoint and parameters.
         *
         * The callback is only executed on a cache miss or when caching is disabled.
         *
         * @param string $endpoint The API endpoint being queried.
         * @param array $params The parameters for the query.
         * @param array|string $tags Tags associated with this cache entry for invalidation.
         * @param CacheTtl|int $ttl Time-to-live for the cache entry. Default is CacheTtl::Default. Must be greater than 0.
         * @param callable $callback A callback that returns the data to cache if not already cached.
         *
         * @return array The cached or newly fetched data.
         */
        public function remember(
            string       $endpoint,
            array        $params,
            array|string $tags,
            int|CacheTtl $ttl,
            callable     $callback,
        ): array {

            if ($ttl instanceof CacheTtl) {
                $ttl = $ttl->value;
            }

            if (!$this->enabled) {
                $data = $callback();
                if (!is_array($data)) {
                    throw new RuntimeException("Cache callback must return an array.");
                }

                return $data;
            }

            if ($ttl < 0) {
                throw new RuntimeException("TTL must be greater than 0.");
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
         * Invalidates all cache entries associated with specific tags.
         *
         * @param string ...$tag The tag or tags to invalidate.
         *
         * @return int The number of cache entries deleted.
         */
        public function invalidateTag(string ...$tag): int {

            if (!$this->enabled) {
                return 0;
            }

            $deleted = 0;
            $index = $this->loadTagIndex();

            foreach ($tag as $rawTag) {
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

            $this->saveTagIndex($index);

            return $deleted;
        }

        /**
         * Invalidates all cache entries.
         *
         * @return int The number of cache entries deleted.
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

            $this->saveTagIndex([]);

            return $deleted;
        }

        /**
         * Removes expired cache entries.
         *
         * @return int The number of cache entries deleted.
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

        private function loadTagIndex(): array {

            if (!file_exists($this->tagIndexPath)) {
                return [];
            }

            $data = json_decode((string)file_get_contents($this->tagIndexPath), true);

            return is_array($data) ? $data : [];
        }

        private function saveTagIndex(array $index): void {

            $bytes = file_put_contents(
                $this->tagIndexPath,
                json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX,
            );

            if ($bytes === false) {
                throw new RuntimeException("Failed to write tag index file: $this->tagIndexPath");
            }
        }

        private function addKeyToTags(string $key, array $tags): void {

            if (empty($tags)) {
                return;
            }

            $index = $this->loadTagIndex();

            foreach ($tags as $tag) {
                $index[$tag] ??= [];
                if (!in_array($key, $index[$tag], true)) {
                    $index[$tag][] = $key;
                }
            }

            $this->saveTagIndex($index);
        }

        private function pruneTagIndex(): void {

            $index = $this->loadTagIndex();
            $changed = false;

            foreach ($index as $tag => $keys) {
                $validKeys = [];

                foreach ($keys as $key) {
                    if (file_exists($this->filePathForKey($key))) {
                        $validKeys[] = $key;
                    } else {
                        $changed = true;
                    }
                }

                if (empty($validKeys)) {
                    unset($index[$tag]);
                    $changed = true;
                } else {
                    $index[$tag] = $validKeys;
                }
            }

            if ($changed) {
                $this->saveTagIndex($index);
            }
        }

    }