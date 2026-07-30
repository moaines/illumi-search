<?php

namespace Moaines\IllumiSearch\Engines;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Meilisearch\Client;
use Moaines\IllumiSearch\Concerns\HasOperatorProcessor;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Result;
use Moaines\IllumiSearch\Support\IllumiSearchConfig;
use Moaines\IllumiSearch\Support\OperatorRegistry;
use Moaines\IllumiSearch\Support\SnippetService;
use Moaines\IllumiSearch\Text\HasTextHelpers;
use Moaines\IllumiSearch\Text\NoopVacuum;
use Moaines\IllumiSearch\Text\NullPragma;
use Moaines\IllumiSearch\Text\StubQueryVocab;

class MeilisearchEngine implements Engine
{
    use NoopVacuum;
    use NullPragma;
    use StubQueryVocab;
    use HasTextHelpers;
    use HasOperatorProcessor;

    private const VERSION = '1.0.0';

    private Client $client;
    private string $basePath;
    private ?IllumiSearchConfig $illumiConfig = null;
    private ?SnippetService $snippets = null;
    private string $prefix = '';
    private bool $isRebuilding = false;

    /** @var array<string, string> modelClass → index uid */
    private array $indexMap = [];

    public function __construct(
        string $host = 'http://localhost:7700',
        string $apiKey = '',
        ?IllumiSearchConfig $illumiConfig = null,
        string $tablePrefix = '',
        string $basePath = '',
        ?SnippetService $snippets = null,
    ) {
        $this->client = new Client($host, $apiKey);
        $this->basePath = $basePath !== '' ? rtrim($basePath, '/') : (rtrim(sys_get_temp_dir(), '/') . '/illumi-search-meilisearch');
        $this->illumiConfig = $illumiConfig;
        $this->snippets = $snippets;
        $this->prefix = $tablePrefix !== '' ? $tablePrefix : '';
        $this->loadIndexMap();
    }

    private function resolvePrefix(): string
    {
        if ($this->prefix !== '') {
            return $this->prefix;
        }

        if ($this->illumiConfig !== null) {
            return $this->illumiConfig->tablePrefix();
        }

        return 'illumi_search_';
    }

    public function setRebuilding(bool $isRebuilding): void
    {
        $this->isRebuilding = $isRebuilding;
    }

    private function indexUid(string $modelClass): string
    {
        if (isset($this->indexMap[$modelClass])) {
            return $this->indexMap[$modelClass];
        }

        $prefix = $this->resolvePrefix();
        $name = str_replace('\\', '_', $modelClass);
        $name = Str::of($name)->replaceMatches('/[^a-zA-Z0-9_]/', '')->lower()->toString();
        $uid = $prefix . $name;

        $this->indexMap[$modelClass] = $uid;
        $this->saveIndexMap();

        return $uid;
    }

    public function upsert(string $modelClass, int|string $modelId, array $document): void
    {
        $uid = $this->indexUid($modelClass);
        $doc = array_merge($document, ['_id' => (string) $modelId]);
        $this->waitForTask($this->client->index($uid)->addDocuments([$doc]));
    }

    public function delete(string $modelClass, int|string $modelId): void
    {
        $uid = $this->indexUid($modelClass);
        $this->waitForTask($this->client->index($uid)->deleteDocument((string) $modelId));
    }

    public function insertBatch(string $modelClass, array $documents): void
    {
        $uid = $this->indexUid($modelClass);
        $docs = [];
        foreach ($documents as $doc) {
            $docs[] = array_merge($doc['document'] ?? $doc, ['_id' => (string) ($doc['model_id'] ?? $doc['id'] ?? '')]);
        }
        $this->waitForTask($this->client->index($uid)->addDocuments($docs));
    }

    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        $query = $this->normalizeQuery($trimmed);
        $hasOr = preg_match('/\bOR\b/i', $query);
        $query = $this->convertQuery($query, $mode);

        $results = [];

        foreach ($modelClasses as $modelClass) {
            if (! $this->tableExists($modelClass)) {
                continue;
            }

            $uid = $this->indexUid($modelClass);

            $raws = $hasOr
                ? $this->orSearch($uid, $query, $limit, $offset)
                : $this->andSearch($uid, $query, $limit, $offset);

            foreach ($raws as $raw) {
                $raw['modelClass'] = $modelClass;
                $results[] = $raw;
            }
        }

        $results = $this->nearFilterResults($results, $query);

        usort($results, fn (array $a, array $b) => $b['rank'] <=> $a['rank']);
        $results = array_slice($results, $offset, $limit);

        if ($withSnippets) {
            $service = $this->snippets ?? app(SnippetService::class);
            $results = $service->enrich($results, $query);
        }

        return array_map(fn (array $r) => Result::fromRaw($r), $results);
    }

    /** @return list<array{modelId: int|string, rank: float, title: string, row: array, totalCount: int}> */
    private function andSearch(string $uid, string $query, int $limit, int $offset): array
    {
        $result = $this->client->index($uid)->search($query, [
            'limit' => $limit + $offset,
            'showRankingScore' => true,
            'matchingStrategy' => 'all',
        ]);

        return array_map(fn (array $hit) => $this->hitToRaw($hit, $result->getEstimatedTotalHits()), $result->getHits());
    }

    /** @return list<array{modelId: int|string, rank: float, title: string, row: array, totalCount: int}> */
    private function orSearch(string $uid, string $query, int $limit, int $offset): array
    {
        $terms = preg_split('/\s+/u', $query);
        $results = [];
        $seen = [];

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            $result = $this->client->index($uid)->search($term, [
                'limit' => $limit + $offset,
                'showRankingScore' => true,
            ]);

            foreach ($result->getHits() as $hit) {
                $rawId = $hit['_id'] ?? '0';
                if (isset($seen[$rawId])) {
                    continue;
                }
                $seen[$rawId] = true;
                $results[] = $this->hitToRaw($hit, $result->getEstimatedTotalHits());
            }
        }

        return $results;
    }

    /** @return array{modelId: int|string, rank: float, title: string, row: array, totalCount: int} */
    private function hitToRaw(array $hit, int $totalCount): array
    {
        $rawId = $hit['_id'] ?? '0';
        return [
            'modelId' => ctype_digit($rawId) ? (int) $rawId : $rawId,
            'rank' => $hit['_rankingScore'] ?? 0.0,
            'title' => $hit['title'] ?? $hit['name'] ?? $rawId,
            'row' => $hit,
            'totalCount' => $totalCount,
        ];
    }

    private function convertQuery(string $query, string $mode): string
    {
        if ($mode === 'raw') {
            return $query;
        }

        $terms = OperatorRegistry::tokenize($query);
        $converted = [];
        $pendingNot = false;

        foreach ($terms as $term) {
            $upper = Str::upper($term);

            if ($pendingNot) {
                $converted[] = '-' . $term;
                $pendingNot = false;
                continue;
            }

            if ($upper === 'NOT') {
                $pendingNot = true;
                continue;
            }

            if (in_array($upper, ['AND', 'NEAR'], true)) {
                continue;
            }

            if ($upper === 'OR') {
                continue;
            }

            if (str_starts_with($term, '"') && str_ends_with($term, '"')) {
                $converted[] = $term;
                continue;
            }

            $clean = rtrim($term, '*');
            $converted[] = $clean;
        }

        return implode(' ', $converted);
    }

    public function count(string $query, array $modelClasses): int
    {
        if (trim($query) === '') {
            return 0;
        }

        $total = 0;

        foreach ($modelClasses as $modelClass) {
            if (! $this->tableExists($modelClass)) {
                continue;
            }

            $uid = $this->indexUid($modelClass);
            $result = $this->client->index($uid)->search($query, ['limit' => 0]);
            $total += $result->getEstimatedTotalHits();
        }

        return $total;
    }

    public function getIndexedModelClasses(): array
    {
        $this->loadIndexMap();
        return array_keys($this->indexMap);
    }

    public function getIndexStats(): array
    {
        $rebuildCompleted = $this->getConfig('rebuild_completed_at');
        $stats = [];
        foreach ($this->indexMap as $modelClass => $uid) {
            try {
                $index = $this->client->index($uid);
                $info = $index->stats();
                $stats[] = [
                    'model_class' => $modelClass,
                    'record_count' => $info['numberOfDocuments'] ?? 0,
                    'last_synced_at' => $rebuildCompleted,
                    'columns' => array_keys($info['fieldDistribution'] ?? []),
                ];
            } catch (\Exception) {
                $stats[] = [
                    'model_class' => $modelClass,
                    'record_count' => 0,
                    'last_synced_at' => $rebuildCompleted,
                    'columns' => [],
                ];
            }
        }
        return $stats;
    }

    public function optimize(): array
    {
        return ['vacuum' => ['before' => 0, 'after' => 0], 'tables_optimized' => count($this->indexMap)];
    }

    public function getEngineVersion(): string
    {
        try {
            $version = $this->client->version();
            return $version['pkgVersion'] ?? self::VERSION;
        } catch (\Exception) {
            return self::VERSION;
        }
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        $config = $this->readConfigFile();
        return $config[$key] ?? $default;
    }

    public function setConfig(string $key, mixed $value): void
    {
        $lock = Cache::lock('illumi-search:config', 5);

        $config = $lock->get(function () use ($key, $value) {
            $cfg = $this->readConfigFile();
            $cfg[$key] = $value;
            $this->writeConfigFile($cfg);
            return $cfg;
        });
    }

    public function createTable(string $modelClass, array $columns, array $prefixLengths = []): void
    {
        $uid = $this->indexUid($modelClass);

        try {
            $task = $this->client->createIndex($uid, ['primaryKey' => '_id']);
            $this->waitForTask($task);
        } catch (\Exception) {
        }

        try {
            $task = $this->client->index($uid)->updateTypoTolerance([
                'minWordSizeForTypos' => [
                    'oneTypo' => 5,
                    'twoTypos' => 7,
                ],
            ]);
            $this->waitForTask($task);
        } catch (\Exception) {
        }
    }

    public function dropTable(string $modelClass): void
    {
        try {
            $uid = $this->indexUid($modelClass);
            $task = $this->client->deleteIndex($uid);
            $this->waitForTask($task);
        } catch (\Exception) {
        }

        unset($this->indexMap[$modelClass]);
        $this->saveIndexMap();
    }

    public function dropIndexTable(string $modelClass): void
    {
        $this->dropTable($modelClass);
    }

    public function tableName(string $modelClass): string
    {
        return $this->indexUid($modelClass);
    }

    public function tableExists(string $modelClass): bool
    {
        $uid = $this->indexUid($modelClass);

        try {
            $this->client->index($uid)->fetchRawInfo();
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function listIndexTables(): array
    {
        try {
            $indexes = $this->client->getIndexes();
            return array_map(fn ($idx) => $idx->getUid(), $indexes->getResults());
        } catch (\Exception) {
            return [];
        }
    }

    public function getDatabasePath(): string
    {
        return $this->basePath;
    }

    public function getDatabaseSize(): ?int
    {
        try {
            $global = $this->client->stats();
            return $global['databaseSize'] ?? null;
        } catch (\Exception) {
            return null;
        }
    }

    public function integrityCheck(string $modelClass): bool
    {
        return $this->tableExists($modelClass);
    }

    public function fullIntegrityCheck(): array
    {
        $errors = [];
        foreach ($this->indexMap as $modelClass => $uid) {
            try {
                $this->client->index($uid)->fetchRawInfo();
            } catch (\Exception $e) {
                $errors[] = "Index {$uid} ({$modelClass}): {$e->getMessage()}";
            }
        }
        return ['passed' => empty($errors), 'errors' => $errors];
    }

    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array
    {
        $suggestions = [];
        $scored = [];
        $lowerQuery = mb_strtolower($query);

        foreach ($this->indexMap as $modelClass => $uid) {
            try {
                $result = $this->client->index($uid)->search($query, [
                    'limit' => $limit * 3,
                    'showRankingScore' => true,
                ]);

                foreach ($result->getHits() as $hit) {
                    $text = $hit['title'] ?? $hit['name'] ?? $hit['body'] ?? '';
                    $words = preg_split('/[\s,;.\-!?()]+/u', mb_strtolower($text));

                    foreach ($words as $word) {
                        $word = trim($word);
                        if ($word === '' || $word === $lowerQuery || strlen($word) < 3) {
                            continue;
                        }

                        $dist = self::levenshteinDistance($lowerQuery, $word);
                        if ($dist !== -1 && $dist <= $maxDistance && $dist > 0) {
                            $existingScore = $scored[$word] ?? PHP_INT_MAX;
                            if ($dist < $existingScore) {
                                $scored[$word] = $dist;
                            }
                        }
                    }
                }
            } catch (\Exception) {
                continue;
            }
        }

        asort($scored);
        $suggestions = array_keys(array_slice($scored, 0, $limit));

        if (empty($suggestions)) {
            foreach ($this->indexMap as $modelClass => $uid) {
                try {
                    $result = $this->client->index($uid)->search($query, [
                        'limit' => $limit,
                        'showRankingScore' => true,
                    ]);

                    foreach ($result->getHits() as $hit) {
                        $title = $hit['title'] ?? $hit['name'] ?? '';
                        if ($title !== '' && strcasecmp($title, $query) !== 0) {
                            $suggestions[] = $title;
                        }
                    }
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return array_values(array_unique(array_slice($suggestions, 0, $limit)));
    }

    public function getSupportedOperators(): array
    {
        return ['AND', 'OR', 'NOT', 'NEAR'];
    }

    public function supportsPhraseSearch(): bool
    {
        return true;
    }

    public function supportsPrefixWildcard(): bool
    {
        return true;
    }

    public function isFts5Available(): bool
    {
        return false;
    }

    public function getEngineStatus(): array
    {
        $version = $this->getEngineVersion();
        $indexCount = count($this->indexMap);
        $docCount = 0;

        foreach ($this->indexMap as $uid) {
            try {
                $info = $this->client->index($uid)->stats();
                $docCount += $info['numberOfDocuments'] ?? 0;
            } catch (\Exception) {
            }
        }

        return [
            'driver' => 'Meilisearch',
            'engine' => 'Meilisearch',
            'version' => $version,
            'indexes' => $indexCount,
            'total_documents' => $docCount,
            'database_path' => $this->basePath,
        ];
    }

    private function readConfigFile(): array
    {
        $path = $this->configPath();

        if (! File::exists($path)) {
            return [];
        }

        $data = json_decode(File::get($path), true);
        return is_array($data) ? $data : [];
    }

    private function writeConfigFile(array $config): void
    {
        $path = $this->configPath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($config, JSON_PRETTY_PRINT));
    }

    private function configPath(): string
    {
        return $this->basePath . '/config.json';
    }

    private function waitForTask(array $task): void
    {
        $uid = $task['taskUid'] ?? null;
        if ($uid !== null) {
            try {
                $result = $this->client->waitForTask($uid);
                if (is_array($result) && ($result['status'] ?? '') === 'failed') {
                    $error = $result['error']['message'] ?? 'unknown error';
                    logger()->warning("Meilisearch task {$uid} failed: {$error}");
                }
            } catch (\Exception $e) {
                logger()->warning("Meilisearch waitForTask({$uid}) threw: {$e->getMessage()}");
            }
        }
    }

    private function loadIndexMap(): void
    {
        $config = $this->readConfigFile();
        $this->indexMap = $config['_index_map'] ?? [];
    }

    private function saveIndexMap(): void
    {
        $config = $this->readConfigFile();
        $config['_index_map'] = $this->indexMap;
        $this->writeConfigFile($config);
    }
}
