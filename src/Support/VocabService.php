<?php

namespace Moaines\IllumiSearch\Support;

use Illuminate\Support\Collection;
use Moaines\IllumiSearch\Engines\FileEngine;
use Moaines\IllumiSearch\Text\HasTextHelpers;
use Symfony\Component\String\UnicodeString;

class VocabService
{
    use HasTextHelpers;

    private string $basePath;
    private string $prefix;

    public const SCRIPT_MISMATCH_PENALTY = 3;
    public const MAX_VOCAB_BYTES = 10 * 1024 * 1024;
    public const SUGGEST_PREFIX_LENGTH = 2;

    public function __construct(string $basePath, string $prefix = 'illumi_search_')
    {
        $this->basePath = rtrim($basePath, '/');
        $this->prefix = $prefix;
    }

    public function vocabPath(): string
    {
        return $this->basePath . '/' . $this->prefix . 'vocab/words.php';
    }

    public function trigramPath(): string
    {
        return $this->basePath . '/' . $this->prefix . 'vocab/trigrams.php';
    }

    /**
     * Suggest spelling corrections for a query term.
     *
     * Two-phase approach:
     *   1. Trigram matching (similar words share trigrams)
     *   2. Prefix Levenshtein fallback (2-char prefix + edit distance)
     *
     * @return string[]
     */
    public function suggest(string $query, int $maxDistance, int $limit, $engine): array
    {
        if (strlen(trim($query)) < 2) {
            return [];
        }

        $queryAscii = (string) (new UnicodeString($query))->ascii();
        $queryScripts = $this->scriptsOf($query);

        $vocab = $this->collectVocab($engine);
        $suggestions = [];

        $queryTrigrams = $this->wordToTrigrams($queryAscii);

        if (count($queryTrigrams) >= 2) {
            $trigramRows = $this->loadTrigramRows($engine);
            $trigramCollection = collect($trigramRows);

            $matchedWords = $trigramCollection
                ->filter(fn ($r) => is_array($r) && in_array($r['trigram'] ?? '', $queryTrigrams, true))
                ->groupBy('word')
                ->filter(fn ($group) => $group->count() >= min(2, count($queryTrigrams)))
                ->map(fn ($group, $word) => [
                    'word' => $word,
                    'avgDoc' => $group->avg('doc_count'),
                ])
                ->sortByDesc('avgDoc')
                ->take($limit * 3)
                ->pluck('word');

            if ($matchedWords->isNotEmpty()) {
                $v = $vocab->filter(fn ($r) => $matchedWords->contains($r[0]))
                    ->map(fn ($r) => (object) ['word' => $r[0], 'ascii_word' => $r[1]]);

                $suggestions = $this->rankSuggestions($v, $queryAscii, $queryScripts, $maxDistance);

                if (count($suggestions) >= $limit) {
                    return array_values(array_unique(array_slice($suggestions, 0, $limit)));
                }
            }
        }

        $prefix = mb_substr($queryAscii, 0, self::SUGGEST_PREFIX_LENGTH);
        $v = $vocab->filter(fn ($r) => str_starts_with($r[1], $prefix))
            ->map(fn ($r) => (object) ['word' => $r[0], 'ascii_word' => $r[1]]);

        $more = $this->rankSuggestions($v, $queryAscii, $queryScripts, $maxDistance);

        return array_values(array_unique(array_slice(array_merge($suggestions, $more), 0, $limit)));
    }

    private function collectVocab($engine): Collection
    {
        $path = $engine instanceof FileEngine
            ? $engine->getVocabPath()
            : $this->vocabPath();

        return collect($this->readFile($path));
    }

    private function loadTrigramRows($engine): array
    {
        $path = $engine instanceof FileEngine
            ? $engine->getVocabTrigramPath()
            : $this->trigramPath();

        return $this->readFile($path);
    }

    /**
     * Read and decode a vocab/trigram file.
     * Falls back to ChunkStorage::decodeFile() for all formats (HMAC, legacy, plain).
     */
    private function readFile(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        try {
            $data = (new ChunkStorage($this->basePath, 1))->decodeFile($path);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function rankSuggestions($vocab, string $queryAscii, array $queryScripts, int $maxDistance): array
    {
        $cache = [];

        return $vocab->map(function ($row) use ($queryAscii, &$cache) {
            $ascii = $row->ascii_word;
            $cache[$ascii] ??= $this->scriptsOf($row->word);

            return ['word' => $row->word, 'distance' => levenshtein($queryAscii, $ascii), 'scripts' => $cache[$ascii]];
        })
            ->filter(fn ($w) => $w['distance'] > -1 && $w['distance'] <= $maxDistance)
            ->map(fn ($w) => [
                'word' => $w['word'],
                'score' => $w['distance'] + (empty(array_intersect($queryScripts, $w['scripts'])) ? self::SCRIPT_MISMATCH_PENALTY : 0),
            ])
            ->sortBy('score')
            ->pluck('word')
            ->all();
    }
}
