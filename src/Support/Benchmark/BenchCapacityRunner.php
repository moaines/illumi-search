<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

use Moaines\IllumiSearch\Contracts\Engine;

class BenchCapacityRunner
{
    private const DEFAULT_STEPS = [1_000, 10_000, 100_000, 500_000, 1_000_000];
    private const SEARCH_QUERIES = [
        'laravel', 'php AND laravel', 'php OR python', 'php NOT java',
        '"design patterns"', 'prog*', 'design NEAR patterns', 'développement',
        '软件', 'برمج', 'проект', 'programing',
    ];
    private const SUGGEST_QUERIES = ['programing', 'laravell', 'desarollo'];
    private const STOP_LATENCY = 100.0;
    private const STOP_REBUILD = 500.0;

    private ?float $stopRebuildThreshold = self::STOP_REBUILD;

    private MetricCollector $metrics;
    private bool $stopped = false;
    private string $bottleneck = '';
    private bool $latinOnly = false;

    public function __construct(
        private readonly Engine $engine,
        private readonly string $modelClass,
        private readonly array $columns,
    ) {
        $this->metrics = new MetricCollector;
    }

    public function setLatinOnly(bool $value): void
    {
        $this->latinOnly = $value;
    }

    public function setStopRebuildThreshold(?float $threshold): void
    {
        $this->stopRebuildThreshold = $threshold;
    }

    /** @return array<int, VolumeSnapshot> */
    public function run(int $targetDocs, ?array $steps = null): array
    {
        $steps ??= $this->resolveSteps($targetDocs);
        $snapshots = [];

        foreach ($steps as $volume) {
            if ($this->stopped) {
                break;
            }

            $snapshot = $this->testVolume($volume);
            $snapshots[$volume] = $snapshot;

            $this->checkStopConditions($snapshot, $volume);
        }

        return $snapshots;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function bottleneck(): string
    {
        return $this->bottleneck;
    }

    private function resolveSteps(int $targetDocs): array
    {
        $steps = [];
        foreach (self::DEFAULT_STEPS as $s) {
            if ($s <= $targetDocs * 1.1) {
                $steps[] = $s;
            }
        }
        if (! in_array($targetDocs, $steps, true)) {
            $steps[] = $targetDocs;
        }
        return $steps;
    }

    private function testVolume(int $volume): VolumeSnapshot
    {
        $start = microtime(true);

        $this->engine->dropTable($this->modelClass);
        $this->engine->createTable($this->modelClass, $this->columns);

        $this->metrics->recordPeakBefore();

        // Scale data
        $this->engine->setRebuilding(true);
        $scaled = (int) min($volume, 100000);
        $sample = $this->generateSample($scaled);
        foreach (array_chunk($sample, 100) as $batch) {
            $this->engine->insertBatch($this->modelClass, $batch);
        }
        $this->engine->setRebuilding(false);

        // Search benchmarks
        $searchQueries = $this->latinOnly
            ? array_values(array_filter(self::SEARCH_QUERIES, fn ($q) => ! $this->isNonLatin($q)))
            : self::SEARCH_QUERIES;
        $searchResults = [];
        foreach ($searchQueries as $q) {
            $t0 = microtime(true);
            $results = $this->engine->search($q, [$this->modelClass], 10);
            $elapsed = (microtime(true) - $t0) * 1000;
            $searchResults[] = ['query' => $q, 'durationMs' => $elapsed, 'count' => count($results)];
        }

        // Suggest benchmarks
        $suggestQueries = $this->latinOnly
            ? array_values(array_filter(self::SUGGEST_QUERIES, fn ($q) => ! $this->isNonLatin($q)))
            : self::SUGGEST_QUERIES;
        $suggestResults = [];
        foreach ($suggestQueries as $q) {
            $t0 = microtime(true);
            $suggestions = method_exists($this->engine, 'suggest') ? $this->engine->suggest($q, 2, 5) : [];
            $elapsed = (microtime(true) - $t0) * 1000;
            $suggestResults[] = ['query' => $q, 'durationMs' => $elapsed, 'suggestions' => $suggestions];
        }

        // Capture peak memory during search phase (before GC frees trigram index)
        $this->metrics->recordPeakMemorySearch();

        // Rebuild speed
        $t0 = microtime(true);
        $this->engine->dropTable($this->modelClass);
        $this->engine->createTable($this->modelClass, $this->columns);
        $this->engine->setRebuilding(true);
        foreach (array_chunk($sample, 100) as $batch) {
            $this->engine->insertBatch($this->modelClass, $batch);
        }
        $this->engine->setRebuilding(false);
        $rebuildTime = microtime(true) - $t0;

        $this->metrics->recordPeakMemory();
        $peakRam = $this->metrics->getPeakMemoryMb();

        // Index size
        $indexSize = method_exists($this->engine, 'getDatabaseSize') ? $this->engine->getDatabaseSize() : 0;

        $this->metrics->recordPeakMemory();

        // For FileEngine at large volumes, free memory between measurement
        // to avoid GC artifacts affecting the next test volume.
        if ($this->engine instanceof \Moaines\IllumiSearch\Engines\FileEngine) {
            gc_collect_cycles();
        }

        $searchDurations = array_column($searchResults, 'durationMs');
        sort($searchDurations);
        $count = count($searchDurations);

        return new VolumeSnapshot(
            docs: $volume,
            totalTimeMs: (microtime(true) - $start) * 1000,
            searchQps: $count > 0 && array_sum($searchDurations) > 0 ? ($count / (array_sum($searchDurations) / 1000)) : 0,
            latencyP50: $count > 0 ? $searchDurations[(int) ($count * 0.50)] : 0,
            latencyP95: $count > 0 ? $searchDurations[(int) ($count * 0.95)] : 0,
            latencyP99: $count > 0 ? $searchDurations[(int) ($count * 0.99)] : 0,
            suggestQps: $this->computeSuggestQps($suggestResults),
            rebuildDocsPerSec: $rebuildTime > 0 ? $volume / $rebuildTime : 0,
            indexSizeMb: (int) ($indexSize / (1024 * 1024)),
            peakRamMb: $peakRam,
            quality: $this->assessQuality($searchResults, $suggestResults),
        );
    }

    private function computeSuggestQps(array $results): float
    {
        $total = array_sum(array_column($results, 'durationMs'));
        $count = count($results);
        return $count > 0 && $total > 0 ? ($count / ($total / 1000)) : 0;
    }

    /** @return array<string, bool> */
    private function assessQuality(array $searchResults, array $suggestResults): array
    {
        $exactFound = 0;
        foreach ($searchResults as $r) {
            if ($r['count'] > 0) $exactFound++;
        }
        $suggestOk = 0;
        foreach ($suggestResults as $r) {
            if (! empty($r['suggestions'])) $suggestOk++;
        }

        return [
            'fuzzy_tolerance' => $suggestOk >= 1,
            'suggest_coverage' => count($suggestResults) > 0 && $suggestOk === count($suggestResults),
            'exact_search' => $exactFound >= 6,
        ];
    }

    private function generateSample(int $count): array
    {
        $docs = [];
        $words = ['php', 'laravel', 'framework', 'guide', 'software', 'design', 'patterns',
            'programming', 'python', 'javascript', 'développement', '软件', 'проект', 'برمج',
            'desarrollo', 'engenharia'];
        for ($i = 1; $i <= $count; $i++) {
            $docs[] = [
                'model_id' => $i,
                'document' => [
                    'title' => $words[array_rand($words)] . " guide {$i}",
                    'body' => implode(' ', array_map(fn () => $words[array_rand($words)], range(1, 20))),
                ],
            ];
        }
        return $docs;
    }

    private function isNonLatin(string $text): bool
    {
        return (bool) preg_match('/[^\x20-\x7E]/u', $text);
    }

    private function checkStopConditions(VolumeSnapshot $snapshot, int $volume): void
    {
        if ($snapshot->latencyP50 > self::STOP_LATENCY) {
            $this->stopped = true;
            $this->bottleneck = "Latency p50 ({$snapshot->latencyP50}ms) exceeded " . self::STOP_LATENCY . "ms threshold at {$volume} docs";
        } elseif ($this->stopRebuildThreshold !== null && $snapshot->rebuildDocsPerSec > 0 && $snapshot->rebuildDocsPerSec < $this->stopRebuildThreshold) {
            $this->stopped = true;
            $this->bottleneck = "Rebuild speed ({$snapshot->rebuildDocsPerSec} d/s) dropped below {$this->stopRebuildThreshold} d/s threshold at {$volume} docs";
        } elseif ($snapshot->peakRamMb > 500) {
            $this->stopped = true;
            $this->bottleneck = "Peak RAM ({$snapshot->peakRamMb}MB) exceeded 500MB threshold at {$volume} docs";
        }
    }
}
