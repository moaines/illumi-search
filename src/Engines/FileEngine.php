<?php

namespace Moaines\IllumiSearch\Engines;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Str;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Result;
use Moaines\IllumiSearch\Support\ChunkStorage;
use Moaines\IllumiSearch\Support\ConcurrentProcessor;
use Moaines\IllumiSearch\Support\IllumiSearchHelper;
use Moaines\IllumiSearch\Support\MatchService;
use Moaines\IllumiSearch\Support\OperatorRegistry;
use Moaines\IllumiSearch\Support\ScoreService;
use Moaines\IllumiSearch\Support\SearchCache;
use Moaines\IllumiSearch\Support\SnippetService;
use Moaines\IllumiSearch\Support\StatsService;
use Moaines\IllumiSearch\Support\TrigramIndex;
use Moaines\IllumiSearch\Support\VocabService;
use Moaines\IllumiSearch\TenantManager;
use Moaines\IllumiSearch\Text\HasDebugCollector;
use Moaines\IllumiSearch\Support\IllumiSearchConfig;
use Moaines\IllumiSearch\Text\HasTextHelpers;
use Moaines\IllumiSearch\Text\NoopVacuum;
use Moaines\IllumiSearch\Text\NullPragma;
use Moaines\IllumiSearch\Text\StubQueryVocab;
use Symfony\Component\String\UnicodeString;

class FileEngine implements Engine
{
    use HasDebugCollector;
    use HasTextHelpers;
    use NoopVacuum;
    use NullPragma;
    use StubQueryVocab;

    private const CACHE_LOAD_FAILED = '__FAILED__';
    private const SEARCH_OVERFETCH_MARGIN = 50;
    private const VOCAB_WORDS_FILE = 'words.php';
    private const VOCAB_TRIGRAMS_FILE = 'trigrams.php';
    private const META_FILE = 'meta.php';
    private const CONFIG_FILE = 'config.php';
    private const VERSION = '1.16.1';

    private string $basePath;
    private ?SnippetService $snippets = null;
    private bool $isRebuilding = false;
    private int $maxWeight;
    private ?TextProcessor $textProcessorCache = null;
    private ChunkStorage $chunks;
    private StatsService $stats;
    private ScoreService $score;
    private MatchService $match;
    private SearchCache $searchCache;
    private TrigramIndex $trigramIndex;
    private VocabService $vocab;
    private ?string $prefix = null;

    /** @var array<string, array|string|null> */
    private array $statsCache = [];

    public function __construct(string $basePath, ?SnippetService $snippets = null, ?IllumiSearchConfig $config = null)
    {
        $illumiConfig = $config ?? app(IllumiSearchConfig::class);
        $this->basePath = rtrim($basePath, '/');
        $this->snippets = $snippets;
        $this->maxWeight = $illumiConfig->maxWeight();
        $this->chunks = new ChunkStorage($basePath, $this->maxWeight);
        $this->stats = new StatsService($basePath);
        $this->score = new ScoreService;
        $this->match = new MatchService;
        $this->searchCache = new SearchCache($basePath);
        $this->trigramIndex = new TrigramIndex($basePath);
        $this->vocab = new VocabService($basePath);
    }

    public function setRebuilding(bool $rebuilding): void
    {
        $this->isRebuilding = $rebuilding;
    }

    private function textProcessor(): TextProcessor
    {
        if ($this->textProcessorCache === null) {
            $this->textProcessorCache = app(TextProcessor::class);
        }

        return $this->textProcessorCache;
    }

    private function path(string $sub): string
    {
        $prefix = app(IllumiSearchConfig::class)->tablePrefix();
        $tenantId = app(TenantManager::class)->tenantId();
        // Security: path validation is delegated to ChunkStorage (realpath ⊆ basePath)
        $prefixed = $prefix . ltrim($sub, '/');

        return $tenantId !== null ? $this->basePath . '/tenants/' . $tenantId . '/' . $prefixed : $this->basePath . '/' . $prefixed;
    }

    private function modelDir(string $modelClass): string
    {
        $name = str_replace('\\', '_', $modelClass);
        $name = Str::of($name)->replaceMatches('/[^a-zA-Z0-9_]/', '')->lower();

        return $this->path('index/' . $name);
    }

    private function buildWeightTexts(string $modelClass, array $document): array
    {
        $result = [];
        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $result["text_w{$w}"] = '';
        }

        $searchable = method_exists($modelClass, 'getSearchableColumns')
            ? (new $modelClass)->getSearchableColumns() : [];

        $processor = $this->textProcessor();

        foreach ($searchable as $key => $config) {
            $col = is_array($config) ? $key : $config;
            $weight = max(1, min($this->maxWeight, (int) ($config['weight'] ?? 1)));
            $normalized = IllumiSearchHelper::normalizeColumnName($col);
            $val = $processor->process($document[$normalized] ?? $document[$col] ?? '');
            $result["text_w{$weight}"] .= ' ' . $val;
        }

        if (empty(array_filter($result))) {
            $result['text_w1'] = $processor->process(' ' . implode(' ', $document));
        }

        foreach ($result as $col => $val) {
            $result[$col] = trim($val);
        }

        return $result;
    }

    private function buildRow(string $modelClass, int|string $modelId, array $weightCols, ?string $syncedAt = null): array
    {
        $row = [0, $modelClass, (string) $modelId];
        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $row[] = $weightCols["text_w{$w}"] ?? '';
        }
        $row[] = $syncedAt ?? now()->toDateTimeString();

        return $row;
    }

    private function nextId(Collection $collection): int
    {
        static $counter = null;
        if ($counter !== null) {
            return $counter++;
        }
        $max = $collection->max(fn ($r) => (int) ($r[0] ?? 0));
        $counter = $max + 1;

        return $counter;
    }

    private function loadStats(string $modelClass, ?array $onlyTerms = null): ?array
    {
        if (array_key_exists($modelClass, $this->statsCache)) {
            $cached = $this->statsCache[$modelClass];
            if ($cached === self::CACHE_LOAD_FAILED) {
                return null;
            }
            if ($onlyTerms !== null && is_array($cached)) {
                return $this->filterStats($cached, $onlyTerms);
            }

            return is_array($cached) ? $cached : null;
        }

        $loaded = $this->stats->load($modelClass, $onlyTerms);
        $this->statsCache[$modelClass] = $loaded ?? self::CACHE_LOAD_FAILED;

        return $loaded;
    }

    private function filterStats(array $stats, array $onlyTerms): array
    {
        if (! isset($stats['terms'])) {
            return $stats;
        }
        $filtered = [];
        foreach ($onlyTerms as $term) {
            if (isset($stats['terms'][$term])) {
                $filtered[$term] = $stats['terms'][$term];
            }
        }
        $stats['terms'] = $filtered;

        return $stats;
    }

    private function saveStats(string $modelClass, array $stats): void
    {
        $this->statsCache[$modelClass] = $stats;
        $this->stats->save($modelClass, $stats);
    }

    private function rebuildStats(string $modelClass): void
    {
        $chunkDir = $this->modelDir($modelClass);
        $chunks = $this->chunks->listChunks($chunkDir);

        if (empty($chunks)) {
            $this->stats->delete($modelClass);

            return;
        }

        $concurrent = new ConcurrentProcessor(app(IllumiSearchConfig::class)->workers());
        $processor = $this->textProcessor();
        $maxWeight = $this->maxWeight;

        $partialResults = $concurrent->run($chunks, function ($path) use ($processor, $maxWeight) {
            $rows = $this->chunks->decodeFile($path);
            if (! is_array($rows)) {
                return ['terms' => [], 'docCount' => 0, 'totalTokens' => 0];
            }

            $termDocCount = [];
            $docCount = 0;
            $totalTokens = 0;

            foreach ($rows as $row) {
                $docCount++;
                $docTokens = 0;
                for ($w = 1; $w <= $maxWeight; $w++) {
                    $text = $processor->process($row[3 + $w - 1] ?? '');
                    if ($text === '') {
                        continue;
                    }
                    $words = preg_split('/\s+/', $text);
                    $docTokens += count($words);
                    foreach (array_unique($words) as $word) {
                        if ($word !== '') {
                            $termDocCount[$word] = ($termDocCount[$word] ?? 0) + 1;
                        }
                    }
                }
                $totalTokens += $docTokens;
            }

            return ['terms' => $termDocCount, 'docCount' => $docCount, 'totalTokens' => $totalTokens];
        });

        $merged = [];
        $totalDocCount = 0;
        $totalTokens = 0;
        foreach ($partialResults as $pr) {
            $totalDocCount += $pr['docCount'];
            $totalTokens += $pr['totalTokens'];
            foreach ($pr['terms'] as $t => $c) {
                $merged[$t] = ($merged[$t] ?? 0) + $c;
            }
        }

        $this->saveStats($modelClass, [
            'docCount' => $totalDocCount,
            'avgDocLength' => $totalDocCount > 0 ? $totalTokens / $totalDocCount : 0,
            'terms' => $merged,
        ]);
    }

    private function chunkVersion(string $modelClass): string
    {
        $dir = $this->modelDir($modelClass);
        $chunks = $this->chunks->listChunks($dir);

        if (empty($chunks)) {
            return '';
        }

        return collect($chunks)
            ->map(fn ($path) => (new \SplFileInfo($path))->getMTime() . ':' . (new \SplFileInfo($path))->getSize())
            ->implode('|');
    }

    private function rebuildStatsUnlessRebuilding(string $modelClass): void
    {
        if ($this->isRebuilding) {
            return;
        }

        $version = $this->chunkVersion($modelClass);
        $versionFile = $this->stats->path($modelClass) . '.version';

        if (FileFacade::exists($versionFile) && FileFacade::get($versionFile) === $version) {
            return;
        }

        $this->rebuildStats($modelClass);
        $temp = $versionFile . '.' . Str::random(8) . '.tmp';
        FileFacade::put($temp, $version);
        FileFacade::move($temp, $versionFile);
    }

    private function sentinelPath(): string
    {
        return $this->path('.batch_in_progress');
    }

    private function recoverFromCrash(): void
    {
        $path = $this->sentinelPath();
        if (! file_exists($path)) {
            return;
        }

        $raw = file_get_contents($path);
        $pid = $raw !== false ? (int) $raw : 0;

        if ($pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0)) {
            return;
        }

        @unlink($path);

        foreach ($this->getIndexedModelClasses() as $class) {
            $chunkDir = $this->modelDir($class);
            $chunks = $this->chunks->listChunks($chunkDir);
            if (empty($chunks)) {
                continue;
            }

            $processor = $this->textProcessor();
            $maxWeight = $this->maxWeight;
            $termDocCount = [];
            $docCount = 0;
            $totalTokens = 0;

            foreach ($chunks as $path) {
                $rows = $this->chunks->decodeFile($path);
                if (! is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    $docCount++;
                    $docTokens = 0;
                    for ($w = 1; $w <= $maxWeight; $w++) {
                        $text = $processor->process($row[3 + $w - 1] ?? '');
                        if ($text === '') {
                            continue;
                        }
                        $words = preg_split('/\s+/', $text);
                        $docTokens += count($words);
                        foreach (array_unique($words) as $word) {
                            if ($word !== '') {
                                $termDocCount[$word] = ($termDocCount[$word] ?? 0) + 1;
                            }
                        }
                    }
                    $totalTokens += $docTokens;
                }
            }

            $this->saveStats($class, [
                'docCount' => $docCount,
                'avgDocLength' => $docCount > 0 ? $totalTokens / $docCount : 0,
                'terms' => $termDocCount,
            ]);
        }

        @unlink($path);
    }

    private function makeResults(array $results): array
    {
        return array_map(fn ($r) => Result::fromRaw($r), $results);
    }

    private function mergeResults(array &$allResults, array $partial, int $keepMax): void
    {
        foreach ($partial as $pr) {
            array_push($allResults, ...(is_array($pr) ? $pr : []));
        }
        if (count($allResults) > $keepMax) {
            $this->sortByRank($allResults);
            $allResults = array_slice($allResults, 0, $keepMax);
        }
    }

    public function upsert(string $modelClass, int|string $modelId, array $document): void
    {
        $weightCols = $this->buildWeightTexts($modelClass, $document);
        $newRow = $this->buildRow($modelClass, $modelId, $weightCols);
        $dir = $this->modelDir($modelClass);

        foreach ($this->chunks->listChunks($dir) as $path) {
            $rows = $this->chunks->loadRows($path);
            foreach ($rows as $i => $row) {
                if ((string) ($row[2] ?? '') === (string) $modelId) {
                    $newRow[0] = $row[0];
                    $rows[$i] = $newRow;
                    $this->chunks->atomicWrite($path, $rows);
                    $this->searchCache->clear($modelClass);
                    $this->rebuildStatsUnlessRebuilding($modelClass);

                    return;
                }
            }
        }

        $lastPath = $this->chunks->lastChunkPath($dir);
        if ($lastPath !== null) {
            $rows = $this->chunks->loadRows($lastPath);
            if (count($rows) < ChunkStorage::CHUNK_SIZE) {
                $newRow[0] = $this->nextId(collect($rows));
                $rows[] = $newRow;
                $this->chunks->atomicWrite($lastPath, $rows);
                $this->searchCache->clear($modelClass);
                $this->rebuildStatsUnlessRebuilding($modelClass);

                return;
            }
        }

        $newRow[0] = 1;
        $this->chunks->atomicWrite($this->chunks->nextChunkPath($dir), [$newRow]);
        $this->searchCache->clear($modelClass);
        $this->rebuildStatsUnlessRebuilding($modelClass);
    }

    public function delete(string $modelClass, int|string $modelId): void
    {
        $dir = $this->modelDir($modelClass);
        foreach ($this->chunks->listChunks($dir) as $path) {
            $rows = $this->chunks->loadRows($path);
            $found = false;
            foreach ($rows as $i => $row) {
                if ((string) ($row[2] ?? '') === (string) $modelId) {
                    array_splice($rows, $i, 1);
                    $found = true;
                    break;
                }
            }
            if ($found) {
                if (! empty($rows)) {
                    $this->chunks->atomicWrite($path, $rows);
                } else {
                    @unlink($path);
                }
                $this->searchCache->clear($modelClass);
                $this->rebuildStatsUnlessRebuilding($modelClass);

                return;
            }
        }
    }

    public function insertBatch(string $modelClass, array $documents): void
    {
        $allRows = [];
        foreach ($documents as $doc) {
            $weightCols = $this->buildWeightTexts($modelClass, $doc['document']);
            $allRows[] = $this->buildRow($modelClass, $doc['model_id'], $weightCols);
        }
        if (empty($allRows)) {
            return;
        }

        $dir = $this->modelDir($modelClass);
        $lastPath = $this->chunks->lastChunkPath($dir);

        if ($lastPath !== null) {
            $existing = $this->chunks->loadRows($lastPath);
            if (count($existing) < ChunkStorage::CHUNK_SIZE) {
                $merged = array_merge($existing, $allRows);
                if (count($merged) <= ChunkStorage::CHUNK_SIZE) {
                    $this->chunks->atomicWrite($lastPath, $merged);
                    $this->searchCache->clear($modelClass);
                    $this->rebuildStatsUnlessRebuilding($modelClass);

                    return;
                }
                $this->chunks->atomicWrite($lastPath, array_slice($merged, 0, ChunkStorage::CHUNK_SIZE));
                $remaining = array_slice($merged, ChunkStorage::CHUNK_SIZE);
                while (! empty($remaining)) {
                    $chunk = array_slice($remaining, 0, ChunkStorage::CHUNK_SIZE);
                    $remaining = array_slice($remaining, ChunkStorage::CHUNK_SIZE);
                    $this->chunks->atomicWrite($this->chunks->nextChunkPath($dir), $chunk);
                }
                $this->searchCache->clear($modelClass);
                $this->rebuildStatsUnlessRebuilding($modelClass);

                return;
            }
        }

        $this->chunks->atomicWrite($this->chunks->nextChunkPath($dir), $allRows);
        $this->searchCache->clear($modelClass);
        $this->rebuildStatsUnlessRebuilding($modelClass);
    }

    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array
    {
        $this->recoverFromCrash();

        if (empty(trim($query))) {
            return [];
        }

    $cacheKey = $this->searchCache->key($query . $this->basePath . (app(TenantManager::class)->tenantId() ?? ''), $modelClasses, $limit, $offset, $mode);
    $enrichedKey = $this->searchCache->enrichedKey($cacheKey);
    $rawKey = $this->searchCache->rawKey($cacheKey);

    $cachedEnriched = $this->searchCache->get($enrichedKey);
    if ($cachedEnriched !== null) {
        return $this->makeResults($cachedEnriched);
    }

    $cachedRaw = $this->searchCache->get($rawKey);
    if ($cachedRaw !== null) {
        $results = $cachedRaw;
        $searchDone = true;
    } else {
        $searchDone = false;
    }

    $safeQuery = $this->normalizeQuery($query);
    $rawTerms = $this->normalizeQueryTerms($query);
    if (empty($rawTerms)) {
        return [];
    }

    $cleanTerms = $this->extractSearchTerms($rawTerms);
    if (empty($cleanTerms)) {
        return [];
    }

    $allResults = [];
    $total = 0;
    $keepMax = $offset + $limit + 50;

    if (! $searchDone) {
        $rawTerms = $this->reorderByRarity($rawTerms, $modelClasses);

        foreach ($modelClasses as $class) {
            $stats = $this->loadStats($class, $cleanTerms);
            $chunkDir = $this->modelDir($class);
            $chunks = $this->chunks->listChunks($chunkDir);
            if (empty($chunks)) {
                continue;
            }

            if ($this->trigramIndex->load($class)) {
                $results = $this->searchTrigrams($class, $stats, $rawTerms, $cleanTerms, $keepMax);
                if (! empty($results)) {
                    $this->mergeResults($allResults, [$results], $keepMax);
                    $total += count($results);
                    continue;
                }
            }

        $concurrent = new ConcurrentProcessor(app(IllumiSearchConfig::class)->workers());
            $partial = $concurrent->run($chunks, function ($path) use ($class, $stats, $rawTerms) {
                $rows = $this->chunks->decodeFile($path);
                if (! is_array($rows)) {
                    return [];
                }

                return $this->processChunk($rows, $class, $rawTerms, $stats);
            });

            $total += collect($partial)->sum(fn ($p) => count($p));
            $this->mergeResults($allResults, $partial, $keepMax);
        }

            $this->sortByRank($allResults);
            $results = array_slice($allResults, $offset, $limit);
            $results = array_map(fn ($r) => array_merge($r, ['totalCount' => $total]), $results);

            $this->searchCache->set($rawKey, $results);
        }

        if ($withSnippets) {
            $service = $this->snippets ?? app(SnippetService::class);
            $results = $service->enrich($results, $safeQuery);
        }

        $this->searchCache->set($enrichedKey, $results);
        $this->setCollectorEngineInfo(['version' => $this->getEngineVersion(), 'driver' => 'FileEngine']);

        return $this->makeResults($results);
    }

    public function count(string $query, array $modelClasses): int
    {
        if (empty(trim($query))) {
            return 0;
        }
        $rawTerms = OperatorRegistry::tokenize($query);
        if (empty($rawTerms)) {
            return 0;
        }

        $count = 0;
        foreach ($modelClasses as $class) {
            foreach ($this->chunks->listChunks($this->modelDir($class)) as $path) {
                foreach ($this->chunks->loadRows($path) as $r) {
                    $weightTexts = [];
                    for ($w = 1; $w <= $this->maxWeight; $w++) {
                        $weightTexts[$w] = $r[3 + $w - 1] ?? '';
                    }
                    if ($this->match->anyWeightText($weightTexts, $rawTerms)) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    public function getIndexedModelClasses(): array
    {
        $indexDir = $this->path('index/');
        if (! is_dir($indexDir)) {
            return [];
        }

        $classes = [];
        foreach (glob($indexDir . '/*', GLOB_ONLYDIR) as $modelDir) {
            $chunks = $this->chunks->listChunks($modelDir);
            if (! empty($chunks)) {
                $firstChunk = $this->chunks->loadRows($chunks[0]);
                if (! empty($firstChunk)) {
                    $classes[] = $firstChunk[0][1] ?? basename($modelDir);
                }
            }
        }

        return $classes;
    }

    public function getIndexStats(): array
    {
        $indexDir = $this->path('index/');
        if (! is_dir($indexDir)) {
            return [];
        }

        $stats = [];
        foreach (glob($indexDir . '/*', GLOB_ONLYDIR) as $modelDir) {
            $count = 0;
            $synced = null;
            $type = null;
            foreach ($this->chunks->listChunks($modelDir) as $chunk) {
                $rows = $this->chunks->loadRows($chunk);
                if (empty($rows)) {
                    continue;
                }
                $type ??= $rows[0][1] ?? basename($modelDir);
                $count += count($rows);
                foreach ($rows as $row) {
                    $d = $row[3 + $this->maxWeight] ?? null;
                    if ($d !== null && ($synced === null || $d > $synced)) {
                        $synced = $d;
                    }
                }
            }
            if ($count > 0) {
                $stats[] = ['model_class' => $type, 'record_count' => $count, 'last_synced_at' => $synced, 'columns' => null];
            }
        }

        return $stats;
    }

    public function optimize(): array
    {
        return ['vacuum' => ['before' => 0, 'after' => 0], 'tables_optimized' => 0];
    }

    public function getEngineVersion(): string
    {
        return 'FileEngine ' . self::VERSION;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::lock(self::CONFIG_LOCK_KEY, self::CONFIG_LOCK_TIMEOUT)
                ->block(self::CONFIG_LOCK_WAIT, function () use ($key, $default) {
                    $data = $this->chunks->decodeFile($this->path(self::CONFIG_FILE));

                    return is_array($data) ? ($data[$key] ?? $default) : $default;
                });
        } catch (LockTimeoutException) {
            Log::warning('illumi-search: FileEngine config read timed out');

            return $default;
        }
    }

    public function setConfig(string $key, mixed $value): void
    {
        try {
            Cache::lock(self::CONFIG_LOCK_KEY, self::CONFIG_LOCK_TIMEOUT)
                ->block(self::CONFIG_LOCK_WAIT, function () use ($key, $value) {
                    $config = $this->chunks->decodeFile($this->path(self::CONFIG_FILE)) ?: [];
                    $config[$key] = $value;

                    $this->chunks->atomicWrite($this->path(self::CONFIG_FILE), $config);
                });
        } catch (LockTimeoutException) {
            Log::warning('illumi-search: FileEngine config write timed out');
        }
    }

    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void
    {
        $this->chunks->ensureDir($this->modelDir($modelClass));
        $this->ensureVocabFiles();
        $this->ensureMetaFile();
        $this->ensureConfigFile();
    }

    public function dropTable(string $modelClass): void
    {
        $dir = $this->modelDir($modelClass);
        if (is_dir($dir)) {
            foreach ($this->chunks->listChunks($dir) as $f) {
                FileFacade::delete($f);
            }
            FileFacade::deleteDirectory($dir);
        }
        $this->stats->delete($modelClass);
        FileFacade::delete($dir . '.php');
    }

    public function dropIndexTable(string $modelClass): void
    {
        $this->dropTable($modelClass);
    }

    public function listIndexTables(): array
    {
        $path = $this->path('index/');
        if (! is_dir($path)) {
            return [];
        }
        $tables = [];
        foreach (glob($path . '/*', GLOB_ONLYDIR) as $dir) {
            if (! empty($this->chunks->listChunks($dir))) {
                $tables[] = basename($dir);
            }
        }

        return $tables;
    }

    public function tableName(string $modelClass): string
    {
        return basename($this->modelDir($modelClass));
    }

    public function tableExists(string $modelClass): bool
    {
        return is_dir($this->modelDir($modelClass));
    }

    public function getDatabasePath(): string
    {
        return $this->basePath;
    }

    public function getDatabaseSize(): ?int
    {
        $searchDir = $this->basePath;
        if (! FileFacade::isDirectory($searchDir)) {
            return 0;
        }

        return (int) collect(FileFacade::allFiles($searchDir))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->sum(fn ($f) => $f->getSize());
    }

    public function integrityCheck(string $modelClass): bool
    {
        foreach ($this->chunks->listChunks($this->modelDir($modelClass)) as $path) {
            if (! is_array($this->chunks->decodeFile($path))) {
                return false;
            }
        }

        return true;
    }

    public function fullIntegrityCheck(): array
    {
        $errors = [];
        $totalCols = 3 + $this->maxWeight + 1;
        $searchDir = $this->basePath;
        if (! is_dir($searchDir)) {
            return ['passed' => true, 'errors' => []];
        }

        foreach (glob($searchDir . '/**/*.php') as $path) {
            $data = $this->chunks->decodeFile($path);
            if (! is_array($data)) {
                $errors[] = 'Corrupted: ' . basename($path);
                continue;
            }
            foreach ($data as $i => $r) {
                if (! is_array($r) || count($r) !== $totalCols) {
                    $errors[] = 'Invalid row ' . $i . ' in ' . basename($path);
                }
            }
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }

    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array
    {
        // Ensure vocab is built by checking decoded content
        $vocabPath = $this->path('vocab/' . self::VOCAB_WORDS_FILE);
        if (! file_exists($vocabPath)) {
            $this->ensureVocabFiles();
            $this->collectAllWords();
        } else {
            $existing = $this->chunks->decodeFile($vocabPath);
            if (empty($existing)) {
                $this->collectAllWords();
            }
        }

        return $this->vocab->suggest($query, $maxDistance, $limit, $this);
    }

    public function isFts5Available(): bool
    {
        return false;
    }

    public function getEngineStatus(): array
    {
        return [
            'driver' => 'FileEngine',
            'engine_version' => $this->getEngineVersion(),
            'base_path' => $this->basePath,
            'database_size' => $this->getDatabaseSize(),
        ];
    }

    public function getSupportedOperators(): array
    {
        return ['AND', 'OR', 'NOT'];
    }

    public function supportsPhraseSearch(): bool
    {
        return true;
    }

    public function supportsPrefixWildcard(): bool
    {
        return true;
    }

    public function rebuildVocabFromScratch(): void
    {
        foreach ($this->getIndexedModelClasses() as $class) {
            $this->rebuildStats($class);
            $chunks = $this->chunks->listChunks($this->modelDir($class));
            if (! empty($chunks)) {
                $this->trigramIndex->build($class, $chunks, function (array $row) {
                    $texts = [];
                    for ($w = 1; $w <= $this->maxWeight; $w++) {
                        $texts[] = $row[3 + $w - 1] ?? '';
                    }

                    return $texts;
                }, [$this->chunks, 'decodeFile']);
            }
        }
    }

    /**
     * Normalize raw query terms: apply CJK separation so that "学习"
     * becomes "学 习" to match the indexed text (already separated).
     */
    private function normalizeQueryTerms(string $query): array
    {
        $safe = $this->normalizeQuery($query);
        $terms = OperatorRegistry::tokenize($query);

        // If normalised form has CJK spacing, rebuild raw terms accordingly
        if ($safe !== '' && $safe !== Str::lower($query)) {
            $safeTerms = OperatorRegistry::tokenize($safe);

            // Only use safe terms if they differ (CJK separation applied)
            if (count($safeTerms) !== count($terms) || $safeTerms !== $terms) {
                return $safeTerms;
            }
        }

        return $terms;
    }

    /**
     * Score a row and build a result array.
     *
     * @return array|null Result array or null if no match
     */
    private function weightTextsFromRow(array $r): array
    {
        $texts = [];
        for ($w = 1; $w <= $this->maxWeight; $w++) {
            $texts[$w] = $r[$this->chunks->colW($w)] ?? '';
        }
        return $texts;
    }

    private function scoreAndBuildResult(array $r, string $class, array $rawTerms, ?array $stats): ?array
    {
        $weightTexts = $this->weightTextsFromRow($r);

        if (! $this->match->anyWeightText($weightTexts, $rawTerms)) {
            return null;
        }

        $concatenated = implode(' ', array_filter($weightTexts));
        $rank = ($stats !== null && ($stats['docCount'] ?? 0) > 0)
            ? $this->score->bm25Weighted($weightTexts, $rawTerms, $stats, $this->maxWeight)
            : $this->score->quick($concatenated, $rawTerms);

        if ($rank <= 0) {
            $rank = $this->score->quick($concatenated, $rawTerms);
        }

        $modelId = $r[$this->chunks->colModelId()] ?? '';
        if (ctype_digit($modelId)) {
            $modelId = (int) $modelId;
        }

        return [
            'modelClass' => $class,
            'modelId' => $modelId,
            'rank' => $rank,
            'title' => $r[$this->chunks->colW(1)] ?? '',
            'row' => [
                'model_type' => $r[$this->chunks->colModelType()] ?? '',
                'model_id' => $r[$this->chunks->colModelId()] ?? '',
                'search_text' => $this->chunks->docTextFromRow($r),
            ],
            'totalCount' => 0,
        ];
    }

    private function processChunk(array $rows, string $class, array $rawTerms, ?array $stats): array
    {
        $results = [];
        foreach ($rows as $r) {
            $result = $this->scoreAndBuildResult($r, $class, $rawTerms, $stats);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function searchTrigrams(string $class, ?array $stats, array $rawTerms, array $cleanTerms, int $keepMax): ?array
    {
        $query = implode(' ', $cleanTerms);
        $candidates = $this->trigramIndex->candidates($query, $keepMax * 5);
        if (empty($candidates)) {
            return [];
        }

        $results = [];
        $chunkCache = [];
        foreach (array_keys($candidates) as $docId) {
            $loc = $this->trigramIndex->getDocLocation($docId);
            if ($loc === null) {
                continue;
            }

            if (! isset($chunkCache[$loc['path']])) {
                $chunkCache[$loc['path']] = $this->chunks->decodeFile($loc['path']);
            }
            $rows = $chunkCache[$loc['path']];
            if (! is_array($rows) || ! isset($rows[$loc['rowIdx']])) {
                continue;
            }

            $r = $rows[$loc['rowIdx']];
            $result = $this->scoreAndBuildResult($r, $class, $rawTerms, $stats);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function extractSearchTerms(array $rawTerms): array
    {
        $terms = [];
        foreach ($rawTerms as $term) {
            if (in_array(strtoupper($term), ['AND', 'OR', 'NOT', 'NEAR'], true)) {
                continue;
            }
            $clean = mb_strtolower(trim($term, '"*'));
            if ($clean !== '') {
                foreach (preg_split('/\s+/', $clean) as $w) {
                    if ($w !== '') {
                        $terms[] = $w;
                    }
                }
            }
        }

        return array_unique($terms);
    }

    private function reorderByRarity(array $rawTerms, array $modelClasses): array
    {
        $freqMap = [];
        foreach ($modelClasses as $class) {
            $stats = $this->loadStats($class);
            if ($stats === null) {
                continue;
            }
            foreach ($stats['terms'] ?? [] as $term => $freq) {
                $freqMap[$term] = ($freqMap[$term] ?? 0) + $freq;
            }
        }

        $groups = [];
        $i = 0;
        while ($i < count($rawTerms)) {
            $term = $rawTerms[$i];
            $upper = strtoupper($term);
            if ($upper === 'NOT' && $i + 1 < count($rawTerms)) {
                $groups[] = ['type' => 'not_pair', 'terms' => [$term, $rawTerms[$i + 1]]];
                $i += 2;
            } elseif (in_array($upper, ['AND', 'OR', 'NEAR'], true)) {
                $groups[] = ['type' => 'operator', 'terms' => [$term]];
                $i++;
            } else {
                $clean = rtrim(mb_strtolower(trim($term, '"*')), '*');
                $groups[] = ['type' => 'searchable', 'terms' => [$term], 'freq' => $freqMap[$clean] ?? 0];
                $i++;
            }
        }

        $searchable = array_values(array_filter($groups, fn ($g) => $g['type'] === 'searchable'));
        usort($searchable, fn ($a, $b) => $a['freq'] <=> $b['freq']);

        $result = [];
        $si = 0;
        foreach ($groups as $g) {
            if ($g['type'] === 'searchable') {
                array_push($result, ...$searchable[$si]['terms']);
                $si++;
            } else {
                array_push($result, ...$g['terms']);
            }
        }

        return $result;
    }

    private function sortByRank(array &$results): void
    {
        if (empty($results)) {
            return;
        }
        $ranks = array_column($results, 'rank');
        array_multisort($ranks, SORT_DESC, $results);
    }

    private function ensureVocabFiles(): void
    {
        $dir = $this->path('vocab/');
        FileFacade::ensureDirectoryExists($dir);
        foreach ([self::VOCAB_WORDS_FILE, self::VOCAB_TRIGRAMS_FILE] as $f) {
            if (! file_exists($this->path('vocab/' . $f))) {
                $this->chunks->atomicWrite($this->path('vocab/' . $f), []);
            }
        }
    }

    private function ensureMetaFile(): void
    {
        if (! file_exists($this->path(self::META_FILE))) {
            $this->chunks->atomicWrite($this->path(self::META_FILE), []);
        }
    }

    private function ensureConfigFile(): void
    {
        if (! file_exists($this->path(self::CONFIG_FILE))) {
            $this->chunks->atomicWrite($this->path(self::CONFIG_FILE), []);
        }
    }

    private function updateMetaFile(array $classes): void
    {
        $this->chunks->atomicWrite($this->path(self::META_FILE), array_map(fn ($c) => [$c], $classes));
    }

    private function collectAllWords(): void
    {
        $counts = [];
        $indexDir = $this->path('index/');
        if (! is_dir($indexDir)) {
            return;
        }

        foreach (glob($indexDir . '/*', GLOB_ONLYDIR) as $modelDir) {
            foreach ($this->chunks->listChunks($modelDir) as $file) {
                foreach ($this->chunks->loadRows($file) as $row) {
                    $parts = [];
                    for ($w = 1; $w <= $this->maxWeight; $w++) {
                        $parts[] = $row[3 + $w - 1] ?? '';
                    }
                    $text = trim(implode(' ', $parts));
                    foreach (array_unique($this->tokenizeText($text)) as $word) {
                        $counts[$word] ??= ['word' => $word, 'count' => 0, 'ascii' => ($w = new UnicodeString($word)) . ''];
                        $counts[$word]['count']++;
                        $counts[$word]['ascii'] = (string) (new UnicodeString($word))->ascii();
                    }
                }
            }
        }

        usort($counts, fn ($a, $b) => $b['count'] <=> $a['count']);
        if (! empty($counts)) {
            $this->chunks->atomicWrite($this->path('vocab/words.php'), array_map(fn ($w) => [$w['word'], $w['ascii'], $w['count']], $counts));
        }
    }

    public function tokenizeTextForVocab(?string $text): array
    {
        return $this->tokenizeText($text);
    }

    public function getVocabChunks(string $modelClass): array
    {
        return $this->chunks->listChunks($this->modelDir($modelClass));
    }

    public function getVocabRows(string $path): array
    {
        return $this->chunks->loadRows($path);
    }

    public function getMaxWeightForVocab(): int
    {
        return $this->maxWeight;
    }

    public function getVocabWritePath(): string
    {
        return $this->path('vocab/');
    }

    public function getBasePathForVocab(): string
    {
        return $this->basePath;
    }

    public function getVocabPath(): string
    {
        return $this->path('vocab/' . self::VOCAB_WORDS_FILE);
    }

    public function getVocabTrigramPath(): string
    {
        return $this->path('vocab/' . self::VOCAB_TRIGRAMS_FILE);
    }

    public function getVocabChunkRow(array $row, int $w): string
    {
        return $row[3 + $w - 1] ?? '';
    }
}
