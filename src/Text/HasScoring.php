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
    /** @var array<string, int> Per-request docCount cache, keyed by scope. */
    private static array $docCountCache = [];

    /**
     * Identity of this engine's index scope (database/connection + tenant).
     * Keeps the docCount cache isolated between bases/tenants in one process.
     */
    abstract protected function docCountScopeKey(): string;

    /**
     * Count the total indexed documents for the searched model classes.
     * Called once per request; the result is cached by scope.
     *
     * @param  array<class-string>  $modelClasses
     */
    abstract protected function countDocsInScope(array $modelClasses): int;

    /**
     * Reset the per-request docCount cache (call at the start of a search).
     */
    protected function resetDocCountCache(): void
    {
        self::$docCountCache = [];
    }

    /**
     * Total indexed documents for the searched model classes, cached per request.
     *
     * @param  array<class-string>  $modelClasses
     */
    protected function indexedDocCount(array $modelClasses): int
    {
        $key = $this->docCountScopeKey() . '|' . implode(',', $modelClasses);
        if (isset(self::$docCountCache[$key])) {
            return self::$docCountCache[$key];
        }

        return self::$docCountCache[$key] = $this->countDocsInScope($modelClasses);
    }

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
     * @param  float  $rawScore  Raw BM25 accumulated score
     * @param  int  $docCount  Total indexed documents (0 → passthrough)
     * @param  int  $maxWeight  Highest weight column for this engine
     * @return float Value between 0 and 100, or raw score if docCount is 0
     */
    protected function normalizeScore(float $rawScore, int $docCount, int $maxWeight = 3): float
    {
        if ($rawScore <= 0.0 || $docCount <= 0) {
            return $rawScore;
        }

        $N = $docCount;
        $idfMax = log(1 + ($N + 0.5) / 0.5);
        $scoreMaxPerTerm = $idfMax * ($this->bm25K1() + 1);
        $maxPossibleScore = $scoreMaxPerTerm * $maxWeight;

        return $maxPossibleScore > 0
            ? min(round($rawScore / $maxPossibleScore * 100, 1), 100.0)
            : 0.0;
    }
}
