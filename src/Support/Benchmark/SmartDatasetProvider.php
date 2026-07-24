<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

use Moaines\IllumiSearch\Contracts\Engine;

/**
 * Intelligent dataset provider for benchmark and tests.
 *
 * Loads seed.json (~1364 posts), analyzes vocabulary,
 * generates varied test queries with expected results,
 * and provides ranking assertions.
 */
class SmartDatasetProvider
{
    private array $posts = [];
    private array $vocabAnalysis = [];
    private array $generatedQueries = [];

    public const SEED = 42;

    /**
     * Load dataset from seed.json.
     *
     * @return array{title: string, body: string, author: string, language: string}[]
     */
    public function loadDataset(?string $seedPath = null): array
    {
        $path = $seedPath ?? base_path('database/seed.json');
        if (! file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        $this->posts = $data['posts'] ?? [];

        return $this->posts;
    }

    /**
     * Analyze vocabulary: frequency, rarity, n-grams.
     *
     * @return array{frequent: string[], rare: string[], multiWord: string[], domainTerms: string[], allTokens: string[]}
     */
    public function analyzeVocabulary(): array
    {
        if (empty($this->posts)) {
            $this->loadDataset();
        }

        $freq = [];
        $total = 0;

        foreach ($this->posts as $post) {
            $text = mb_strtolower(($post['title'] ?? '') . ' ' . ($post['body'] ?? ''));
            $words = preg_split('/[^\p{L}\p{N}]+/u', $text);
            $unique = array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 3));
            foreach ($unique as $w) {
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
            $total++;
        }

        $maxFreq = max($freq) ?: 1;
        $frequent = [];
        $rare = [];
        foreach ($freq as $word => $count) {
            if ($count > $total * 0.3) { // appears in >30% of docs
                $frequent[] = $word;
            } elseif ($count <= max(1, $total * 0.05)) { // appears in <5%
                $rare[] = $word;
            }
        }

        // Multi-word terms from titles
        $multiWord = [];
        foreach ($this->posts as $post) {
            $title = mb_strtolower(trim($post['title'] ?? ''));
            if (str_word_count($title) >= 3 && strlen($title) > 20) {
                $multiWord[] = $title;
            }
        }

        // Domain-specific terms (software, programming)
        $domainTerms = ['software', 'programming', 'development', 'algorithm', 'database',
            'framework', 'javascript', 'python', 'engineering'];

        $this->vocabAnalysis = compact('frequent', 'rare', 'multiWord', 'domainTerms', 'freq');
        $this->vocabAnalysis['allTokens'] = array_keys($freq);

        return $this->vocabAnalysis;
    }

    /**
     * Generate varied test queries with expected results.
     *
     * @return array<int, array{query: string, type: string, mustMatch: int[], mustNotMatch: int[], idealOrder?: int[], tokens: string[]}>
     */
    public function generateQueries(int $count = 30): array
    {
        if (empty($this->vocabAnalysis)) {
            $this->analyzeVocabulary();
        }
        mt_srand(self::SEED);

        $queries = [];
        $allPosts = $this->posts;
        $vocab = $this->vocabAnalysis['freq'] ?? [];

        // 1. Simple words (frequent)
        $frequent = $this->vocabAnalysis['frequent'] ?? [];
        shuffle($frequent);
        for ($i = 0; $i < min(5, count($frequent)); $i++) {
            $word = $frequent[$i];
            $queries[] = $this->buildQuery('simple', $word, $allPosts);
        }

        // 2. Rare words
        $rare = $this->vocabAnalysis['rare'] ?? [];
        shuffle($rare);
        for ($i = 0; $i < min(5, count($rare)); $i++) {
            $word = $rare[$i];
            $queries[] = $this->buildQuery('rare', $word, $allPosts);
        }

        // 3. Domain terms
        $domain = $this->vocabAnalysis['domainTerms'] ?? [];
        shuffle($domain);
        for ($i = 0; $i < min(4, count($domain)); $i++) {
            $word = $domain[$i];
            $queries[] = $this->buildQuery('domain', $word, $allPosts);
        }

        // 4. Multi-word phrases from titles
        $multiWord = $this->vocabAnalysis['multiWord'] ?? [];
        shuffle($multiWord);
        $phraseCount = 0;
        foreach ($multiWord as $phrase) {
            if ($phraseCount >= 4) {
                break;
            }
            $shortPhrase = implode(' ', array_slice(explode(' ', $phrase), 0, 3));
            if (strlen($shortPhrase) > 8) {
                $queries[] = $this->buildQuery('phrase', $shortPhrase, $allPosts);
                $phraseCount++;
            }
        }

        // 5. AND queries (frequent + rare)
        for ($i = 0; $i < 4; $i++) {
            $f = $frequent[array_rand($frequent)] ?? 'software';
            $r = $rare[array_rand($rare)] ?? 'algorithm';
            $queries[] = $this->buildQuery('and', "$f AND $r", $allPosts);
        }

        // 6. OR queries
        for ($i = 0; $i < 3; $i++) {
            $f1 = $frequent[array_rand($frequent)] ?? 'development';
            $f2 = $domain[array_rand($domain)] ?? 'programming';
            $queries[] = $this->buildQuery('or', "$f1 OR $f2", $allPosts);
        }

        // 7. Wildcard prefixes
        foreach (['prog', 'deve', 'soft', 'algo'] as $prefix) {
            $queries[] = $this->buildQuery('wildcard', "{$prefix}*", $allPosts);
        }

        $this->generatedQueries = array_slice($queries, 0, $count);

        return $this->generatedQueries;
    }

    private function buildQuery(string $type, string $query, array $allPosts): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}\*]+/u', mb_strtolower($query));
        $cleanTokens = array_values(array_unique(array_filter($tokens, fn ($w) => mb_strlen($w) >= 2)));

        $mustMatch = [];
        $mustNotMatch = [];
        $idealOrder = [];

        foreach ($allPosts as $i => $post) {
            $text = mb_strtolower(($post['title'] ?? '') . ' ' . ($post['body'] ?? ''));
            $matches = true;
            foreach ($cleanTokens as $t) {
                if (! str_contains($text, $t)) {
                    $matches = false;
                    break;
                }
            }

            $docId = $i + 1;

            if ($matches) {
                $mustMatch[] = $docId;
                // Title match ranks higher (ideal order)
                $title = mb_strtolower($post['title'] ?? '');
                $titleMatch = true;
                foreach ($cleanTokens as $t) {
                    if (! str_contains(' ' . $title . ' ', $t)) {
                        $titleMatch = false;
                        break;
                    }
                }
                if ($titleMatch) {
                    array_unshift($idealOrder, $docId);
                } else {
                    $idealOrder[] = $docId;
                }
            } elseif (count($cleanTokens) <= 3) {
                // For simple queries, non-matching docs should NOT appear
                $partialMatch = false;
                foreach ($cleanTokens as $t) {
                    if (str_contains($text, $t)) {
                        $partialMatch = true;
                        break;
                    }
                }
                if (! $partialMatch) {
                    $mustNotMatch[] = $docId;
                }
            }
        }

        return compact('query', 'type', 'mustMatch', 'mustNotMatch', 'idealOrder', 'cleanTokens') + ['tokens' => $cleanTokens];
    }

    /**
     * Assert that an engine's search results match the expected ranking.
     */
    public function assertRanking(Engine $engine, array $queryDef, string $modelClass): array
    {
        $query = $queryDef['query'];
        $results = $engine->search($query, [$modelClass], 20);
        $resultIds = array_map(fn ($r) => $r->modelId, $results);

        $passed = [
            'query' => $query,
            'type' => $queryDef['type'],
            'returnedCount' => count($results),
            'totalRelevant' => count($queryDef['mustMatch']),
            'foundRelevant' => count(array_intersect($resultIds, $queryDef['mustMatch'])),
            'errors' => [],
        ];

        // Check mustMatch docs appear
        foreach ($queryDef['mustMatch'] as $id) {
            if (! in_array($id, $resultIds, true)) {
                $passed['errors'][] = "Doc $id should appear but missing";
            }
        }

        // Check mustNotMatch docs don't appear
        foreach (array_slice($queryDef['mustNotMatch'] ?? [], 0, 20) as $id) {
            if (in_array($id, $resultIds, true)) {
                $passed['errors'][] = "Doc $id should NOT appear but found";
            }
        }

        // Check ideal order (first n docs)
        foreach (array_slice($queryDef['idealOrder'] ?? [], 0, 5) as $rank => $expectedId) {
            if (isset($resultIds[$rank]) && $resultIds[$rank] !== $expectedId) {
                // Non-critical: order preference, not a hard error
                $passed['errors'][] = "Position " . ($rank + 1) . ": expected doc $expectedId, got {$resultIds[$rank]} (order preference)";
            }
        }

        $passed['success'] = empty($passed['errors']);

        return $passed;
    }

    public function getPosts(): array
    {
        return $this->posts;
    }

    public function getVocabAnalysis(): array
    {
        return $this->vocabAnalysis;
    }

    public function getGeneratedQueries(): array
    {
        return $this->generatedQueries;
    }
}
