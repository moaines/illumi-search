<?php

namespace Moaines\IllumiSearch\Text;

/**
 * Shared scoring helpers for all engines.
 *
 * Provides BM25 normalization to a 0–100 range, making scores
 * comparable across queries and model classes.
 */
trait HasScoring
{
    /**
     * BM25 tuning parameter: term frequency saturation (1.2–2.0).
     */
    protected function bm25K1(): float
    {
        return 1.2;
    }

    /**
     * BM25 tuning parameter: length normalization (0–1, 0.75 = typical).
     */
    protected function bm25B(): float
    {
        return 0.75;
    }

    /**
     * Normalize a raw BM25 score to a 0–100 range.
     *
     * Uses the theoretical maximum BM25 score (rarest possible term,
     * highest weight) to normalize, making scores comparable across
     * different model classes and datasets.
     *
     * When $stats is null (no term-frequency map available), the raw
     * score is returned unchanged.
     *
     * @param  float  $rawScore  Raw BM25 accumulated score
     * @param  array|null  $stats  Stats containing docCount, or null for passthrough
     * @param  int  $maxWeight  Highest weight column for this engine
     * @return float Value between 0 and 100, or raw score if stats unavailable
     */
    protected function normalizeScore(float $rawScore, ?array $stats, int $maxWeight = 3): float
    {
        if ($rawScore <= 0.0) {
            return $rawScore;
        }

        if ($stats === null) {
            return $rawScore;
        }

        $N = $stats['docCount'] ?? 1;
        $idfMax = log(1 + ($N + 0.5) / 0.5);
        $scoreMaxPerTerm = $idfMax * ($this->bm25K1() + 1);
        $maxPossibleScore = $scoreMaxPerTerm * $maxWeight;

        return $maxPossibleScore > 0
            ? min(round($rawScore / $maxPossibleScore * 100, 1), 100.0)
            : 0.0;
    }
}
