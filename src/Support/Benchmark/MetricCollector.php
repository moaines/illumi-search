<?php

namespace Moaines\IllumiSearch\Support\Benchmark;

use Moaines\IllumiSearch\Text\HasTextHelpers;

class MetricCollector
{
    private array $quantitative = [];
    private array $quality = [];
    private array $soundness = [];

    /** @var float[] Individual query latencies in ms */
    private array $latencies = [];

    private float $peakMemoryBefore = 0;
    private float $peakMemoryAfter = 0;

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

    public function extractSearchText(mixed $result): string
    {
        if ($result === null) {
            return '';
        }
        if (is_string($result) || is_numeric($result)) {
            return (string) $result;
        }

        $raw = $result->raw ?? [];

        // Engine-computed concatenation (search_text is preferred)
        foreach (['search_text', 'search_title'] as $col) {
            if (! empty($raw[$col])) {
                return mb_strtolower((string) $raw[$col]);
            }
        }

        // Weight columns must be concatenated (a term may be in any column)
        $weightCols = collect(['text_w1', 'text_w2', 'text_w3'])
            ->filter(fn ($c) => ! empty($raw[$c]))
            ->map(fn ($c) => $raw[$c])
            ->implode(' ');
        if ($weightCols !== '') {
            return mb_strtolower($weightCols);
        }

        // Named columns — concatenate all (a term may be in any column, e.g. FTS5)
        $namedCols = collect(['title', 'body', 'content'])
            ->filter(fn ($c) => ! empty($raw[$c]))
            ->map(fn ($c) => $raw[$c])
            ->implode(' ');
        if ($namedCols !== '') {
            return mb_strtolower($namedCols);
        }

        return mb_strtolower((string) ($result->summary ?? $result->title ?? ''));
    }

    public function tokenize(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text));

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 2)));
    }

    public function tokensMatch(string $docText, array $queryTokens): bool
    {
        $docTokens = $this->tokenize($docText);

        return empty(array_diff($queryTokens, $docTokens));
    }

    // ─── Recording ──────────────────────────────────────

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

    public function recordLatency(float $ms): void
    {
        $this->latencies[] = $ms;
    }

    public function recordPeakMemory(): void
    {
        $this->peakMemoryAfter = memory_get_peak_usage(true);
    }

    public function recordPeakBefore(): void
    {
        $this->peakMemoryBefore = memory_get_peak_usage(true);
    }

    // ─── Quality: Precision@K ───────────────────────────

    public function precisionAtK(array $results, string $query, int $k = 5): float
    {
        if (empty($results)) {
            return 0.0;
        }
        $queryTokens = $this->tokenize($query);
        if (empty($queryTokens)) {
            return 0.0;
        }

        $topK = array_slice($results, 0, $k);
        $found = 0;
        foreach ($topK as $r) {
            if ($this->tokensMatch($this->extractSearchText($r), $queryTokens)) {
                $found++;
            }
        }

        return $found / min(count($topK), $k);
    }

    public function precisionAt1(array $results, string $query): float
    {
        return $this->precisionAtK($results, $query, 1);
    }

    // ─── Quality: Recall@K ──────────────────────────────

    public function recallAtK(array $results, string $query, int $k, int $totalRelevant): float
    {
        if ($totalRelevant <= 0) {
            return 0.0;
        }
        $queryTokens = $this->tokenize($query);
        if (empty($queryTokens)) {
            return 0.0;
        }

        $topK = array_slice($results, 0, $k);
        $found = 0;
        foreach ($topK as $r) {
            if ($this->tokensMatch($this->extractSearchText($r), $queryTokens)) {
                $found++;
            }
        }

        return $found / $totalRelevant;
    }

    // ─── Quality: F1@K ──────────────────────────────────

    public function f1AtK(float $precision, float $recall): float
    {
        return ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
    }

    // ─── Quality: NDCG with weighted relevance (0–3) ────

    /**
     * @param  array<int, int>  $relevanceMap  docId → relevance (0–3)
     */
    public function weightedNDCG(array $results, string $query, int $k, array $relevanceMap): float
    {
        $topK = array_slice($results, 0, $k);
        if (empty($topK)) {
            return 0.0;
        }

        $gains = [];
        foreach ($topK as $r) {
            $gains[] = $relevanceMap[$r->modelId] ?? 0;
        }

        $dcg = 0.0;
        foreach ($gains as $i => $g) {
            $dcg += $g / log($i + 2, 2);
        }

        rsort($gains);
        $idcg = 0.0;
        foreach ($gains as $i => $g) {
            $idcg += $g / log($i + 2, 2);
        }

        return $idcg > 0 ? $dcg / $idcg : 0.0;
    }

    // ─── Quality: Avg first relevant position ────────────

    public function avgFirstRelevantPos(array $allResults, array $exactQueries): float
    {
        $positions = [];
        foreach ($exactQueries as $q) {
            $results = $allResults[$q] ?? [];
            $queryTokens = $this->tokenize($q);
            if (empty($queryTokens)) {
                continue;
            }

            foreach ($results as $pos => $r) {
                if ($this->tokensMatch($this->extractSearchText($r), $queryTokens)) {
                    $positions[] = $pos + 1; // 1-indexed
                    break;
                }
            }
        }

        return ! empty($positions) ? array_sum($positions) / count($positions) : 0.0;
    }

    // ─── Quality: MAP@K ──────────────────────────────────

    public function averagePrecisionAtK(array $results, string $query, int $k = 5): float
    {
        if (empty($results)) {
            return 0.0;
        }
        $queryTokens = $this->tokenize($query);
        if (empty($queryTokens)) {
            return 0.0;
        }

        $topK = array_slice($results, 0, $k);
        $relevant = 0;
        $sum = 0.0;
        foreach ($topK as $i => $r) {
            if ($this->tokensMatch($this->extractSearchText($r), $queryTokens)) {
                $relevant++;
                $sum += $relevant / ($i + 1);
            }
        }

        return $relevant > 0 ? $sum / min($relevant, $k) : 0.0;
    }

    // ─── Quality: MRR (fixed — uses injected perfect-match docs) ──

    public function meanReciprocalRank(array $allResults, array $expectedMap): float
    {
        $sum = 0.0;
        foreach ($expectedMap as $query => $expectedTerm) {
            if (! is_string($expectedTerm)) {
                continue;
            }
            $results = $allResults[$query] ?? [];
            $queryTokens = $this->tokenize($expectedTerm);
            if (empty($queryTokens)) {
                continue;
            }

            foreach ($results as $rank => $r) {
                if ($this->tokensMatch($this->extractSearchText($r), $queryTokens)) {
                    $sum += 1.0 / ($rank + 1);
                    break;
                }
            }
        }

        return $sum / max(1, count($expectedMap));
    }

    // ─── Suggest metrics (full set) ────────────────────

    public function suggestPrecisionAtK(array $suggestions, string $query, int $maxDistance = 2, int $k = 5): float
    {
        $topK = array_slice($suggestions, 0, $k);
        if (empty($topK)) {
            return 0.0;
        }
        $valid = 0;
        foreach ($topK as $word) {
            $d = HasTextHelpers::levenshteinDistance($query, (string) $word);
            if ($d !== -1 && $d <= $maxDistance) {
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
        $qScripts = self::scriptsOf($query);
        $wSum = 0.0;
        foreach ($topK as $word) {
            $d = HasTextHelpers::levenshteinDistance($query, (string) $word);
            if ($d === -1 || $d > $maxDistance) {
                continue;
            }
            $same = ! empty(array_intersect($qScripts, self::scriptsOf((string) $word)));
            $wSum += min(1.0, ($maxDistance - $d) / $maxDistance + ($same ? 0.2 : 0));
        }

        return $wSum / count($topK);
    }

    public function suggestTop1Accuracy(array $suggestions, string $expected): bool
    {
        return mb_strtolower((string) ($suggestions[0] ?? '')) === mb_strtolower($expected);
    }

    public function suggestCoverageAny(array $suggestResults, array $queries): float
    {
        $found = 0;
        foreach ($queries as $q) {
            if (! empty($suggestResults[$q] ?? [])) {
                $found++;
            }
        }

        return $found / max(1, count($queries));
    }

    public function suggestCoverageCorrect(array $suggestResults, array $queries, int $maxDistance = 2): float
    {
        $found = 0;
        foreach ($queries as $q) {
            foreach ($suggestResults[$q] ?? [] as $word) {
                if (HasTextHelpers::levenshteinDistance($q, (string) $word) <= $maxDistance) {
                    $found++;
                    break;
                }
            }
        }

        return $found / max(1, count($queries));
    }

    public function suggestCoverageExpected(array $suggestResults, array $expectedMap): float
    {
        $found = 0;
        $total = 0;
        foreach ($expectedMap as $query => $expected) {
            if (in_array($expected, $suggestResults[$query] ?? [], true)) {
                $found++;
            }
            $total++;
        }

        return $total > 0 ? $found / $total : 0.0;
    }

    public function meanReciprocalRankSuggest(array $suggestResults, array $expectedMap): float
    {
        $sum = 0.0;
        foreach ($expectedMap as $query => $expected) {
            foreach ($suggestResults[$query] ?? [] as $rank => $word) {
                if (mb_strtolower((string) $word) === mb_strtolower($expected)) {
                    $sum += 1.0 / ($rank + 1);
                    break;
                }
            }
        }

        return $sum / max(1, count($expectedMap));
    }

    // ─── Latency percentiles ─────────────────────────────

    public function computeLatencyPercentiles(): array
    {
        $l = $this->latencies;
        if (empty($l)) {
            return ['p50' => 0, 'p95' => 0, 'p99' => 0];
        }
        sort($l);
        $n = count($l);

        return [
            'p50' => $l[(int) ceil($n * 0.50) - 1] ?? 0,
            'p95' => $l[(int) ceil($n * 0.95) - 1] ?? 0,
            'p99' => $l[(int) ceil($n * 0.99) - 1] ?? 0,
        ];
    }

    public function getPeakMemoryMB(): float
    {
        return round(($this->peakMemoryAfter - $this->peakMemoryBefore) / 1048576, 1);
    }

    // ─── Empty results rate ──────────────────────────────

    public function emptyResultsRate(array $allResults, array $exactQueries, array $existenceIndex = []): float
    {
        $total = 0;
        $empty = 0;
        foreach ($exactQueries as $q) {
            $tokens = $this->tokenize($q);
            if (! empty($existenceIndex)) {
                $anyExists = false;
                foreach ($tokens as $t) {
                    if (in_array($t, $existenceIndex, true)) {
                        $anyExists = true;
                        break;
                    }
                }
                if (! $anyExists) {
                    continue;
                }
            }
            $total++;
            if (empty($allResults[$q] ?? [])) {
                $empty++;
            }
        }

        return $total > 0 ? $empty / $total : 0.0;
    }

    // ─── Accent / Fuzzy ─────────────────────────────────

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
                if ($this->tokensMatch($searchText, $this->tokenize($needleLow))) {
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
            foreach ($results as $r) {
                $searchText = $this->extractSearchText($r);
                $cleanNeedle = normalizer_normalize(mb_strtolower($original), \Normalizer::FORM_KD);
                $cleanNeedle = $cleanNeedle ? preg_replace('/\p{Mn}/u', '', $cleanNeedle) : mb_strtolower($original);
                if ($this->tokensMatch($searchText, $this->tokenize($cleanNeedle))) {
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

    // ─── Getters ────────────────────────────────────────

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
        $q = $this->quantitative;
        $lat = $this->computeLatencyPercentiles();
        $q['Latency p50'] = ['value' => round($lat['p50'], 2), 'unit' => 'ms'];
        $q['Latency p95'] = ['value' => round($lat['p95'], 2), 'unit' => 'ms'];
        $q['Latency p99'] = ['value' => round($lat['p99'], 2), 'unit' => 'ms'];
        $q['Peak RAM'] = ['value' => $this->getPeakMemoryMB(), 'unit' => 'MB'];

        return ['quantity' => $q, 'quality' => $this->quality, 'soundness' => $this->soundness];
    }
}
