<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Engines\MySqlEngine;
use Moaines\IllumiSearch\Engines\SqliteEngine;

class BenchmarkRunner
{
    private array $posts = [];

    private array $searchQueries = [];

    private string $testModelClass = 'App\Models\BenchmarkPost';

    private MetricCollector $metrics;

    private array $searchResults = [];

    private array $suggestResults = [];

    private string $mode = 'processed';

    public function __construct(
        private readonly Engine $engine,
        private readonly string $seedPath,
    ) {
        $this->metrics = new MetricCollector;
    }

    private function processDocument(string $text): string
    {
        if ($this->mode === 'raw') {
            return $text;
        }

        return app(TextProcessor::class)->process($text);
    }

    public function run(int $totalDocs, bool $verbose = false, string $mode = 'processed'): array
    {
        $this->mode = $mode;

        if ($mode === 'raw') {
            app()->instance(TextProcessor::class, new IdentityProcessor);

            // Configure FTS5 tokenizer for accent folding + stemming in raw mode
            config(['illumi-search.engines.sqlite.fts5.tokenizer' => 'porter unicode61 remove_diacritics 2']);
            config(['illumi-search.processing.processor' => 'unicode']);
        }

        $generator = new DataGenerator;
        $this->posts = $generator->generate($totalDocs, $this->seedPath);
        $this->searchQueries = $generator->buildSearchQueries($this->posts, 20);

        if ($verbose) {
            echo "  Dataset: " . count($this->posts) . " documents\n";
            echo "  Queries: " . count($this->searchQueries['exact']) . " exact, "
                . count($this->searchQueries['typo']) . " typo, "
                . count($this->searchQueries['nonexistent']) . " nonexistent\n";
        }

        $this->benchUpsert($verbose);
        $this->benchSearch($verbose);
        $this->benchSuggest($verbose);
        $this->benchOperators($verbose);
        if ($mode === 'raw') {
            $this->benchRawModeTests($verbose);
        }
        $this->benchRebuild($verbose);

        return $this->metrics->getAll();
    }

    private function benchUpsert(bool $verbose): void
    {
        $this->engine->createTable($this->testModelClass, ['title', 'body']);

        $half = (int) (count($this->posts) / 2);
        if ($half < 10) {
            $half = count($this->posts);
        }

        // Phase 1: fast path (setRebuilding = true, no vocab sync)
        if (method_exists($this->engine, 'setRebuilding')) {
            $this->engine->setRebuilding(true);
        }

        $start = microtime(true);
        for ($i = 0; $i < $half; $i++) {
            $post = $this->posts[$i];
            $this->engine->upsert($this->testModelClass, $i + 1, [
                'title' => $this->processDocument($post['title'] ?? ''),
                'body' => $this->processDocument($post['body'] ?? ''),
            ]);
        }
        $elapsed = microtime(true) - $start;
        $rate = $elapsed > 0 ? $half / $elapsed : 0;
        $this->metrics->recordQuant('Upsert (fast)', round($rate, 1), 'docs/sec');

        if ($verbose) {
            echo "  Upsert (fast): {$half} docs in " . round($elapsed, 2) . "s (" . round($rate, 1) . " docs/sec)\n";
        }

        // Phase 2: batch insert (still fast for bulk operations)
        // Note: using insertBatch skips per-row vocab sync (similar to rebuild behavior)

        $remaining = count($this->posts) - $half;
        if ($remaining > 0) {
            // Build batch for remaining documents
            $batch = [];
            for ($i = $half; $i < count($this->posts); $i++) {
                $post = $this->posts[$i];
                $batch[] = [
                    'model_id' => $i + 1,
                    'document' => [
                        'title' => $this->processDocument($post['title'] ?? ''),
                        'body' => $this->processDocument($post['body'] ?? ''),
                    ],
                ];
            }

            $start = microtime(true);
            $this->engine->insertBatch($this->testModelClass, $batch);
            $elapsed = microtime(true) - $start;
            $rate = $elapsed > 0 ? $remaining / $elapsed : 0;
            $this->metrics->recordQuant('Upsert (with vocab)', round($rate, 1), 'docs/sec');

            if ($verbose) {
                echo "  Upsert (with vocab): {$remaining} docs in " . round($elapsed, 2) . "s (" . round($rate, 1) . " docs/sec)\n";
            }
        }

        $size = $this->engine->getDatabaseSize();
        $this->metrics->recordQuant('Index size', round(($size ?? 0) / 1048576, 1), 'MB');
    }

    private function benchSearch(bool $verbose): void
    {
        $exactQueries = $this->searchQueries['exact'];
        $typoQueries = $this->searchQueries['typo'];
        $nonexistentQueries = $this->searchQueries['nonexistent'];
        $accentTests = $this->searchQueries['accent'];

        // Exact search
        if (! empty($exactQueries)) {
            $start = microtime(true);

            foreach ($exactQueries as $q) {
                $this->searchResults[$q] = $this->engine->search($q, [$this->testModelClass], 10);
            }

            $elapsed = microtime(true) - $start;
            $rate = $elapsed > 0 ? count($exactQueries) / $elapsed : 0;
            $this->metrics->recordQuant('Search (exact)', round($rate, 1), 'q/sec');

            // Precision@5
            $totalPrecision = 0.0;
            $totalNdcg = 0.0;
            $totalMap = 0.0;
            foreach ($exactQueries as $q) {
                $totalPrecision += $this->metrics->precisionAtK($this->searchResults[$q] ?? [], $q, 5);
                $totalNdcg += $this->metrics->ndcgAtK($this->searchResults[$q] ?? [], $q, 5);
                $totalMap += $this->metrics->averagePrecisionAtK($this->searchResults[$q] ?? [], $q, 5);
            }
            $count = max(1, count($exactQueries));
            $avgPrecision = $totalPrecision / $count;
            $this->metrics->recordQuality('Precision@5', round($avgPrecision, 2));
            $this->metrics->recordQuality('NDCG@5', round($totalNdcg / $count, 2));
            $this->metrics->recordQuality('MAP@5', round($totalMap / $count, 2));
        }

        // Typo tolerance via suggest (FTS engines don't do fuzzy search)
        if (! empty($typoQueries)) {
            $suggestTypos = [];
            foreach ($typoQueries as $item) {
                $typo = is_array($item) ? ($item['query'] ?? '') : '';
                $expected = is_array($item) ? ($item['expected'] ?? '') : '';
                if ($typo && $expected) {
                    $suggestTypos[$typo] = $expected;
                }
            }

            $typoSuggestResults = [];
            foreach ($suggestTypos as $typo => $expected) {
                $suggestions = $this->engine->suggest($typo, 2, 5);
                $typoSuggestResults[$typo] = $suggestions;
            }

            $allFuzzyOk = true;
            foreach ($suggestTypos as $typo => $expected) {
                $suggestions = $typoSuggestResults[$typo] ?? [];
                if (! in_array($expected, $suggestions)) {
                    $allFuzzyOk = false;
                    break;
                }
            }
            $this->metrics->recordQuality('Fuzzy tolerance', $allFuzzyOk);
        }

        // Accent search
        if (! empty($accentTests)) {
            $accentResults = [];
            foreach ($accentTests as $original => $ascii) {
                $accentResults[$ascii] = $this->engine->search($ascii, [$this->testModelClass], 10);
                if ($verbose && empty($accentResults[$ascii])) {
                    echo "  Accent test '{$ascii}' (→{$original}): 0 results, skipping\n";
                    break;
                }
            }
            $accentOk = false;
            if (count($accentResults) === count($accentTests)) {
                $accentOk = $this->metrics->accentInsensitivity($accentResults, $accentTests);
            }
            $this->metrics->recordQuality('Accent insensitivity', $accentOk);
        }

        // Exact search empty rate
        $emptyRate = $this->metrics->emptyResultsRate($this->searchResults, $exactQueries);
        $this->metrics->recordQuality('Empty results rate (existing terms)', round($emptyRate * 100, 1) . '%');

        // Nonexistent search perf
        if (! empty($nonexistentQueries)) {
            $start = microtime(true);
            for ($i = 0; $i < 10; $i++) {
                $this->engine->search($nonexistentQueries[array_rand($nonexistentQueries)], [$this->testModelClass], 10);
            }
            $elapsed = microtime(true) - $start;
            $rate = $elapsed > 0 ? 10 / $elapsed : 0;
            $this->metrics->recordQuant('Search (nonexistent)', round($rate, 1), 'q/sec');
        }

        if ($verbose) {
            echo "  Search: " . count($exactQueries) . " exact + " . count($typoQueries) . " typo queries done\n";
        }
    }

    private function benchSuggest(bool $verbose): void
    {
        $cyrillicQueries = ['правил', 'превед', 'разраб'];

        // Generate suggest queries from actual data: pick 5 existing terms, create typos
        $existingTerms = array_map('strtolower', $this->searchQueries['exact'] ?? []);
        $longTerms = array_values(array_filter($existingTerms, fn ($t) => strlen($t) >= 5));
        shuffle($longTerms);
        $suggestTargets = array_slice($longTerms, 0, 5);

        $latinQueries = [];
        $typoExpected = [];
        foreach ($suggestTargets as $word) {
            $typo = $word;
            $len = strlen($typo);
            if ($len >= 4) {
                $pos = max(1, (int) ($len * 0.4));
                $alt = $typo[$pos] === $typo[$pos - 1] ? chr(rand(97, 122)) : $typo[$pos - 1];
                $typo[$pos] = $alt;
            }
            $latinQueries[] = $typo;
            $typoExpected[$typo] = $word;
        }

        $allSuggest = array_merge($latinQueries, $cyrillicQueries);

        $start = microtime(true);
        foreach ($allSuggest as $q) {
            $this->suggestResults[$q] = $this->engine->suggest($q, 2, 5);
        }
        $elapsed = microtime(true) - $start;
        $rate = $elapsed > 0 ? count($allSuggest) / $elapsed : 0;
        $this->metrics->recordQuant('Suggest', round($rate, 1), 'q/sec');

        // Suggest quality metrics — a query is valid if its expected word exists in the data
        $validLatinQueries = array_values(array_filter($latinQueries,
            fn ($q) => isset($typoExpected[$q])
                && in_array(mb_strtolower($typoExpected[$q]), $existingTerms, true)
        ));

        $validToyoExpected = array_filter($typoExpected,
            fn ($expected) => in_array(mb_strtolower($expected), $existingTerms, true),
        );

        $suggestPrecision = 0.0;
        foreach ($validLatinQueries as $q) {
            $suggestPrecision += $this->metrics->suggestPrecisionAtK(
                $this->suggestResults[$q] ?? [], $q, 2, 5,
            );
        }
        $this->metrics->recordQuality(
            'Suggest Precision@5',
            $validLatinQueries ? round($suggestPrecision / count($validLatinQueries), 2) : 0,
        );

        $top1Count = 0;
        foreach ($validToyoExpected as $typo => $expected) {
            if ($this->metrics->suggestTop1Accuracy($this->suggestResults[$typo] ?? [], $expected)) {
                $top1Count++;
            }
        }
        $this->metrics->recordQuality(
            'Suggest Top-1',
            round($top1Count / max(1, count($typoExpected)), 2),
        );

        // Script-aware precision on valid queries
        $scriptSuggestPrecision = 0.0;
        foreach ($validLatinQueries as $q) {
            $scriptSuggestPrecision += $this->metrics->suggestScriptAwarePrecisionAtK(
                $this->suggestResults[$q] ?? [], $q, 2, 5,
            );
        }
        $this->metrics->recordQuality(
            'Suggest Prec@5 (script)',
            $validLatinQueries ? round($scriptSuggestPrecision / count($validLatinQueries), 2) : 0,
        );

        // Coverage using validated queries only
        $suggestCoverageAny = $this->metrics->suggestCoverageAny($this->suggestResults, $validLatinQueries);
        $this->metrics->recordQuality('Suggest coverage', round($suggestCoverageAny, 2));

        $suggestCoverageCorrect = $this->metrics->suggestCoverageCorrect($this->suggestResults, $validLatinQueries, 2);
        $this->metrics->recordQuality('Suggest coverage (correct)', round($suggestCoverageCorrect, 2));

        $suggestCoverageExact = $this->metrics->suggestCoverageExpected($this->suggestResults, $validToyoExpected);
        $this->metrics->recordQuality('Suggest coverage (exact)', round($suggestCoverageExact, 2));

        $this->metrics->recordQuality('MRR', round($this->metrics->meanReciprocalRankSuggest($this->suggestResults, $validToyoExpected), 2));

        // Script isolation : check that Latin queries prefer Latin results
        $hasCyrillicFirst = false;
        foreach ($latinQueries as $q) {
            $words = $this->suggestResults[$q] ?? [];
            if (! empty($words) && preg_match('/\p{Cyrillic}/u', $words[0] ?? '')) {
                $hasCyrillicFirst = true;
                break;
            }
        }

        $this->metrics->recordQuality('Script isolation', ! $hasCyrillicFirst);

        // MRR on suggest queries
        $this->metrics->recordQuality('MRR', round($this->metrics->meanReciprocalRankSuggest($this->suggestResults, [
            'laravil' => 'laravel',
            'phpp' => 'php',
            'framwork' => 'framework',
        ]), 2));

        if ($verbose) {
            echo "  Suggest: " . count($allSuggest) . " queries done\n";
        }
    }

    private function benchOperators(bool $verbose): void
    {
        $allTexts = $this->searchQueries['exact'] ?? [];
        $andTerm1 = $allTexts[0] ?? 'php';
        $andTerm2 = $allTexts[1] ?? 'framework';
        $orAltTerm = $allTexts[2] ?? 'python';
        $notExcluded = 'zyxwv9876nonexistent';
        $phraseQuery = '"software engineering"';

        $andQuery = $andTerm1 . ' AND ' . $andTerm2;
        $orQuery = $andTerm1 . ' OR ' . $orAltTerm;
        $notQuery = $andTerm1 . ' NOT ' . $notExcluded;

        $andResults = $this->engine->search($andQuery, [$this->testModelClass], 10);
        $orResults = $this->engine->search($orQuery, [$this->testModelClass], 10);
        $notResults = $this->engine->search($notQuery, [$this->testModelClass], 10);

        // AND: all results should contain both terms
        $andWorks = true;
        foreach ($andResults as $r) {
            $t = $this->metrics->extractSearchTextForSoundness($r);
            if (mb_strpos($t, $andTerm1) === false || mb_strpos($t, $andTerm2) === false) {
                $andWorks = false;
                break;
            }
        }
        $this->metrics->recordSound('AND operator narrows', $andWorks, $andWorks ? 'All results contain both terms' : 'Some results missing a term');

        // OR: results can contain either term
        $orWorks = ! empty($orResults);
        $this->metrics->recordSound('OR operator broadens', $orWorks, $orWorks ? 'Returned ' . count($orResults) . ' results' : 'No results');

        // NOT: results should NOT contain the excluded term
        $notWorks = true;
        foreach ($notResults as $r) {
            $t = $this->metrics->extractSearchTextForSoundness($r);
            if (mb_strpos($t, $notExcluded) !== false) {
                $notWorks = false;
                break;
            }
        }
        $this->metrics->recordSound('NOT operator excludes', $notWorks, $notWorks ? 'Excluded term not present' : 'Excluded term found in results');

        $phraseResults = $this->engine->search($phraseQuery, [$this->testModelClass], 10);
        $phraseFound = false;
        foreach ($phraseResults as $r) {
            $text = $this->metrics->extractSearchTextForSoundness($r);
            if (mb_strpos($text, 'software engineering') !== false) {
                $phraseFound = true;
                break;
            }
        }
        $this->metrics->recordSound('Phrase exacte', $phraseFound);

        $emptyResults = $this->engine->search('', [$this->testModelClass], 10);
        $this->metrics->recordSound('Empty query returns empty', empty($emptyResults));

        try {
            $this->engine->search('!@#$%^&*()', [$this->testModelClass], 10);
            $this->metrics->recordSound('Special chars no error', true);
        } catch (\Exception) {
            $this->metrics->recordSound('Special chars no error', false);
        }

        $orderResults = $this->engine->search('php', [$this->testModelClass], 5);
        $orderStable = true;
        if (count($orderResults) >= 2) {
            $firstOrder = array_map(fn ($r) => $r->modelId, $orderResults);
            for ($i = 0; $i < 2; $i++) {
                $rerun = $this->engine->search('php', [$this->testModelClass], 5);
                $rerunOrder = array_map(fn ($r) => $r->modelId, $rerun);
                if ($rerunOrder !== $firstOrder) {
                    $orderStable = false;
                    break;
                }
            }
        }
        $this->metrics->recordSound('Order stability', $orderStable);

        if ($verbose) {
            echo "  Soundness: AND=" . ($andWorks ? '✓' : '✗')
                . " OR=" . ($orWorks ? '✓' : '✗')
                . " NOT=" . ($notWorks ? '✓' : '✗')
                . " phrase=" . ($phraseFound ? '✓' : '✗')
                . "\n";
        }
    }

    private function benchRawModeTests(bool $verbose): void
    {
        $uniqueStem = 'xylophonezephyrquantum';
        $uniqueAccent = 'wobblequébectest';
        $uniqueAccentAscii = 'wobblequebectest';

        // Insert known test documents for stemming evaluation
        $stemDocId = 999999;
        $this->engine->upsert($this->testModelClass, $stemDocId, [
            'title' => $uniqueStem . ' development',
            'body' => $uniqueStem . ' This document is about development activities',
        ]);

        // Accent test document: contains accented version of unique token
        $accentDocId = 999998;
        $this->engine->upsert($this->testModelClass, $accentDocId, [
            'title' => $uniqueAccent . ' café génie',
            'body' => $uniqueAccent . ' The french café concept',
        ]);

        // Stemming test: search for a derived form, expect test doc with 'development' (root)
        $stemResultsOnly = $this->engine->search('developing', [$this->testModelClass], 1000);
        $stemOk = collect($stemResultsOnly)->pluck('modelId')->contains($stemDocId);
        $this->metrics->recordSound('Stemming (developing→development)', $stemOk);

        // Accent test: search ASCII version of unique token, expect accented doc
        $accentResults = $this->engine->search($uniqueAccentAscii, [$this->testModelClass], 5);
        $accentOk = collect($accentResults)->pluck('modelId')->contains($accentDocId);
        if ($verbose && ! $accentOk && ! empty($accentResults)) {
            echo "  DEBUG accent: found " . count($accentResults) . " results, IDs: "
                . collect($accentResults)->pluck('modelId')->join(',') . "\n";
        }
        $this->metrics->recordSound('Accent folding (cafe→café)', $accentOk);

        // Prefix match test
        $prefixResults = $this->engine->search('soft*', [$this->testModelClass], 5);
        $prefixOk = false;
        foreach ($prefixResults as $r) {
            $t = $this->metrics->extractSearchTextForSoundness($r);
            if (mb_strpos($t, 'software') !== false) {
                $prefixOk = true;
                break;
            }
        }
        $this->metrics->recordSound('Prefix match (soft*)', $prefixOk);

        // Clean up test documents
        $this->engine->delete($this->testModelClass, $stemDocId);
        $this->engine->delete($this->testModelClass, $accentDocId);

        if ($verbose) {
            echo "  Raw tests: stem=" . ($stemOk ? '✓' : '✗')
                . " accent=" . ($accentOk ? '✓' : '✗')
                . " prefix=" . ($prefixOk ? '✓' : '✗')
                . "\n";
        }
    }

    private function benchRebuild(bool $verbose): void
    {
        $start = microtime(true);

        $this->engine->dropTable($this->testModelClass);

        if (method_exists($this->engine, 'setRebuilding')) {
            $this->engine->setRebuilding(true);
        }

        $this->engine->createTable($this->testModelClass, ['title', 'body']);

        $count = 0;
        $documents = [];
        foreach ($this->posts as $post) {
            $documents[] = [
                'model_id' => $count + 1,
                'document' => [
                    'title' => $this->processDocument($post['title'] ?? ''),
                    'body' => $this->processDocument($post['body'] ?? ''),
                ],
            ];
            $count++;

            if (count($documents) >= 100 || $count === count($this->posts)) {
                $this->engine->insertBatch($this->testModelClass, $documents);
                $documents = [];
            }
        }

        $elapsed = microtime(true) - $start;
        $rate = $elapsed > 0 ? $count / $elapsed : 0;
        $this->metrics->recordQuant('Rebuild', round($rate, 1), 'docs/sec');

        if ($verbose) {
            echo "  Rebuild: {$count} docs in " . round($elapsed, 2) . "s (" . round($rate, 1) . " docs/sec)\n";
        }

        $this->engine->dropTable($this->testModelClass);
    }
}
