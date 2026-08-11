<?php

namespace Moaines\IllumiSearch\Support;

use Moaines\IllumiSearch\Text\HasTextHelpers;
use Symfony\Component\String\UnicodeString;

/**
 * Shared spelling-correction ranking for every engine's suggest().
 *
 * Pure logic: rank candidate vocab rows ({word, ascii_word?}) by
 * Levenshtein distance to the query's ASCII form, plus a script mismatch
 * penalty so a Latin query never suggests a Cyrillic word that happens to
 * share the same ASCII transcription.
 *
 * Single source of truth for SCRIPT_MISMATCH_PENALTY (previously defined in
 * VocabService and MySqlEngine, and hard-coded in PgsqlEngine).
 */
class SuggestRanker
{
    use HasTextHelpers;

    public const SCRIPT_MISMATCH_PENALTY = 3;

    /**
     * ASCII-transliterate a word (used to compare words across scripts).
     */
    public function ascii(string $word): string
    {
        return (string) (new UnicodeString($word))->ascii();
    }

    /**
     * Rank candidate vocab rows by distance + script penalty.
     *
     * @param  iterable<object{word: string, ascii_word?: string}|array{word: string, ascii_word?: string}>  $rows
     * @param  string[]  $queryScripts
     * @return string[]  Words sorted by ascending score (distance + penalty).
     */
    public function rank(iterable $rows, string $queryAscii, array $queryScripts, int $maxDistance): array
    {
        $scriptCache = [];
        $results = [];

        foreach ($rows as $row) {
            $word = is_array($row) ? $row['word'] : $row->word;
            if ($word === '' || mb_strlen($word) < 2) {
                continue;
            }

            $asciiWord = is_array($row) ? ($row['ascii_word'] ?? '') : ($row->ascii_word ?? '');
            $asciiWord = $asciiWord !== '' ? $asciiWord : $this->ascii($word);

            $distance = levenshtein($queryAscii, $asciiWord);
            // Distance 0 is an exact match — spellcheck must never suggest
            // the exact word that was typed (it's already in the index).
            if ($distance <= 0 || $distance > $maxDistance) {
                continue;
            }

            $wordScripts = $scriptCache[$asciiWord] ??= $this->scriptsOf($word);
            $penalty = empty(array_intersect($queryScripts, $wordScripts)) ? self::SCRIPT_MISMATCH_PENALTY : 0;

            $results[] = ['word' => $word, 'score' => $distance + $penalty];
        }

        usort($results, fn ($a, $b) => $a['score'] <=> $b['score']);

        return array_column($results, 'word');
    }
}
