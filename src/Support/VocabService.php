<?php

namespace Moaines\IllumiSearch\Support;

use Moaines\IllumiSearch\Concerns\HasVocabSuggest;
use Moaines\IllumiSearch\Engines\FileEngine;
use Moaines\IllumiSearch\Text\HasTextHelpers;

class VocabService
{
    use HasTextHelpers;
    use HasVocabSuggest;

    private string $basePath;
    private string $prefix;

    public function __construct(string $basePath, string $prefix = 'illumi_search_')
    {
        $this->basePath = rtrim($basePath, '/');
        $this->prefix = $prefix;
    }

    public function vocabPath(): string
    {
        return $this->basePath . '/' . $this->prefix . 'vocab/words.php';
    }

    /**
     * Suggest spelling corrections for a query term.
     *
     * FileEngine delegates here. The workflow itself is the shared
     * HasVocabSuggest pipeline; this wrapper only carries the engine
     * reference needed to resolve tenant-scoped vocab files.
     *
     * @return string[]
     */
    public function suggest(string $query, int $maxDistance, int $limit, $engine): array
    {
        $this->fileEngine = $engine;

        return $this->runSuggest($query, $maxDistance, $limit);
    }

    /** @var FileEngine|null Engine reference for tenant-scoped vocab paths. */
    private ?FileEngine $fileEngine = null;

    /**
     * Backend step: FileEngine has no trigram index — candidate rows come
     * from the ASCII-prefix phase only (trigrams.php is intentionally empty).
     *
     * @return array{word: string, ascii_word: string}[]
     */
    protected function trigramCandidateRows(string $queryAscii, array $queryTrigrams, int $limit): iterable
    {
        return [];
    }

    /**
     * Backend step: vocab rows whose ASCII form starts with the prefix.
     *
     * @return array{word: string, ascii_word: string}[]
     */
    protected function prefixCandidateRows(string $query, string $queryAscii, string $prefix, int $limit): iterable
    {
        $rows = [];

        foreach ($this->collectVocab() as $row) {
            if (str_starts_with($row[1], $prefix)) {
                $rows[] = ['word' => $row[0], 'ascii_word' => $row[1]];
            }
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: string, 2: int}[]  Rows [word, ascii, count] from words.php.
     */
    private function collectVocab(): array
    {
        $path = $this->fileEngine instanceof FileEngine
            ? $this->fileEngine->getVocabPath()
            : $this->vocabPath();

        return $this->readFile($path);
    }

    /**
     * Read and decode a vocab file.
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
}
