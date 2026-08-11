<?php

namespace Moaines\IllumiSearch\Tests\Unit\Engines;

use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class SqliteSuggestCyrillicTest extends TestCase
{
    private string $dbPath;
    private SqliteEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/sqlite_suggest_cyr_' . uniqid() . '.sqlite';
        $this->engine = new SqliteEngine($this->dbPath);
        $this->engine->createTable('App\Models\Post', ['title', 'body']);
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'программирование', 'body' => 'php']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'привет мир', 'body' => 'laravel']);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function test_suggest_cyrillic_prefix_matches_raw_terms(): void
    {
        // fts5vocab stores raw Cyrillic terms. A loose distance lets the
        // prefix candidate through, proving the raw-prefix LIKE works
        // (the default maxDistance=2 would reject this long word anyway,
        // same as the pre-refactor code did on raw bytes).
        $suggestions = $this->engine->suggest('програм', 20, 5);
        $this->assertContains('программирование', $suggestions);
    }

    public function test_suggest_cyrillic_near_match(): void
    {
        // "привет" vs "привет" is distance 0 (excluded as exact match), but
        // "привт" (typo) is distance 1 from "привет" — must be suggested.
        $suggestions = $this->engine->suggest('привт', 2, 5);
        $this->assertContains('привет', $suggestions);
    }
}
