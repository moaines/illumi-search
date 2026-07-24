<?php

namespace Moaines\IllumiSearch\Support;

use Illuminate\Support\Facades\File;

/**
 * Manages term-frequency stats for FileEngine BM25 IDF.
 *
 * Stats are JSON files stored alongside chunks. Each contains:
 *   {docCount, avgDocLength, terms: {word: docFreq, ...}}
 */
class StatsService
{
    private string $basePath;
    private string $prefix;

    public function __construct(string $basePath, string $prefix = 'illumi_search_')
    {
        $this->basePath = rtrim($basePath, '/');
        $this->prefix = $prefix;
    }

    public function path(string $modelClass): string
    {
        $name = str_replace('\\', '_', $modelClass);
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $name));

        return $this->basePath . '/' . $this->prefix . 'index/' . $name . '.stats';
    }

    public function load(string $modelClass, ?array $onlyTerms = null): ?array
    {
        $path = $this->path($modelClass);

        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        $stats = json_decode($content, true);
        if (! is_array($stats)) {
            return null;
        }

        if ($onlyTerms !== null && isset($stats['terms'])) {
            $filtered = [];
            foreach ($onlyTerms as $term) {
                if (isset($stats['terms'][$term])) {
                    $filtered[$term] = $stats['terms'][$term];
                }
            }
            $stats['terms'] = $filtered;
        }

        return $stats;
    }

    public function save(string $modelClass, array $stats): void
    {
        $path = $this->path($modelClass);
        $dir = dirname($path);
        File::ensureDirectoryExists($dir);

        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($temp, json_encode($stats, JSON_UNESCAPED_UNICODE), LOCK_EX);

        if (file_exists($path)) {
            unlink($path);
        }
        rename($temp, $path);
    }

    public function delete(string $modelClass): void
    {
        $path = $this->path($modelClass);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
