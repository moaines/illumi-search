<?php

namespace Moaines\IllumiSearch\Support;

use Illuminate\Support\Str;

/**
 * Boolean matching service for FileEngine.
 *
 * Evaluates AND/OR/NOT operator expressions against document texts.
 * Supports per-weight column matching (no concatenation needed).
 *
 * Matching strategy:
 *   1. Exact token match (in_array) — precise, no false positives
 *   2. Word prefix match (regex \b) — tolerant: "lara" → "laravel", not "declarative"
 *   3. Phrase match — Str::contains on raw text
 *
 * Script-aware: CJK/Thai/Lao/Khmer single characters are kept as tokens
 * (the usual `length >= 2` filter doesn't apply to non-Latin scripts).
 */
class MatchService
{
    /**
     * Match a document by checking each weight column independently.
     *
     * @param  array<int, string>  $weightTexts  Per-weight texts (1-indexed).
     * @param  string[]  $terms  Raw query terms (preserves operators, quotes).
     */
    public function anyWeightText(array $weightTexts, array $terms): bool
    {
        return $this->evaluate(
            $this->parseTerms($terms),
            $this->parseColumns($weightTexts),
        );
    }

    /**
     * Match a document against already-parsed terms.
     * Use this in hot loops: parse the query once, then match every row.
     *
     * @param  array<int, string>  $weightTexts
     * @param  array{mustMatch: string[], shouldMatch: string[], exclude: string[]}  $parsed
     */
    public function anyWeightParsed(array $weightTexts, array $parsed): bool
    {
        return $this->evaluate($parsed, $this->parseColumns($weightTexts));
    }

    /**
     * Parse raw query terms into structured groups (once per query).
     *
     * @return array{mustMatch: string[], shouldMatch: string[], exclude: string[]}
     */
    public function parseTerms(array $terms): array
    {
        $mustMatch = [];
        $shouldMatch = [];
        $exclude = [];
        $nextIsNot = false;
        $pendingOr = false;

        foreach ($terms as $term) {
            $upper = Str::upper($term);

            if ($upper === 'OR') {
                $pendingOr = true;
                continue;
            }
            if ($upper === 'AND') {
                continue;
            }
            if ($upper === 'NOT') {
                $nextIsNot = true;
                continue;
            }
            if ($upper === 'NEAR') {
                continue;
            }

            if ($nextIsNot) {
                $exclude[] = $this->cleanTerm($term);
                $nextIsNot = false;
                continue;
            }

            $clean = $this->cleanTerm($term);

            if ($pendingOr) {
                $shouldMatch[] = $clean;
                $pendingOr = false;
            } else {
                $mustMatch[] = $clean;
            }
        }

        return compact('mustMatch', 'shouldMatch', 'exclude');
    }

    private function cleanTerm(string $term): string
    {
        if (str_starts_with($term, '"') && str_ends_with($term, '"')) {
            return mb_strtolower(trim($term, '"'));
        }

        return rtrim(mb_strtolower($term), '*');
    }

    /**
     * Parse all weight columns into tokens + raw texts in a single pass.
     *
     * @param  array<int, string>  $weightTexts
     * @return array{tokens: array<int, string[]>, texts: array<int, string>}
     */
    private function parseColumns(array $weightTexts): array
    {
        $tokens = [];
        $texts = [];

        for ($w = 1; $w <= count($weightTexts); $w++) {
            $text = $weightTexts[$w] ?? '';

            if (trim($text) === '') {
                $tokens[$w] = [];
                $texts[$w] = '';
                continue;
            }

            $lower = mb_strtolower($text);
            $texts[$w] = $lower;

            $words = preg_split('/[^\p{L}\p{N}]+/u', $lower);

            // Keep single CJK/Thai characters (they are valid tokens)
            $hasNonLatin = IllumiSearchHelper::hasNonLatin($lower);

            $unique = [];
            foreach ($words as $token) {
                $len = mb_strlen($token);
                if ($len === 0) {
                    continue;
                }
                if (! $hasNonLatin && $len < 2) {
                    continue;
                }
                if (! isset($unique[$token])) {
                    $unique[$token] = true;
                }
            }

            $tokens[$w] = array_keys($unique);
        }

        return compact('tokens', 'texts');
    }

    // ─── Matching ──────────────────────────────────────

    /**
     * Check if a term (possibly a phrase) exists in any column.
     *
     * Strategy:
     *   1. Phrase ("exact words") → Str::contains on raw column text
     *   2. Single word → exact token match (in_array)
     *   3. Single word → word prefix match (\b regex, tolerant: "lara" → "laravel")
     */
    private function termInColumns(string $term, array $tokens, array $texts): bool
    {
        $words = preg_split('/\s+/', $term);

        if (count($words) > 1) {
            // Phrase: check consecutive words in raw text
            foreach ($texts as $ct) {
                if (str_contains($ct, $term)) {
                    return true;
                }
            }

            return false;
        }

        // 1. Exact token match
        foreach ($tokens as $colTokens) {
            if (in_array($term, $colTokens, true)) {
                return true;
            }
        }

        // 2. Word prefix match ("lara" → "laravel", not "declarative")
        foreach ($texts as $ct) {
            if (preg_match('/\b' . preg_quote($term, '/') . '/u', $ct)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate operator precedence with AND/OR/NOT.
     */
    private function evaluate(array $parsed, array $columns): bool
    {
        $mustMatch = $parsed['mustMatch'];
        $shouldMatch = $parsed['shouldMatch'];
        $exclude = $parsed['exclude'];
        $tokens = $columns['tokens'];
        $texts = $columns['texts'];

        // NOT exclusion
        foreach ($exclude as $ex) {
            if ($this->termInColumns($ex, $tokens, $texts)) {
                return false;
            }
        }

        // AND mode (no OR): all mustMatch required
        if (empty($shouldMatch)) {
            if (empty($mustMatch)) {
                return false;
            }
            foreach ($mustMatch as $m) {
                if (! $this->termInColumns($m, $tokens, $texts)) {
                    return false;
                }
            }

            return true;
        }

        // OR mode: any shouldMatch or mustMatch triggers match
        $allOr = array_merge($shouldMatch, $mustMatch);
        foreach ($allOr as $s) {
            if ($this->termInColumns($s, $tokens, $texts)) {
                return true;
            }
        }

        return false;
    }
}
