<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Support\ChunkStorage;
use Moaines\IllumiSearch\Support\VocabService;
use Moaines\IllumiSearch\Tests\TestCase;

class VocabServiceTest extends TestCase
{
    private string $tempDir;
    private VocabService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/test_vocab_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->service = new VocabService($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function writeVocab(array $rows): void
    {
        $storage = new ChunkStorage($this->tempDir, 1);
        $storage->atomicWrite($this->service->vocabPath(), $rows);
    }

    public function test_suggest_returns_empty_for_short_query(): void
    {
        $this->assertSame([], $this->service->suggest('a', 2, 5, null));
    }

    public function test_suggest_returns_fuzzy_matches_from_vocab(): void
    {
        $this->writeVocab([
            ['laravel', 'laravel', 10],
            ['philosophy', 'philosophy', 3],
            ['lighthouse', 'lighthouse', 2],
        ]);

        $suggestions = $this->service->suggest('laravell', 2, 5, null);

        $this->assertContains('laravel', $suggestions);
    }

    public function test_suggest_returns_empty_when_no_vocab_file(): void
    {
        $this->assertSame([], $this->service->suggest('laravel', 2, 5, null));
    }

    public function test_suggest_respects_limit(): void
    {
        $this->writeVocab([
            ['apple', 'apple', 10],
            ['apricot', 'apricot', 8],
            ['apology', 'apology', 6],
            ['approve', 'approve', 4],
            ['appear', 'appear', 2],
        ]);

        $suggestions = $this->service->suggest('appl', 3, 3, null);

        $this->assertLessThanOrEqual(3, count($suggestions));
    }
}
