<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Support\TrigramIndex;
use Moaines\IllumiSearch\Tests\TestCase;

class TrigramIndexTest extends TestCase
{
    private string $tempDir;
    private TrigramIndex $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/test_trigram_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->index = new TrigramIndex($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') as $f) {
            if (is_dir($f)) {
                array_map('unlink', glob($f . '/*'));
                @rmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /** Build an index from [rowId => [weightTexts...]] rows, one chunk per row. */
    private function buildIndex(array $rows): void
    {
        $chunks = [];
        foreach ($rows as $i => $texts) {
            $path = $this->tempDir . '/chunk' . $i;
            file_put_contents($path, serialize([$texts]));
            $chunks[] = $path;
        }

        $this->index->build('App\Models\Doc', $chunks, fn (array $row) => $row);
    }

    public function test_cjk_query_finds_documents_through_trigrams(): void
    {
        $this->buildIndex([
            1 => ['系统设计', 'laravel guide'],
            2 => ['数据库', 'php'],
            3 => ['系统架构', 'laravel'],
        ]);

        $this->assertSame([1, 3], array_keys($this->index->candidates('系统 设计', 100)));
        $this->assertSame([2], array_keys($this->index->candidates('数据库', 100)));
    }

    public function test_single_cjk_character_recall(): void
    {
        $this->buildIndex([
            1 => ['系统设计'],
            2 => ['数据库'],
            3 => ['系统架构'],
        ]);

        // A single CJK char must still find every doc containing it.
        $this->assertSame([1, 3], array_keys($this->index->candidates('系', 100)));
    }

    public function test_cjk_candidates_are_verified_not_strict(): void
    {
        // "统设" shares the "系" trigrams with doc 3 ("系统架构") — the trigram
        // path is a candidate generator, exact matching happens in the engine.
        // The candidate set must be a SUPERSET of true matches (recall), never
        // miss a real match.
        $this->buildIndex([
            1 => ['系统设计'],
            3 => ['系统架构'],
        ]);

        $candidates = array_keys($this->index->candidates('统设', 100));
        $this->assertContains(1, $candidates);
    }

    public function test_latin_query_still_works(): void
    {
        $this->buildIndex([
            1 => ['系统设计', 'laravel guide'],
            2 => ['数据库', 'php'],
            3 => ['系统架构', 'laravel'],
        ]);

        $this->assertSame([1, 3], array_keys($this->index->candidates('laravel', 100)));
        $this->assertSame([2], array_keys($this->index->candidates('php', 100)));
    }

    public function test_version_mismatch_makes_load_return_false(): void
    {
        $this->buildIndex([1 => ['system guide']]);

        $trigramFile = $this->tempDir . '/illumi_search_index/app_models_doc.trigram';
        $this->assertFileExists($trigramFile);

        // Corrupt the version byte to simulate an index from an older format.
        $handle = fopen($trigramFile, 'r+b');
        fseek($handle, 4);
        fwrite($handle, "\x01");
        fclose($handle);

        $this->assertFalse($this->index->load('App\Models\Doc'));
    }

    public function test_tokenize_encodes_non_latin_deterministically(): void
    {
        $method = new \ReflectionMethod($this->index, 'tokenize');

        $a = $method->invoke($this->index, '系统设计');
        $b = $method->invoke($this->index, '系统设计');

        $this->assertSame($a, $b);
        $this->assertNotEmpty($a);
        // Every trigram must live in the ASCII alphabet (no raw CJK bytes).
        $this->assertMatchesRegularExpression('/^[a-z0-9#]+$/', implode('', $a));
    }

    public function test_tokenize_encodes_only_character_tokenized_scripts(): void
    {
        // The trigram index only encodes scripts tokenized per-character
        // (CJK/Thai/Lao/Khmer/Myanmar, per IllumiSearchHelper::hasNonLatin).
        // Other non-Latin scripts (Cyrillic, Arabic) and symbols produce no
        // trigrams and fall back to the full scan.
        $method = new \ReflectionMethod($this->index, 'tokenize');

        // CJK-like scripts are encoded → candidate trigrams exist.
        $this->assertNotEmpty($method->invoke($this->index, '系统设计'));
        $this->assertNotEmpty($method->invoke($this->index, 'ระบบ'));
        $this->assertNotEmpty($method->invoke($this->index, 'ភាសា'));

        // Cyrillic, Arabic, Greek and emoji are not in the trigram alphabet.
        $this->assertSame([], $method->invoke($this->index, 'привет'));
        $this->assertSame([], $method->invoke($this->index, 'مرحبا'));
        $this->assertSame([], $method->invoke($this->index, 'κόσμος'));
        $this->assertSame([], $method->invoke($this->index, '😀'));
    }

    public function test_mixed_script_text_encodes_only_cjk_part(): void
    {
        // A doc mixing Cyrillic and CJK must still be findable by its CJK part
        // through the trigram path; the Cyrillic part is served by the scan.
        $this->buildIndex([
            1 => ['привет 世界 guide'],
        ]);

        $this->assertSame([1], array_keys($this->index->candidates('世界', 100)));
        // Cyrillic-only query yields no trigram candidates → engine falls back.
        $this->assertSame([], array_keys($this->index->candidates('привет', 100)));
    }
}
