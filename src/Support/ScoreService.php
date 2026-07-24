<?php

namespace Moaines\IllumiSearch\Support;

use Illuminate\Support\Str;

/**
 * BM25 scoring and tokenization service.
 *
 * Provides per-field weighted BM25 scoring (Robertson-Sparck Jones IDF),
 * quick substring scoring (fallback when no stats), and tokenization helpers.
 */
class ScoreService
{
    public const BM25_K1 = 1.2;
    public const BM25_B = 0.75;

    /**
     * Compute BM25 score for a single text field.
     *
     * @param  string  $text  Lowercased field text.
     * @param  string[]  $terms  Raw query terms (operators are skipped).
     * @param  array|null  $stats  Term-frequency map, or null for 0 score.
     */
    public function bm25Text(string $text, array $terms, ?array $stats): float
    {
        $freqMap = $this->tokenFrequencies($text);
        $docLength = array_sum($freqMap);

        if ($docLength === 0 || $stats === null) {
            return 0.0;
        }

        $N = $stats['docCount'] ?? 1;
        $avgdl = $stats['avgDocLength'] ?? $docLength;
        $termFreqs = $stats['terms'] ?? [];

        $score = 0.0;

        foreach ($terms as $term) {
            $clean = $this->cleanTerm($term);
            if ($clean === null) {
                continue;
            }

            $freq = $freqMap[$clean] ?? 0;
            if ($freq === 0) {
                continue;
            }

            $n = $termFreqs[$clean] ?? 0;
            $idf = log(1 + ($N - $n + 0.5) / ($n + 0.5));

            $score += $idf * ($freq * (self::BM25_K1 + 1))
                / ($freq + self::BM25_K1 * (1 - self::BM25_B + self::BM25_B * $docLength / $avgdl));
        }

        return $score;
    }

    /**
     * Score a document by weighting each column independently.
     *
     * @param  array<int, string>  $weightTexts  Per-weight texts (1-indexed).
     * @param  string[]  $terms  Raw query terms.
     * @param  array|null  $stats  Term-frequency map.
     * @param  int  $maxWeight  Highest weight to consider.
     */
    public function bm25Weighted(array $weightTexts, array $terms, ?array $stats, int $maxWeight): float
    {
        $totalScore = 0.0;
        $totalWeight = 0;

        for ($w = 1; $w <= $maxWeight; $w++) {
            $text = $weightTexts[$w] ?? '';
            if (trim($text) === '') {
                continue;
            }

            $fieldScore = $this->bm25Text($text, $terms, $stats);
            $totalScore += $w * $fieldScore;
            $totalWeight += $w;
        }

        $avgScore = $totalWeight > 0 ? $totalScore / $totalWeight : 0.0;

        return $this->normalize($avgScore, $stats, $maxWeight);
    }

    /**
     * Quick scoring: count matching tokens and word prefixes.
     *
     * Exact token matches are counted with their full frequency.
     * Word prefix matches (\b word boundary) add 0.1 each, capped at 1.0 per term.
     * Exact matches always outrank prefix-only matches.
     */
    public function quick(string $text, array $terms): float
    {
        $freqMap = $this->tokenFrequencies($text);
        $score = 0.0;

        foreach ($terms as $term) {
            $clean = $this->cleanTerm($term);
            if ($clean === null) {
                continue;
            }

            // Exact token match — full frequency
            $tokenScore = $freqMap[$clean] ?? 0;
            if ($tokenScore > 0) {
                $score += $tokenScore;
                continue;
            }

            // Word prefix match — count occurrences, capped at 1.0
            $lowerText = Str::lower($text);
            $prefixCount = preg_match_all('/\b' . preg_quote($clean, '/') . '/u', $lowerText);
            if ($prefixCount > 0) {
                $score += min($prefixCount * 0.1, 1.0);
            }
        }

        return $score;
    }

    /**
     * Normalize a BM25 score to 0–100.
     */
    public function normalize(float $rawScore, ?array $stats, int $maxWeight = 3): float
    {
        if ($rawScore <= 0.0 || $stats === null) {
            return $rawScore;
        }

        $N = $stats['docCount'] ?? 1;
        $idfMax = log(1 + ($N + 0.5) / 0.5);
        $scoreMaxPerTerm = $idfMax * (self::BM25_K1 + 1);
        $maxPossibleScore = $scoreMaxPerTerm * $maxWeight;

        return $maxPossibleScore > 0
            ? min(round($rawScore / $maxPossibleScore * 100, 1), 100.0)
            : 0.0;
    }

    /**
     * Clean a search term: strip operators, quotes, wildcards. Returns null for operators.
     *
     * @internal
     */
    private function cleanTerm(string $term): ?string
    {
        if (in_array(Str::upper($term), ['AND', 'OR', 'NOT', 'NEAR'], true)) {
            return null;
        }

        if (Str::startsWith($term, '"') && Str::endsWith($term, '"')) {
            return Str::lower(trim($term, '"'));
        }

        $cleaned = rtrim(Str::lower($term), '*');

        return $cleaned !== '' ? $cleaned : null;
    }

    public function tokenFrequencies(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($text));
        $hasNonLatin = IllumiSearchHelper::hasNonLatin($text);
        $freq = [];

        foreach ($tokens as $t) {
            $minLen = $hasNonLatin ? 1 : 2;
            if (Str::length($t) >= $minLen) {
                $freq[$t] = ($freq[$t] ?? 0) + 1;
            }
        }

        return $freq;
    }

    public function tokenCount(string $text): int
    {
        return count($this->tokenFrequencies($text));
    }
}
