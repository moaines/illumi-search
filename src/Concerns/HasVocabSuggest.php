<?php

namespace Moaines\IllumiSearch\Concerns;

use Moaines\IllumiSearch\Support\SuggestRanker;

/**
 * Unique suggest() workflow shared by every engine.
 *
 * The spelling-correction pipeline is defined once here — guard, optional
 * pre-step, trigram phase, prefix phase, optional post-step, ranking — and
 * each engine only overrides the backend steps that fetch candidate rows:
 *
 *   runSuggest()
 *     ├─ beforeSuggest()                 // hook (PgSQL: build vocab when empty)
 *     ├─ trigramCandidateRows()          // backend: candidates via trigrams ([] if unsupported)
 *     ├─ prefixCandidateRows()           // backend: candidates via ASCII prefix
 *     ├─ rank()                          // shared: Levenshtein + script penalty
 *     └─ afterSuggest()                  // hook (PgSQL: ts_stat fallback)
 *
 * The workflow itself is NOT overridable — engines implement only the data
 * access (SQL/files). Ranking is centralized in SuggestRanker. Each engine
 * exposes a public suggest($query, $maxDistance, $limit) that calls
 * runSuggest() (keeping its own precondition logic in beforeSuggest()).
 *
 * Requires HasTextHelpers (scriptsOf, wordToTrigrams) on the consuming class.
 */
trait HasVocabSuggest
{
    /**
     * Run the shared suggest workflow.
     *
     * @return string[]
     */
    public function runSuggest(string $query, int $maxDistance, int $limit): array
    {
        if (mb_strlen(trim($query)) < 2) {
            return [];
        }

        $this->beforeSuggest();

        $queryAscii = $this->suggestRanker()->ascii($query);
        $queryScripts = $this->scriptsOf($query);

        $suggestions = [];
        $queryTrigrams = $this->wordToTrigrams($queryAscii);

        // Phase 1: trigram matching (candidates sharing ≥2 trigrams).
        if (count($queryTrigrams) >= 2) {
            $suggestions = $this->suggestRanker()->rank(
                $this->trigramCandidateRows($queryAscii, $queryTrigrams, $limit),
                $queryAscii,
                $queryScripts,
                $maxDistance,
            );

            if (count($suggestions) >= $limit) {
                return array_values(array_unique(array_slice($suggestions, 0, $limit)));
            }
        }

        // Phase 2: ASCII-prefix fallback (2-char prefix + Levenshtein).
        // Engines that index raw terms (SQLite fts5vocab) match on the raw
        // prefix, not the ASCII one — so the raw query is passed along.
        $prefix = mb_substr($queryAscii, 0, 2);
        $more = $this->suggestRanker()->rank(
            $this->prefixCandidateRows($query, $queryAscii, $prefix, $limit),
            $queryAscii,
            $queryScripts,
            $maxDistance,
        );

        $suggestions = array_merge($suggestions, $more);

        // Phase 3: engine-specific fallback when nothing matched so far.
        $suggestions = array_merge($suggestions, $this->afterSuggest($queryAscii, $queryScripts, $maxDistance, $limit, $suggestions));

        return array_values(array_unique(array_slice($suggestions, 0, $limit)));
    }

    private function suggestRanker(): SuggestRanker
    {
        return new SuggestRanker;
    }

    /**
     * Backend step: candidate rows via trigram matching.
     *
     * @return iterable<object|array{word: string, ascii_word?: string}>
     */
    protected function trigramCandidateRows(string $queryAscii, array $queryTrigrams, int $limit): iterable
    {
        return [];
    }

    /**
     * Backend step: candidate rows via ASCII-prefix match.
     *
     * @param  string  $query  Raw (un-normalized) query — for backends that
     *                         index raw terms (e.g. SQLite fts5vocab) and must
     *                         match on the raw prefix rather than the ASCII one.
     * @return iterable<object|array{word: string, ascii_word?: string}>
     */
    protected function prefixCandidateRows(string $query, string $queryAscii, string $prefix, int $limit): iterable
    {
        return [];
    }

    /** Hook before any matching (e.g. build the vocab on first call). */
    protected function beforeSuggest(): void
    {
    }

    /**
     * Hook after both phases (e.g. a slow fallback when nothing matched).
     *
     * @param  string[]  $queryScripts
     * @param  string[]  $suggestions
     * @return string[]
     */
    protected function afterSuggest(string $queryAscii, array $queryScripts, int $maxDistance, int $limit, array $suggestions): array
    {
        return [];
    }
}
