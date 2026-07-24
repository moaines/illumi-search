<?php

namespace Moaines\IllumiSearch\Support;

use Illuminate\Support\Facades\File;

/**
 * File-based search result cache shared by all engines.
 *
 * Cache key = md5(query + modelClasses + limit + offset + mode + version).
 * Files stored in {base}/cache/{key}.json.
 * Cleared on write operations (selective per model class when possible).
 */
class SearchCache
{
    private string $cacheDir;
    private string $cacheVersion = 'v2';

    public function __construct(string $basePath, string $prefix = 'illumi_search_')
    {
        $this->cacheDir = rtrim($basePath, '/') . '/' . $prefix . 'cache/';
    }

    /**
     * Build a cache key from the search parameters.
     */
    public function key(string $query, array $modelClasses, int $limit, int $offset, string $mode): string
    {
        $modelPrefix = md5(implode(',', $modelClasses));
        $data = serialize([$query, $modelClasses, $limit, $offset, $mode, $this->cacheVersion]);

        return substr($modelPrefix, 0, 8) . '_' . md5($data);
    }

    /**
     * Get cached results for a key.
     */
    public function get(string $key): ?array
    {
        $path = $this->path($key);

        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Store results for a key.
     */
    public function set(string $key, array $results): void
    {
        File::ensureDirectoryExists($this->cacheDir);

        $path = $this->path($key);
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($temp, json_encode($results, JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($temp, $path);
    }

    /**
     * Clear all cached results, or only those for a specific model class.
     */
    public function clear(?string $modelClass = null): void
    {
        if (! is_dir($this->cacheDir)) {
            return;
        }

        if ($modelClass !== null) {
            $prefix = substr(md5($modelClass), 0, 8);
            $pattern = $this->cacheDir . $prefix . '_*.json';
        } else {
            $pattern = $this->cacheDir . '*.json';
        }

        foreach (glob($pattern) as $file) {
            @unlink($file);
        }
    }

    private function path(string $key): string
    {
        return $this->cacheDir . $key . '.json';
    }
}
