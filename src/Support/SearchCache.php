<?php

namespace Moaines\IllumiSearch\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Search result cache — shared by all engines.
 * Supports file-based (default) and Laravel Cache (Redis, DynamoDB, etc.) backends.
 *
 * Switch backend with config: config(['illumi-search.cache_driver' => 'redis'])
 * Cache TTL: config(['illumi-search.cache_ttl' => 300]) (5 minutes)
 *
 * Cache key = md5(query + modelClasses + limit + offset + mode + version).
 */
class SearchCache
{
    private string $cacheDir;
    private string $cacheVersion = 'v3';
    private bool $useLaravelCache;

    public function __construct(string $basePath, string $prefix = 'illumi_search_')
    {
        $this->cacheDir = rtrim($basePath, '/') . '/' . $prefix . 'cache/';
        $this->useLaravelCache = config('illumi-search.cache_driver', 'file') !== 'file'
            && $this->laravelCacheAvailable();
    }

    private function laravelCacheAvailable(): bool
    {
        return class_exists(Cache::class) && config('cache.default') !== null;
    }

    public function key(string $query, array $modelClasses, int $limit, int $offset, string $mode): string
    {
        // Encode each model individually in the key so clear($modelClass) can
        // invalidate both single- and multi-model entries containing it.
        $modelPrefix = implode(
            '.',
            array_map(fn ($m) => substr(md5((string) $m), 0, 8), $modelClasses),
        );
        $data = serialize([$query, $modelClasses, $limit, $offset, $mode, $this->cacheVersion]);

        return $this->useLaravelCache
            ? md5($data)
            : $modelPrefix . '_' . md5($data);
    }

    public function enrichedKey(string $baseKey): string
    {
        return $baseKey . '_enriched';
    }

    public function rawKey(string $baseKey): string
    {
        return $baseKey . '_raw';
    }

    public function get(string $key): ?array
    {
        if ($this->useLaravelCache) {
            $data = Cache::get("illumi_search:{$key}");

            return is_array($data) ? $data : null;
        }

        $path = $this->path($key);
        if (! file_exists($path)) {
            return null;
        }

        $ttl = (int) config('illumi-search.cache_ttl', 300);
        if ($ttl > 0 && (time() - filemtime($path)) > $ttl) {
            @unlink($path);

            return null;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    public function set(string $key, array $results): void
    {
        if ($this->useLaravelCache) {
            $ttl = (int) config('illumi-search.cache_ttl', 300);
            Cache::put("illumi_search:{$key}", $this->stripModels($results), $ttl);

            return;
        }

        File::ensureDirectoryExists($this->cacheDir);
        $path = $this->path($key);
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($temp, json_encode($this->stripModels($results), JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($temp, $path);
    }

    /**
     * Never serialize Eloquent models (with their relations) into the cache —
     * json_encode on a model is heavy and the model is not needed on cache hits.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function stripModels(array $results): array
    {
        foreach ($results as &$r) {
            if (is_array($r) && array_key_exists('eloquentModel', $r)) {
                $r['eloquentModel'] = null;
            }
        }

        return $results;
    }

    public function clear(?string $modelClass = null): void
    {
        if ($this->useLaravelCache) {
            // Tag-based flush if Redis/Memcached supports tags
            Cache::flush();

            return;
        }

        if (! is_dir($this->cacheDir)) {
            return;
        }

        // Key files look like: {md5A}.{md5B}.{...}_{queryhash}.json. Matching
        // *{modelHash}* invalidates single- and multi-model entries containing
        // the model (hash collision chance is negligible at 8 hex chars).
        $pattern = $modelClass !== null
            ? $this->cacheDir . '*' . substr(md5($modelClass), 0, 8) . '*'
            : $this->cacheDir . '*.json';

        foreach (glob($pattern) as $file) {
            @unlink($file);
        }
    }

    private function path(string $key): string
    {
        return $this->cacheDir . $key . '.json';
    }
}
