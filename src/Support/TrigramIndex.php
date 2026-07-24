<?php

namespace Moaines\IllumiSearch\Support;

/**
 * Fixed-size trigram inverted index for fast full-text search.
 *
 * Alphabet: a-z (26) + 0-9 (10) + # (1) = 37 chars → 50,653 possible trigrams.
 * Each trigram maps to a posting list of document IDs (uint32 LE).
 *
 * Files:
 *   {model}.trigram — 810 KB fixed-size index (50,653 entries × 16 bytes)
 *   {model}.postings — variable-size posting lists (sequential uint32 docIds)
 *
 * Reference: php-fts (https://github.com/olivier-ls/php-fts)
 *
 * @internal
 */
class TrigramIndex
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789#';
    private const ALPHABET_SIZE = 37;
    private const TOTAL_ENTRIES = 50653; // 37³
    private const WORD_BOUNDARY = '#';
    private const HEADER_SIZE = 16;
    private const ENTRY_SIZE = 16;
    private const ID_SIZE = 4;
    private const FILE_MAGIC = 'TRIG';
    private const FILE_VERSION = 1;
    private const MAP_MAGIC = 'TMAP';
    private const MAP_VERSION = 1;
    private const INITIAL_CAPACITY = 4;
    private const GROWTH_FACTOR = 1.5;

    private ?string $modelClass = null;
    private ?string $trigramPath = null;
    private ?string $postingsPath = null;
    private ?string $mapPath = null;
    private ?string $basePath = null;
    private ?string $prefix = null;

    /** @var array<int, array{pathIdx: int, rowIdx: int}> docId → [chunkPathIndex, rowIndex] */
    private array $docMap = [];

    /** @var string[] Chunk paths indexed by build order */
    private array $chunkPaths = [];

    public function __construct(?string $basePath = null, ?string $prefix = null)
    {
        $this->basePath = $basePath;
        $this->prefix = $prefix ?? 'illumi_search_';
    }

    // ─── Path helpers ───────────────────────────────────

    private function trigramPath(string $modelClass): string
    {
        return $this->modelDir($modelClass) . '.trigram';
    }

    private function postingsPath(string $modelClass): string
    {
        return $this->modelDir($modelClass) . '.postings';
    }

    private function mapPath(string $modelClass): string
    {
        return $this->modelDir($modelClass) . '.map';
    }

    private function modelDir(string $modelClass): string
    {
        $name = str_replace('\\', '_', $modelClass);
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $name));
        $base = rtrim($this->basePath ?? storage_path('app/illumi-search-file-engine'), '/');

        return $base . '/' . $this->prefix . 'index/' . $name;
    }

    // ─── Trigram math (O(1)) ────────────────────────────

    private function trigramToIndex(string $trigram): int
    {
        if (strlen($trigram) !== 3) {
            throw new \InvalidArgumentException("Trigram must be exactly 3 chars: '$trigram'");
        }

        $idx = 0;
        for ($i = 0; $i < 3; $i++) {
            $pos = strpos(self::ALPHABET, $trigram[$i]);
            if ($pos === false) {
                throw new \InvalidArgumentException("Invalid char in trigram '$trigram': '{$trigram[$i]}'");
            }
            $idx = $idx * self::ALPHABET_SIZE + $pos;
        }

        return $idx;
    }

    /**
     * Convert index back to trigram string.
     */
    private function indexToTrigram(int $index): string
    {
        $c3 = $index % self::ALPHABET_SIZE;
        $index = (int) ($index / self::ALPHABET_SIZE);
        $c2 = $index % self::ALPHABET_SIZE;
        $c1 = (int) ($index / self::ALPHABET_SIZE);

        return self::ALPHABET[$c1] . self::ALPHABET[$c2] . self::ALPHABET[$c3];
    }

    // ─── Tokenizer ──────────────────────────────────────

    /**
     * Normalize text: lowercase, strip diacritics, keep [a-z0-9].
     */
    private function normalize(string $text): string
    {
        // Strip HTML
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // NFC normalize + strip combining marks
        $normalized = normalizer_normalize($text, \Normalizer::FORM_KD);
        if ($normalized !== false) {
            $text = preg_replace('/\p{Mn}/u', '', $normalized) ?? $text;
        }

        // Lowercase
        $text = mb_strtolower($text);

        // Replace non [a-z0-9] with space, collapse, trim
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Tokenize text into unique trigrams.
     */
    public function tokenize(string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        $words = explode(' ', $normalized);
        $trigrams = [];

        foreach ($words as $word) {
            $padded = self::WORD_BOUNDARY . $word . self::WORD_BOUNDARY;
            $len = strlen($padded);

            for ($i = 0; $i <= $len - 3; $i++) {
                $trigrams[] = substr($padded, $i, 3);
            }
        }

        return array_values(array_unique($trigrams));
    }

    // ─── Build index ────────────────────────────────────

    /**
     * Build trigram index from document chunks.
     *
     * Documents are processed one chunk at a time to keep memory bounded.
     * Postings are accumulated per trigram then flushed at the end.
     */
    /**
     * Write the document map file.
     * Format: [chunks: [path, ...], entries: [[chunkIdx, rowIdx], ...]]
     */
    private function writeMapFile(): void
    {
        $data = [
            'chunks' => $this->chunkPaths,
            'entries' => [],
        ];

        foreach ($this->docMap as $docId => $loc) {
            $data['entries'][$docId] = [$loc['pathIdx'], $loc['rowIdx']];
        }

        $temp = $this->mapPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($temp, serialize($data));
        rename($temp, $this->mapPath);
    }

    /**
     * Write the fixed-size .trigram file.
     */
    private function writeTrigramFile(string $path): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot write trigram file: $path");
        }

        fwrite($handle, self::FILE_MAGIC);
        fwrite($handle, pack('C', self::FILE_VERSION));
        fwrite($handle, str_repeat("\x00", 11));

        // Entries
        foreach ($this->entries as $entry) {
            $lo = $entry['offset'] & 0xFFFFFFFF;
            $hi = ($entry['offset'] >> 32) & 0xFFFFFFFF;
            fwrite($handle, pack('VVV', $lo, $hi, $entry['capacity']));
            fwrite($handle, pack('V', $entry['count']));
        }

        fclose($handle);
    }

    // ─── Load index (for search) ────────────────────────

    /**
     * Build trigram index from document chunks.
     *
     * @param  string[]  $chunkPaths
     * @param  callable  $rowTextExtractor  Function(array $row): array<int, string>
     * @param  callable|null  $decoder  Function(string $path): ?array, null = default unserialize
     */
    public function build(string $modelClass, array $chunkPaths, callable $rowTextExtractor, ?callable $decoder = null): void
    {
        if ($decoder === null) {
            $decoder = function (string $path): ?array {
                $content = file_get_contents($path);
                if ($content === false || $content === '') {
                    return null;
                }

                if (str_starts_with($content, 'hmac:')) {
                    $parts = explode(':', $content, 3);
                    if (count($parts) !== 3) {
                        return null;
                    }
                    if (hash_hmac(IllumiSearchHelper::HMAC_ALGO, $parts[2], IllumiSearchHelper::HMAC_KEY) !== $parts[1]) {
                        return null;
                    }
                    $data = unserialize($parts[2]);
                } else {
                    $data = unserialize($content);
                }

                return is_array($data) ? $data : null;
            };
        }
        $this->modelClass = $modelClass;
        $this->trigramPath = $this->trigramPath($modelClass);
        $this->postingsPath = $this->postingsPath($modelClass);
        $this->mapPath = $this->mapPath($modelClass);
        $this->chunkPaths = $chunkPaths;

        $dir = dirname($this->trigramPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->entries = [];
        for ($i = 0; $i < self::TOTAL_ENTRIES; $i++) {
            $this->entries[$i] = ['offset' => 0, 'capacity' => 0, 'count' => 0];
        }

        $pendingPostings = [];
        $docId = 1;
        $this->docMap = [];

        foreach ($chunkPaths as $chunkIdx => $chunkPath) {
            $rows = $decoder($chunkPath);

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $rowIdx => $row) {
                $this->docMap[$docId] = ['pathIdx' => $chunkIdx, 'rowIdx' => $rowIdx];
                $weightTexts = $rowTextExtractor($row);
                $combined = implode(' ', $weightTexts);
                $trigrams = $this->tokenize($combined);

                foreach ($trigrams as $t) {
                    $idx = $this->trigramToIndex($t);
                    $pendingPostings[$idx][] = $docId;
                }

                $docId++;
            }

            unset($rows);
        }

        // Write postings file
        $postingsDir = dirname($this->postingsPath);
        if (! is_dir($postingsDir)) {
            mkdir($postingsDir, 0755, true);
        }

        $handle = fopen($this->postingsPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot write postings: {$this->postingsPath}");
        }

        foreach ($this->entries as $idx => &$entry) {
            $docIds = $pendingPostings[$idx] ?? [];
            $docIds = array_values(array_unique($docIds));
            $count = count($docIds);

            if ($count === 0) {
                continue;
            }

            $capacity = max(self::INITIAL_CAPACITY, (int) ceil($count * self::GROWTH_FACTOR));
            $offset = ftell($handle);

            $data = pack('V*', ...$docIds);
            $padding = str_repeat("\x00", ($capacity - $count) * self::ID_SIZE);
            fwrite($handle, $data . $padding);

            $entry['offset'] = $offset;
            $entry['capacity'] = $capacity;
            $entry['count'] = $count;
        }
        unset($entry);

        fclose($handle);

        // Write trigram index file
        $this->writeTrigramFile($this->trigramPath);

        // Write document map file
        $this->writeMapFile();
    }

    /**
     * Load trigram index from disk.
     */
    public function load(string $modelClass): bool
    {
        $this->trigramPath = $this->trigramPath($modelClass);
        $this->postingsPath = $this->postingsPath($modelClass);
        $this->mapPath = $this->mapPath($modelClass);

        if (! file_exists($this->trigramPath)) {
            return false;
        }

        $handle = fopen($this->trigramPath, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, self::HEADER_SIZE);
        if ($header === false || strlen($header) < self::HEADER_SIZE) {
            fclose($handle);

            return false;
        }
        if (substr($header, 0, 4) !== self::FILE_MAGIC) {
            fclose($handle);

            return false;
        }

        $this->entries = [];

        for ($i = 0; $i < self::TOTAL_ENTRIES; $i++) {
            $data = fread($handle, self::ENTRY_SIZE);
            if ($data === false || strlen($data) < self::ENTRY_SIZE) {
                break;
            }

            $parsed = unpack('Vlo/Vhi/Vcapacity/Vcount', $data);
            if ($parsed === false) {
                break;
            }

            $this->entries[$i] = [
                'offset' => ($parsed['hi'] << 32) | $parsed['lo'],
                'capacity' => $parsed['capacity'],
                'count' => $parsed['count'],
            ];
        }

        fclose($handle);

        $this->loadMap();

        return true;
    }

    /**
     * Load the document map (docId → chunkPath, rowIndex).
     */
    private function loadMap(): void
    {
        if (! file_exists($this->mapPath)) {
            $this->docMap = [];
            $this->chunkPaths = [];

            return;
        }

        $content = file_get_contents($this->mapPath);
        if ($content === false || $content === '') {
            $this->docMap = [];
            $this->chunkPaths = [];

            return;
        }

        $data = unserialize($content);

        if (! is_array($data)) {
            $this->docMap = [];
            $this->chunkPaths = [];

            return;
        }

        $this->chunkPaths = $data['chunks'] ?? [];
        $this->docMap = [];

        foreach (($data['entries'] ?? []) as $docId => $loc) {
            $this->docMap[$docId] = ['pathIdx' => $loc[0], 'rowIdx' => $loc[1]];
        }
    }

    /**
     * Get chunk path and row index for a document ID.
     *
     * @return array{path: string, rowIdx: int}|null
     */
    public function getDocLocation(int $docId): ?array
    {
        $loc = $this->docMap[$docId] ?? null;

        if ($loc === null) {
            return null;
        }

        $path = $this->chunkPaths[$loc['pathIdx']] ?? null;

        if ($path === null) {
            return null;
        }

        return ['path' => $path, 'rowIdx' => $loc['rowIdx']];
    }

    /**
     * Check if trigram index exists for a model.
     */
    public function exists(string $modelClass): bool
    {
        return file_exists($this->trigramPath($modelClass));
    }

    /**
     * Delete trigram index files for a model.
     */
    public function delete(string $modelClass): void
    {
        foreach (['.trigram', '.postings', '.map'] as $ext) {
            $path = $this->modelDir($modelClass) . $ext;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    // ─── Search ──────────────────────────────────────────

    /**
     * Find candidate document IDs for a query using trigram intersection.
     *
     * Implements rarest-first optimization: trigrams sorted by document frequency
     * ascending, intersected progressively.
     *
     * @param  string  $query  The raw search query
     * @param  int  $maxCandidates  Maximum candidates to consider per trigram
     * @return array<int, int> docId => matchCount (number of matching trigrams)
     */
    public function candidates(string $query, int $maxCandidates = 5000): array
    {
        $queryTrigrams = $this->tokenize($query);

        if (empty($queryTrigrams)) {
            return [];
        }

        // Lookup and filter existing trigrams
        $trigramEntries = [];
        foreach ($queryTrigrams as $t) {
            $idx = $this->trigramToIndex($t);
            $entry = $this->entries[$idx] ?? ['count' => 0];
            if ($entry['count'] > 0) {
                $trigramEntries[$t] = $entry + ['index' => $idx];
            }
        }

        if (empty($trigramEntries)) {
            return [];
        }

        // Rarest-first: sort by document frequency ascending
        uasort($trigramEntries, fn ($a, $b) => $a['count'] <=> $b['count']);

        // Try intersection first
        $scores = $this->intersect($trigramEntries, $maxCandidates);

        // Fall back to union if intersection is too small
        if (count($scores) < 10) {
            $scores = $this->union($trigramEntries, $maxCandidates);
        }

        // Sort by match count descending
        arsort($scores);

        return $scores;
    }

    /**
     * Count documents matching a query.
     */
    public function count(string $query): int
    {
        $queryTrigrams = $this->tokenize($query);
        if (empty($queryTrigrams)) {
            return 0;
        }

        $matched = [];

        foreach ($queryTrigrams as $t) {
            $idx = $this->trigramToIndex($t);
            $entry = $this->entries[$idx] ?? ['offset' => 0, 'count' => 0];

            if ($entry['offset'] === 0 || $entry['count'] === 0) {
                continue;
            }

            $docIds = $this->readPostings($entry['offset'], $entry['count']);

            foreach ($docIds as $docId) {
                $matched[$docId] = true;
            }
        }

        return count($matched);
    }

    /**
     * Intersection mode: only documents matching ALL query trigrams.
     */
    private function intersect(array $trigramEntries, int $maxCandidates): array
    {
        $scores = null;

        foreach ($trigramEntries as $entry) {
            $count = min($entry['count'], $maxCandidates);
            $offset = $entry['offset'];
            $docIds = $this->readPostings($offset, $count);

            if ($scores === null) {
                $scores = [];
                foreach ($docIds as $docId) {
                    $scores[$docId] = 1;
                }
            } else {
                $docIdSet = array_flip($docIds);
                foreach ($scores as $docId => $score) {
                    if (isset($docIdSet[$docId])) {
                        $scores[$docId]++;
                    } else {
                        unset($scores[$docId]);
                    }
                }
            }

            if (empty($scores)) {
                break;
            }
        }

        return $scores ?? [];
    }

    /**
     * Union mode: documents matching ANY query trigram (weighted by match count).
     */
    private function union(array $trigramEntries, int $maxCandidates): array
    {
        $scores = [];

        foreach ($trigramEntries as $entry) {
            $count = min($entry['count'], $maxCandidates);
            $offset = $entry['offset'];
            $docIds = $this->readPostings($offset, $count);

            foreach ($docIds as $docId) {
                $scores[$docId] = ($scores[$docId] ?? 0) + 1;
            }
        }

        return $scores;
    }

    /**
     * Read postings from disk.
     *
     * @return int[] List of document IDs
     */
    private function readPostings(int $offset, int $count): array
    {
        if ($offset === 0 || $count === 0) {
            return [];
        }

        $handle = fopen($this->postingsPath, 'rb');
        if ($handle === false) {
            return [];
        }

        fseek($handle, $offset);
        $data = fread($handle, $count * self::ID_SIZE);

        fclose($handle);

        if ($data === false || strlen($data) < $count * self::ID_SIZE) {
            return [];
        }

        $docIds = unpack('V*', $data);

        return $docIds !== false ? array_values($docIds) : [];
    }

    // ─── Stats ──────────────────────────────────────────

    /**
     * Get total number of indexed trigrams (non-zero entries).
     */
    public function indexedTrigrams(): int
    {
        $count = 0;
        foreach ($this->entries as $entry) {
            if ($entry['offset'] > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get total number of postings (sum of all posting list lengths).
     */
    public function totalPostings(): int
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            $total += $entry['count'];
        }

        return $total;
    }
}
