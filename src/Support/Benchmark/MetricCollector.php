<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

class MetricCollector
{
    private array $quantitative = [];

    private array $quality = [];

    private array $soundness = [];

    private static function scriptsOf(string $text): array
    {
        $maps = [
            'Latin' => '\p{Latin}', 'Cyrillic' => '\p{Cyrillic}', 'Arabic' => '\p{Arabic}',
            'Han' => '\p{Han}', 'Hiragana' => '\p{Hiragana}', 'Katakana' => '\p{Katakana}',
            'Hangul' => '\p{Hangul}', 'Greek' => '\p{Greek}', 'Hebrew' => '\p{Hebrew}',
            'Devanagari' => '\p{Devanagari}', 'Thai' => '\p{Thai}', 'Armenian' => '\p{Armenian}',
            'Georgian' => '\p{Georgian}', 'Coptic' => '\p{Coptic}', 'Bengali' => '\p{Bengali}',
        ];
        $found = [];
        foreach ($maps as $name => $regex) {
            if (preg_match('/' . $regex . '/u', $text)) {
                $found[] = $name;
            }
        }
        return $found ?: ['Common'];
    }

    /**
     * Extract searchable text from a result, handling both MySQL (raw.search_text)
     * and SQLite FTS5 (individual columns).
     */
    private function extractSearchText(mixed $result): string
    {
        if ($result === null) {
            return '';
        }

        if (is_string($result) || is_numeric($result)) {
            return (string) $result;
        }

        // MySQL: raw has a single search_text column
        if (! empty($result->raw['search_text'])) {
            $text = $result->raw['search_text'];
            if (mb_strlen((string) $text) > 3) {
                return mb_strtolower((string) $text);
            }
        }

        // SQLite FTS5: concatenate all text columns from raw
        if (! empty($result->raw)) {
            $parts = [];
            foreach ($result->raw as $key => $value) {
                if (in_array($key, ['model_id', 'rank', 'total_count', 'id', 'model_type', 'search_text'], true)) {
                    continue;
                }
                if (is_string($value) && mb_strlen($value) > 2) {
                    $parts[] = $value;
                }
            }
            $text = implode(' ', $parts);
            if (mb_strlen($text) > 3) {
                return mb_strtolower($text);
            }
        }

        // Fallback: use summary or title (may not contain the search term match)
        $fallback = $result->summary ?? $result->title ?? '';

        return mb_strtolower((string) $fallback);
    }

    /**
     * Public access to extractSearchText for external consumers (BenchmarkRunner).
     */
    public function extractSearchTextForSoundness(mixed $result): string
    {
        return $this->extractSearchText($result);
    }

    public function recordQuant(string $metric, float $value, string $unit): void
    {
        $this->quantitative[$metric] = ['value' => $value, 'unit' => $unit];
    }

    public function recordQuality(string $metric, mixed $value, string $display = ''): void
    {
        $this->quality[$metric] = ['value' => $value, 'display' => $display ?: (is_bool($value) ? ($value ? '✓' : '✗') : (string) $value)];
    }

    public function recordSound(string $metric, bool $passed, string $label = ''): void
    {
        $this->soundness[$metric] = ['value' => $passed, 'display' => $label ?: ($passed ? '✓' : '✗')];
    }

    public function precisionAtK(array $results, string $expected, int $k = 5): float
    {
        if (empty($results)) {
            return 0.0;
        }

        $topK = array_slice($results, 0, $k);
        $found = 0;

        foreach ($topK as $result) {
            $searchText = $this->extractSearchText($result);

            if (mb_strlen($searchText) <= 3) {
                continue;
            }

            $expectedLow = mb_strtolower($expected);

            if (mb_strpos($searchText, $expectedLow) !== false) {
                $found++;
            }
        }

        return $found / min(count($topK), $k);
    }

    private function relevanceGain(mixed $result, string $queryLow): int
    {
        $title = mb_strtolower((string) ($result->title ?? ''));
        if (mb_strpos($title, $queryLow) !== false) {
            return 3;
        }

        $allText = '';
        if (! empty($result->raw)) {
            $parts = [];
            foreach ($result->raw as $key => $value) {
                if (in_array($key, ['model_id', 'rank', 'total_count', 'id', 'model_type', 'title'], true)) {
                    continue;
                }
                if (is_string($value) && mb_strlen($value) > 2) {
                    $parts[] = $value;
                }
            }
            $allText = implode(' ', $parts);
        }

        // Fallback: use summary if available
        if (mb_strlen($allText) <= 3 && ($result->summary ?? null) !== null) {
            $allText = $result->summary;
        }

        return mb_strpos(mb_strtolower($allText), $queryLow) !== false ? 1 : 0;
    }

    public function ndcgAtK(array $results, string $query, int $k = 5): float
    {
        if (empty($results)) {
            return 0.0;
        }

        $queryLow = mb_strtolower($query);
        $gains = [];

        foreach (array_slice($results, 0, $k) as $result) {
            $gains[] = $this->relevanceGain($result, $queryLow);
        }

        $dcg = 0.0;
        foreach ($gains as $i => $gain) {
            $dcg += $gain / log($i + 2, 2);
        }

        $ideal = $gains;
        rsort($ideal);
        $idcg = 0.0;
        foreach ($ideal as $i => $gain) {
            $idcg += $gain / log($i + 2, 2);
        }

        return $idcg > 0 ? $dcg / $idcg : 0.0;
    }

    public function averagePrecisionAtK(array $results, string $query, int $k = 5): float
    {
        if (empty($results)) {
            return 0.0;
        }

        $queryLow = mb_strtolower($query);
        $topK = array_slice($results, 0, $k);
        $relevant = 0;
        $sum = 0.0;

        foreach ($topK as $i => $result) {
            if ($this->relevanceGain($result, $queryLow) > 0) {
                $relevant++;
                $sum += $relevant / ($i + 1);
            }
        }

        return $relevant > 0 ? $sum / min($relevant, $k) : 0.0;
    }

    public function meanReciprocalRank(array $allResults, array $expectedMap): float
    {
        if (empty($expectedMap)) {
            return 0.0;
        }

        $sum = 0.0;

        foreach ($expectedMap as $query => $expected) {
            $results = $allResults[$query] ?? [];

            foreach ($results as $rank => $result) {
                $searchText = $this->extractSearchText($result);
                $expectedLow = mb_strtolower($expected);

                if (mb_strpos($searchText, $expectedLow) !== false) {
                    $sum += 1.0 / ($rank + 1);
                    break;
                }
            }
        }

        return $sum / max(1, count($expectedMap));
    }

    public function meanReciprocalRankSuggest(array $suggestResults, array $expectedMap): float
    {
        if (empty($expectedMap)) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($expectedMap as $query => $expected) {
            $suggestions = $suggestResults[$query] ?? [];
            foreach ($suggestions as $rank => $word) {
                if (mb_strtolower((string) $word) === mb_strtolower($expected)) {
                    $sum += 1.0 / ($rank + 1);
                    break;
                }
            }
        }

        return $sum / max(1, count($expectedMap));
    }

    /**
     * Precision@5 for suggest: how many of the top 5 suggestions are valid.
     * A suggestion is valid if its Levenshtein distance from the query is ≤ maxDistance.
     */
    public function suggestPrecisionAtK(array $suggestions, string $query, int $maxDistance = 2, int $k = 5): float
    {
        $topK = array_slice($suggestions, 0, $k);

        if (empty($topK)) {
            return 0.0;
        }

        $valid = 0;
        foreach ($topK as $word) {
            $dist = levenshtein($query, (string) $word);
            if ($dist !== -1 && $dist <= $maxDistance) {
                $valid++;
            }
        }

        return $valid / count($topK);
    }

    public function suggestScriptAwarePrecisionAtK(array $suggestions, string $query, int $maxDistance = 2, int $k = 5): float
    {
        $topK = array_slice($suggestions, 0, $k);

        if (empty($topK)) {
            return 0.0;
        }

        $queryScripts = self::scriptsOf($query);
        $weightSum = 0.0;

        foreach ($topK as $word) {
            $dist = levenshtein($query, (string) $word);

            if ($dist === -1 || $dist > $maxDistance) {
                continue;
            }

            $wordScripts = self::scriptsOf((string) $word);
            $sameScript = ! empty(array_intersect($queryScripts, $wordScripts));

            $score = ($maxDistance - $dist) / $maxDistance + ($sameScript ? 0.2 : 0);
            $weightSum += min(1.0, $score);
        }

        return $weightSum / count($topK);
    }

    /**
     * Top-1 accuracy for suggest: is the first suggestion the correct word?
     */
    public function suggestTop1Accuracy(array $suggestions, string $expected): bool
    {
        $first = $suggestions[0] ?? '';

        return mb_strtolower((string) $first) === mb_strtolower($expected);
    }

    /**
     * Coverage: % of queries with at least one suggestion (any).
     */
    public function suggestCoverageAny(array $suggestResults, array $queries): float
    {
        $found = 0;
        foreach ($queries as $q) {
            $results = $suggestResults[$q] ?? [];
            if (! empty($results)) {
                $found++;
            }
        }
        return $found / max(1, count($queries));
    }

    /**
     * Coverage: % of queries with at least one suggestion within Levenshtein ≤ 2.
     */
    public function suggestCoverageCorrect(array $suggestResults, array $queries, int $maxDistance = 2): float
    {
        $found = 0;
        foreach ($queries as $q) {
            $results = $suggestResults[$q] ?? [];
            foreach ($results as $word) {
                $dist = levenshtein($q, (string) $word);
                if ($dist !== -1 && $dist <= $maxDistance) {
                    $found++;
                    break;
                }
            }
        }
        return $found / max(1, count($queries));
    }

    /**
     * Coverage: % of queries where the expected word is in the suggestions.
     */
    public function suggestCoverageExpected(array $suggestResults, array $expectedMap): float
    {
        $found = 0;
        $total = 0;
        foreach ($expectedMap as $query => $expected) {
            $results = $suggestResults[$query] ?? [];
            if (in_array($expected, $results, true)) {
                $found++;
            }
            $total++;
        }
        return $total > 0 ? $found / $total : 0.0;
    }

    public function fuzzyTolerance(array $allResults, array $typoQueries): bool
    {
        foreach ($typoQueries as $item) {
            $key = is_array($item) ? ($item['query'] ?? '') : (string) $item;
            $results = $allResults[$key] ?? [];

            if (empty($results)) {
                return false;
            }

            $needle = is_array($item) ? ($item['expected'] ?? '') : '';
            if ($needle === '') {
                continue;
            }

                $found = false;
            foreach ($results as $result) {
                $searchText = $this->extractSearchText($result);

                $needleLow = mb_strtolower($needle);
                $needleNorm = normalizer_normalize($needleLow, \Normalizer::FORM_KD);
                $needleNorm = $needleNorm ? preg_replace('/\p{Mn}/u', '', $needleNorm) : $needleLow;

                if (mb_strpos($searchText, $needleNorm) !== false) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    public function accentInsensitivity(array $allResults, array $accentTests): bool
    {
        foreach ($accentTests as $original => $ascii) {
            $results = $allResults[$ascii] ?? [];

            if (empty($results)) {
                return false;
            }

            $found = false;
            foreach ($results as $result) {
                $searchText = $this->extractSearchText($result);

                $cleanNeedle = normalizer_normalize(mb_strtolower($original), \Normalizer::FORM_KD);
                $cleanNeedle = $cleanNeedle ? preg_replace('/\p{Mn}/u', '', $cleanNeedle) : mb_strtolower($original);

                if (mb_strpos($searchText, $cleanNeedle) !== false) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    public function scriptIsolation(array $latinSuggest, array $suggestions): bool
    {
        $first = $suggestions[0] ?? '';

        if ($first === '') {
            return false;
        }

        return true;
    }

    public function emptyResultsRate(array $allResults, array $exactQueries): float
    {
        $empty = 0;

        foreach ($exactQueries as $q) {
            if (empty($allResults[$q] ?? [])) {
                $empty++;
            }
        }

        return $empty / max(1, count($exactQueries));
    }

    public function getQuantitative(): array
    {
        return $this->quantitative;
    }

    public function getQuality(): array
    {
        return $this->quality;
    }

    public function getSoundness(): array
    {
        return $this->soundness;
    }

    public function getAll(): array
    {
        return [
            'quantity' => $this->quantitative,
            'quality' => $this->quality,
            'soundness' => $this->soundness,
        ];
    }
}
