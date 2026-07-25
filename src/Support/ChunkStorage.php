<?php

namespace Moaines\IllumiSearch\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ChunkStorage
{
    public const CHUNK_SIZE = 100;
    private const MAX_CHUNK_BYTES = 50 * 1024 * 1024;

    private string $basePath;
    private int $maxWeight;
    private int $totalColumns;

    private const COL_ID = 0;
    private const COL_MODEL_TYPE = 1;
    private const COL_MODEL_ID = 2;
    private const COL_TEXT_W_BASE = 3;

    public function __construct(string $basePath, int $maxWeight = 3)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->maxWeight = $maxWeight;
        $this->totalColumns = self::COL_TEXT_W_BASE + $maxWeight + 1;
    }

    public function colModelType(): int
    {
        return self::COL_MODEL_TYPE;
    }

    public function colModelId(): int
    {
        return self::COL_MODEL_ID;
    }

    public function colW(int $weight): int
    {
        return self::COL_TEXT_W_BASE + $weight - 1;
    }

    public function colLastSyncedAt(): int
    {
        return self::COL_TEXT_W_BASE + $this->maxWeight;
    }

    public function totalColumns(): int
    {
        return $this->totalColumns;
    }

    /**
     * Resolve a path's real path and verify it stays within basePath.
     *
     * Uses realpath() to resolve symlinks, `.` and `..` segments.
     * Throws when the path is outside basePath or doesn't exist.
     *
     * @return string Resolved real path
     */
    private function resolvePath(string $path): string
    {
        $resolved = realpath($path);

        if ($resolved === false || ! Str::startsWith($resolved, $this->basePath)) {
            throw new \RuntimeException("Security: path outside base path: $path");
        }

        return $resolved;
    }

    public function decodeFile(string $path): mixed
    {
        $this->resolvePath($path);

        try {
            $size = File::size($path);
        } catch (\Exception $e) {
            throw new \RuntimeException("Cannot read chunk file: $path", 0, $e);
        }

        if ($size > self::MAX_CHUNK_BYTES) {
            throw new \RuntimeException("Chunk file too large: $path");
        }

        $content = file_get_contents($path);

        if ($content === false || $content === '') {
            return null;
        }

        if (str_starts_with($content, '<?php')) {
            if (preg_match('/base64_decode\("([^"]+)"\)/', $content, $m)) {
                $decoded = unserialize(base64_decode($m[1]));

                return is_array($decoded) ? $decoded : null;
            }

            return null;
        }

        // HMAC-signed format: hmac:sha256:payload
        if (str_starts_with($content, 'hmac:')) {
            $parts = explode(':', $content, 3);
            if (count($parts) !== 3) {
                return null;
            }

            $expectedHmac = $parts[1];
            $payload = $parts[2];

            if (hash_hmac(IllumiSearchHelper::HMAC_ALGO, $payload, IllumiSearchHelper::HMAC_KEY) !== $expectedHmac) {
                throw new \RuntimeException("Integrity check failed (HMAC mismatch): $path");
            }

            $result = unserialize($payload);

            return is_array($result) ? $result : null;
        }

        // Plain serialize (legacy or unsigned)
        try {
            $result = unserialize($content);
        } catch (\Throwable) {
            return null;
        }

        return is_array($result) ? $result : null;
    }

    public function loadRows(string $path): array
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("Chunk file not found: $path");
        }

        $cacheKey = 'illumi_chunk_' . md5($path);

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        $data = $this->decodeFile($path);

        if (! is_array($data)) {
            throw new \RuntimeException("Corrupt chunk file: $path");
        }

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $data, 300);
        }

        return $data;
    }

    public function atomicWrite(string $path, array $data): void
    {
        $dir = dirname($path);
        File::ensureDirectoryExists($dir);
        $this->resolvePath($dir);

        $payload = serialize($data);
        $hmac = hash_hmac(IllumiSearchHelper::HMAC_ALGO, $payload, IllumiSearchHelper::HMAC_KEY);

        // Format: hmac:sha256hash:serialized_data
        // Supports legacy format (<?php) and future format changes
        $content = 'hmac:' . $hmac . ':' . $payload;

        $temp = $path . '.' . Str::random(16) . '.tmp';
        file_put_contents($temp, $content, LOCK_EX);

        rename($temp, $path);
    }

    public function rowToObj(array $r): object
    {
        $obj = (object) [
            'id' => $r[self::COL_ID] ?? 0,
            'model_type' => $r[self::COL_MODEL_TYPE] ?? '',
            'model_id' => $r[self::COL_MODEL_ID] ?? '',
        ];

        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $obj->{"text_w{$w}"} = $r[$this->colW($w)] ?? '';
        }

        $obj->last_synced_at = $r[$this->colLastSyncedAt()] ?? '';

        return $obj;
    }

    public function docText(object $doc): string
    {
        $values = [];
        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $col = "text_w{$w}";
            $values[$this->colW($w)] = $doc->$col ?? '';
        }

        return $this->docTextFromRow($values);
    }

    public function docTextFromRow(array $r): string
    {
        $parts = [];

        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $value = $r[$this->colW($w)] ?? '';
            if ($value !== '') {
                $parts[] = str_repeat($value . ' ', $w);
            }
        }

        return trim(implode('', $parts));
    }

    public function listChunks(string $dir): array
    {
        if (! File::isDirectory($dir)) {
            return [];
        }

        $this->resolvePath($dir);

        return collect(File::files($dir))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->map(fn ($f) => $f->getPathname())
            ->values()
            ->all();
    }

    public function ensureDir(string $dir): void
    {
        // Create the directory (including parent directories) first.
        // The path originates from internal methods (modelDir()), not user input,
        // but we still verify it's under basePath after creation.
        File::ensureDirectoryExists($dir);

        $this->resolvePath($dir);
    }

    public function nextChunkPath(string $dir): string
    {
        $this->ensureDir($dir);
        $chunks = $this->listChunks($dir);
        $next = empty($chunks) ? 0 : (int) pathinfo(end($chunks), PATHINFO_FILENAME) + 1;

        return $dir . '/' . sprintf('%04d', $next) . '.php';
    }

    public function lastChunkPath(string $dir): ?string
    {
        $chunks = $this->listChunks($dir);

        return ! empty($chunks) ? end($chunks) : null;
    }
}
