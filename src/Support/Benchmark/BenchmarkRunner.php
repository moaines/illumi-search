<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;

class BenchmarkRunner
{
    private array $posts = [];
    private array $searchQueries = [];
    private string $testModelClass = 'App\Models\BenchmarkPost';
    private MetricCollector $metrics;
    private array $searchResults = [];
    private array $suggestResults = [];
    private string $mode = 'processed';
    private array $existenceIndex = [];
    private int $totalRelevant = 0;

    /** @var array<string, int> Ideal relevance map docId → relevance (0–3) */
    private array $relevanceMap = [];

    /** @var array<string, array<int>> Ideal ranking per query */
    private array $idealRanking = [];

    public function __construct(
        private readonly Engine $engine,
        private readonly string $seedPath,
    ) {
        $this->metrics = new MetricCollector;
    }

    public function metrics(): MetricCollector
    {
        return $this->metrics;
    }

    private function processDocument(string $text): string
    {
        if ($this->mode === 'raw') {
            return $text;
        }

        return app(TextProcessor::class)->process($text);
    }

    public function run(int $totalDocs, bool $verbose = false, string $mode = 'processed', int $seed = 42): array
    {
        $this->mode = $mode;
        mt_srand($seed);

        if ($mode === 'raw') {
            app()->instance(TextProcessor::class, new IdentityProcessor);
            config(['illumi-search.engines.sqlite.fts5.tokenizer' => 'porter unicode61 remove_diacritics 2']);
            config(['illumi-search.processing.processor' => 'unicode']);
        }

        // Clear FileEngine cache for cold measurements
        if (method_exists($this->engine, 'searchCache')) {
            $ref = new \ReflectionClass($this->engine);
            if ($ref->hasMethod('cacheClear')) {
                $ref->getMethod('cacheClear')->invoke($this->engine, null);
            }
        }

        $generator = new DataGenerator;
        $this->posts = $generator->generate($totalDocs, $this->seedPath);
        $this->searchQueries = $generator->buildSearchQueries($this->posts, 20);
        $this->buildExistenceIndex($this->posts);
        $this->metrics->recordPeakBefore();

        if ($verbose) {
            echo "  Dataset: " . count($this->posts) . " documents\n";
            echo "  Queries: " . count($this->searchQueries['exact']) . " exact, "
                . count($this->searchQueries['typo']) . " typo\n";
        }

        $this->benchUpsert($verbose);
        $this->benchSearch($verbose);
        $this->benchSuggest($verbose);

        $controlledIds = $this->insertControlledDocuments();
        $this->benchOperators($verbose, $controlledIds);

        // Re-search for MRR/NDCG queries that need the controlled docs
        foreach (array_keys($this->idealRanking) as $qr) {
            $this->searchResults[$qr] = $this->engine->search($qr, [$this->testModelClass], 10);
        }

        if ($mode === 'raw') {
            $this->benchRawModeTests($verbose);
        }
        $this->benchRebuild($verbose);

        $this->metrics->recordPeakMemory();

        // Build quality metrics with controlled dataset
        $this->computeQualityMetrics();

        return $this->metrics->getAll();
    }

    private function buildExistenceIndex(array $posts): void
    {
        $tokens = [];
        foreach ($posts as $post) {
            $text = ($post['title'] ?? '') . ' ' . ($post['body'] ?? '');
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) as $w) {
                if (mb_strlen(trim($w)) >= 2) {
                    $tokens[trim($w)] = true;
                }
            }
        }
        $this->existenceIndex = array_keys($tokens);
    }

    private function insertControlledDocuments(): array
    {
        // 10 controlled docs with known ranking expectations
        // First 5: unique-prefixed docs for operator tests
        $controlledDocs = [
            ['title' => 'zzyxxphp laravel framework guide', 'body' => 'learn zzyxxphp and laravel for web'],
            ['title' => 'zzyxxphp symfony framework guide', 'body' => 'learn zzyxxphp and symfony for web'],
            ['title' => 'zzyxxpython django framework', 'body' => 'learn zzyxxpython and django for web'],
            ['title' => 'zzyxxjavascript react frontend', 'body' => 'learn zzyxxjavascript and react for web'],
            ['title' => 'zzyxxcustom software engineering', 'body' => 'zzyxxcustom software engineering best practices design patterns'],
            // Next 5: ranking-controlled docs with natural language
            ['title' => 'php programming language for web', 'body' => 'php is a popular scripting language for web development'],
            ['title' => 'learning php programming basics', 'body' => 'php programming for beginners and advanced developers'],
            ['title' => 'web development with php and laravel', 'body' => 'laravel is a php framework for modern web applications'],
            ['title' => 'javascript for frontend web development', 'body' => 'javascript is essential for interactive web pages'],
            ['title' => 'advanced javascript programming guide', 'body' => 'javascript patterns and best practices for professionals'],
            ['title' => 'python data science machine learning', 'body' => 'python with pandas numpy and scikit learn for data science'],
            ['title' => 'data science with python and r', 'body' => 'python and r programming for statistical analysis'],
            ['title' => 'zzzperfectmatch', 'body' => 'this document exactly matches the query zzzperfectmatch'],
            ['title' => 'zzzperfectmatch', 'body' => 'another document about zzzperfectmatch topic'],
            ['title' => 'zzzweighttest weightthree title', 'body' => 'weightone body text with match term'],
        ];

        $relevanceMap = [];
        $idealRanking = [];
        $controlledIds = [];

        foreach ($controlledDocs as $i => $doc) {
            $docId = 999000 + $i;
            $this->engine->upsert($this->testModelClass, $docId, $doc);
            $controlledIds[] = $docId;
        }

        // Relevance map for WeightedNDCG (docId → 0-3)
        // Docs 13-14: zzzperfectmatch → relevance 3 for query "zzzperfectmatch"
        $relevanceMap[999012] = 3;
        $relevanceMap[999013] = 2;

        // Docs 6-8: php-related → relevance 3 for "php"
        $relevanceMap[999005] = 3;
        $relevanceMap[999006] = 3;
        $relevanceMap[999007] = 3;
        // Docs 9-10: javascript → relevance 2-3
        $relevanceMap[999008] = 2;
        $relevanceMap[999009] = 3;
        // Doc 15: weight test
        $relevanceMap[999014] = 3;

        $this->relevanceMap = $relevanceMap;

        // Ideal rankings for specific queries
        $this->idealRanking = [
            'php' => [999000, 999001, 999002],
            'javascript' => [999004, 999003],
            'zzzperfectmatch' => [999007, 999008],
        ];

        return $controlledIds;
    }

    private function computeQualityMetrics(): void
    {
        $exactQueries = $this->searchQueries['exact'];

        // Count total relevant docs per query for Recall
        foreach ($exactQueries as $q) {
            $tokens = $this->metrics->tokenize($q);
            $count = 0;
            foreach ($this->posts as $post) {
                $text = mb_strtolower(($post['title'] ?? '') . ' ' . ($post['body'] ?? ''));
                if ($this->metrics->tokensMatch($text, $tokens)) {
                    $count++;
                }
            }
            $this->totalRelevant = max($this->totalRelevant, $count);
        }

        $precisionSum = 0;
        $recallSum = 0;
        $f1Sum = 0;
        $ndcgSum = 0;
        $mapSum = 0;
        $prec1Sum = 0;
        $count = 0;

        // Build a comprehensive relevance map from all docs
        $fullRelevanceMap = $this->relevanceMap;
        foreach ($exactQueries as $q) {
            $tokens = $this->metrics->tokenize($q);
            foreach ($this->posts as $i => $post) {
                $docId = $i + 1;
                $text = mb_strtolower(($post['title'] ?? '') . ' ' . ($post['body'] ?? ''));
                if (! $this->metrics->tokensMatch($text, $tokens)) {
                    continue;
                }
                $title = mb_strtolower($post['title'] ?? '');
                $titleMatch = true;
                foreach ($tokens as $t) {
                    if (! str_contains($title, $t)) {
                        $titleMatch = false;
                        break;
                    }
                }
                $fullRelevanceMap[$docId] = max($fullRelevanceMap[$docId] ?? 0, $titleMatch ? 3 : 1);
            }
        }

        foreach ($exactQueries as $q) {
            $results = $this->searchResults[$q] ?? [];
            $tokens = $this->metrics->tokenize($q);
            $totalRel = 0;
            foreach ($this->posts as $post) {
                $text = mb_strtolower(($post['title'] ?? '') . ' ' . ($post['body'] ?? ''));
                if ($this->metrics->tokensMatch($text, $tokens)) {
                    $totalRel++;
                }
            }

            $p5 = $this->metrics->precisionAtK($results, $q, 5);
            $r5 = $this->metrics->recallAtK($results, $q, 5, max(1, $totalRel));
            $f1 = $this->metrics->f1AtK($p5, $r5);
            $map = $this->metrics->averagePrecisionAtK($results, $q, 5);
            $p1 = $this->metrics->precisionAt1($results, $q);
            $ndcg = $this->metrics->weightedNDCG($results, $q, 5, $fullRelevanceMap);

            $precisionSum += $p5;
            $recallSum += $r5;
            $f1Sum += $f1;
            $mapSum += $map;
            $prec1Sum += $p1;
            $ndcgSum += $ndcg;
            $count++;
        }

        $count = max(1, $count);
        $mrrExpected = [];
        foreach ($this->idealRanking as $query => $ids) {
            $mrrExpected[$query] = $query;
        }

        $this->metrics->recordQuality('Precision@5', round($precisionSum / $count, 2));
        $this->metrics->recordQuality('Recall@5', round($recallSum / $count, 2));
        $this->metrics->recordQuality('F1@5', round($f1Sum / $count, 2));
        $this->metrics->recordQuality('NDCG@5', round($ndcgSum / $count, 2));
        $this->metrics->recordQuality('MAP@5', round($mapSum / $count, 2));
        $this->metrics->recordQuality('Precision@1', round($prec1Sum / $count, 2));
        $mrrExpected = [];
        foreach ($this->idealRanking as $query => $ids) {
            $mrrExpected[$query] = $query;
        }
        $this->metrics->recordQuality('MRR', round($this->metrics->meanReciprocalRank($this->searchResults, $mrrExpected), 2));
        $this->metrics->recordQuality('Avg first relevant', round($this->metrics->avgFirstRelevantPos($this->searchResults, $exactQueries), 1) . 'th');
    }

    // ─── Existing methods (benchUpsert, benchSearch, benchSuggest, etc.) ──

    private function benchUpsert(bool $verbose): void
    {
        $this->engine->createTable($this->testModelClass, ['title', 'body']);
        $half = max(1, (int) (count($this->posts) / 2));

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
            echo "  Upsert (fast): {$half} docs in " . round($elapsed, 2) . "s\n";
        }

        $remaining = count($this->posts) - $half;
        if ($remaining > 0) {
            $batch = [];
            for ($i = $half; $i < count($this->posts); $i++) {
                $post = $this->posts[$i];
                $batch[] = ['model_id' => $i + 1, 'document' => [
                    'title' => $this->processDocument($post['title'] ?? ''),
                    'body' => $this->processDocument($post['body'] ?? ''),
                ]];
            }
            $start = microtime(true);
            $this->engine->insertBatch($this->testModelClass, $batch);
            $elapsed = microtime(true) - $start;
            $rate = $elapsed > 0 ? $remaining / $elapsed : 0;
            $this->metrics->recordQuant('Upsert (with vocab)', round($rate, 1), 'docs/sec');
            if ($verbose) {
                echo "  Upsert (with vocab): {$remaining} docs in " . round($elapsed, 2) . "s\n";
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

        if (! empty($exactQueries)) {
            $start = microtime(true);
            foreach ($exactQueries as $q) {
                $qs = microtime(true);
                $this->searchResults[$q] = $this->engine->search($q, [$this->testModelClass], 10);
                $this->metrics->recordLatency((microtime(true) - $qs) * 1000);
            }
            $elapsed = microtime(true) - $start;
            $rate = $elapsed > 0 ? count($exactQueries) / $elapsed : 0;
            $this->metrics->recordQuant('Search (exact)', round($rate, 1), 'q/sec');
        }

        if (! empty($typoQueries)) {
            $typoResults = [];
            foreach ($typoQueries as $item) {
                $typo = is_array($item) ? ($item['query'] ?? '') : '';
                $expected = is_array($item) ? ($item['expected'] ?? '') : '';
                if ($typo && $expected) {
                    $typoResults[$typo] = $this->engine->suggest($typo, 2, 5);
                }
            }

            $allFuzzyOk = ! empty($typoResults);
            foreach ($typoResults as $typo => $suggestions) {
                if (empty($suggestions)) {
                    $allFuzzyOk = false;
                    break;
                }
            }
            $this->metrics->recordQuality('Fuzzy tolerance', $allFuzzyOk);
        }

        if (! empty($accentTests)) {
            $accentResults = [];
            foreach ($accentTests as $original => $ascii) {
                $accentResults[$ascii] = $this->engine->search($ascii, [$this->testModelClass], 10);
            }
            $accentOk = count($accentResults) === count($accentTests)
                && $this->metrics->accentInsensitivity($accentResults, $accentTests);
            $this->metrics->recordQuality('Accent insensitivity', $accentOk);
        }

        $emptyRate = $this->metrics->emptyResultsRate($this->searchResults, $exactQueries, $this->existenceIndex);
        $this->metrics->recordQuality('Empty results rate', round($emptyRate * 100, 1) . '%');

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
            echo "  Search: " . count($exactQueries) . " exact queries done\n";
        }
    }

    private function benchSuggest(bool $verbose): void
    {
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
        $cyrillicQueries = ['правил', 'превед', 'разраб'];
        $allSuggest = array_merge($latinQueries, $cyrillicQueries);

        $start = microtime(true);
        foreach ($allSuggest as $q) {
            $this->suggestResults[$q] = $this->engine->suggest($q, 2, 5);
        }
        $elapsed = microtime(true) - $start;
        $rate = $elapsed > 0 ? count($allSuggest) / $elapsed : 0;
        $this->metrics->recordQuant('Suggest', round($rate, 1), 'q/sec');

        $validToyoExpected = array_filter($typoExpected,
            fn ($e) => in_array(mb_strtolower($e), $existingTerms, true));
        $validLatinQueries = array_keys($validToyoExpected);

        $sp = 0;
        foreach ($validLatinQueries as $q) {
            $sp += $this->metrics->suggestPrecisionAtK($this->suggestResults[$q] ?? [], $q, 2, 5);
        }
        $this->metrics->recordQuality('Suggest Prec@5', $validLatinQueries ? round($sp / count($validLatinQueries), 2) : 0);

        $t1 = 0;
        foreach ($validToyoExpected as $typo => $expected) {
            if ($this->metrics->suggestTop1Accuracy($this->suggestResults[$typo] ?? [], $expected)) {
                $t1++;
            }
        }
        $this->metrics->recordQuality('Suggest Top-1', round($t1 / max(1, count($typoExpected)), 2));

        $ssp = 0;
        foreach ($validLatinQueries as $q) {
            $ssp += $this->metrics->suggestScriptAwarePrecisionAtK($this->suggestResults[$q] ?? [], $q, 2, 5);
        }
        $this->metrics->recordQuality('Suggest Prec@5 (script)', $validLatinQueries ? round($ssp / count($validLatinQueries), 2) : 0);

        $cover = $this->metrics->suggestCoverageAny($this->suggestResults, $validLatinQueries);
        $this->metrics->recordQuality('Suggest coverage', round($cover, 2));

        $coverCorr = $this->metrics->suggestCoverageCorrect($this->suggestResults, $validLatinQueries, 2);
        $this->metrics->recordQuality('Suggest coverage (correct)', round($coverCorr, 2));

        $hasCyrillicFirst = false;
        foreach ($latinQueries as $q) {
            $words = $this->suggestResults[$q] ?? [];
            if (! empty($words) && preg_match('/\p{Cyrillic}/u', $words[0] ?? '')) {
                $hasCyrillicFirst = true;
                break;
            }
        }
        $this->metrics->recordQuality('Script isolation', ! $hasCyrillicFirst);

        if ($verbose) {
            echo "  Suggest: " . count($allSuggest) . " queries done\n";
        }
    }

    private function benchOperators(bool $verbose, array $controlledIds): void
    {
        // AND
        $andResults = $this->engine->search('zzyxxphp AND framework', [$this->testModelClass], 10);
        $andWorks = true;
        foreach ($andResults as $r) {
            if (! $this->textHasAllTokens($this->metrics->extractSearchText($r), ['zzyxxphp', 'framework'])) {
                $andWorks = false;
                break;
            }
        }
        $this->metrics->recordSound('AND operator narrows', $andWorks,
            $andWorks ? 'All results contain both terms' : 'Some results missing a term');

        // OR
        $orResults = $this->engine->search('zzyxxphp OR zzyxxpython', [$this->testModelClass], 10);
        $this->metrics->recordSound('OR operator broadens', ! empty($orResults),
            'Returned ' . count($orResults) . ' results');

        // NOT
        $notResults = $this->engine->search('zzyxxphp NOT laravel', [$this->testModelClass], 10);
        $notWorks = true;
        foreach ($notResults as $r) {
            if (mb_strpos($this->metrics->extractSearchText($r), 'laravel') !== false) {
                $notWorks = false;
                break;
            }
        }
        $this->metrics->recordSound('NOT operator excludes', $notWorks);

        // Phrase
        $phraseResults = $this->engine->search('"zzyxxcustom software engineering"', [$this->testModelClass], 10);
        $phraseFound = false;
        foreach ($phraseResults as $r) {
            if (mb_strpos($this->metrics->extractSearchText($r), 'zzyxxcustom software engineering') !== false) {
                $phraseFound = true;
                break;
            }
        }
        $this->metrics->recordSound('Phrase exacte', $phraseFound);

        // Empty
        $emptyResults = $this->engine->search('', [$this->testModelClass], 10);
        $this->metrics->recordSound('Empty query returns empty', empty($emptyResults));

        // Special chars
        $specialOk = true;
        try {
            $this->engine->search('!@#$%^&*()', [$this->testModelClass], 10);
        } catch (\Exception) {
            $specialOk = false;
        }
        $this->metrics->recordSound('Special chars no error', $specialOk);

        // Order stability
        $orderStable = true;
        $orderQueries = ['zzyxxphp', 'framework', 'guide'];
        foreach ($orderQueries as $oq) {
            $firstRun = $this->engine->search($oq, [$this->testModelClass], 5);
            if (count($firstRun) < 2) {
                continue;
            }
            $firstOrder = array_map(fn ($r) => $r->modelId, $firstRun);
            for ($i = 0; $i < 2; $i++) {
                $rerun = $this->engine->search($oq, [$this->testModelClass], 5);
                if (array_map(fn ($r) => $r->modelId, $rerun) !== $firstOrder) {
                    $orderStable = false;
                    break 2;
                }
            }
            break;
        }
        $this->metrics->recordSound('Order stability', $orderStable);

        // ─── New soundness: Weight column scoring ──────────
        // Search for the unique term 'weightthree' which exists in controlled doc title
        $weightResults = $this->engine->search('weightthree', [$this->testModelClass], 10);
        $weightSoundness = ! empty($weightResults);
        $this->metrics->recordSound('Weight-3 column search', $weightSoundness);

        // wildcard (only for engines that support it)
        if ($this->engine->supportsPrefixWildcard()) {
            $wildcardResults = $this->engine->search('prog*', [$this->testModelClass], 10);
            $wildcardOk = false;
            foreach ($wildcardResults as $r) {
                $t = $this->metrics->extractSearchText($r);
                if (mb_strpos($t, 'programming') !== false || mb_strpos($t, 'prog') !== false) {
                    $wildcardOk = true;
                    break;
                }
            }
            $this->metrics->recordSound('Prefix wildcard (prog*)', $wildcardOk);
        }

        if ($verbose) {
            echo "  Soundness: AND=" . ($andWorks ? '✓' : '✗')
                . " OR=" . (! empty($orResults) ? '✓' : '✗')
                . " NOT=" . ($notWorks ? '✓' : '✗')
                . " phrase=" . ($phraseFound ? '✓' : '✗') . "\n";
        }
    }

    private function textHasAllTokens(string $text, array $tokens): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text));
        $unique = array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 2));

        return empty(array_diff($tokens, $unique));
    }

    private function benchRawModeTests(bool $verbose): void
    {
        $uniqueStem = 'xylophonezephyrquantum';
        $stemDocId = 999999;
        $this->engine->upsert($this->testModelClass, $stemDocId, [
            'title' => $uniqueStem . ' development',
            'body' => $uniqueStem . ' development activities',
        ]);
        $stemResults = $this->engine->search('developing', [$this->testModelClass], 1000);
        $stemOk = collect($stemResults)->pluck('modelId')->contains($stemDocId);
        $this->metrics->recordSound('Stemming (developing→development)', $stemOk);
        $this->engine->delete($this->testModelClass, $stemDocId);
        if ($verbose) {
            echo "  Raw tests: stem=" . ($stemOk ? '✓' : '✗') . "\n";
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

        $documents = [];
        $count = 0;
        foreach ($this->posts as $post) {
            $documents[] = ['model_id' => $count + 1, 'document' => [
                'title' => $this->processDocument($post['title'] ?? ''),
                'body' => $this->processDocument($post['body'] ?? ''),
            ]];
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
            echo "  Rebuild: {$count} docs in " . round($elapsed, 2) . "s\n";
        }
        $this->engine->dropTable($this->testModelClass);
    }
}
