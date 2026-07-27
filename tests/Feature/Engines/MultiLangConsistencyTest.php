<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use PHPUnit\Framework\Attributes\DataProvider;
use Moaines\IllumiSearch\Engines\PgsqlEngine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class MultiLangConsistencyTest extends TestCase
{
    private const QT_MODEL = 'App\Models\BenchmarkPost';
    private const QT_COLUMNS = ['title', 'body'];

    public static function engineProvider(): array
    {
        $engines['SQLite'] = ['sqlite'];

        // PostgreSQL (if available)
        try {
            new \PDO(
                'pgsql:host=' . env('ILLUMI_SEARCH_PGSQL_HOST', '127.0.0.1') . ';port=' . env('ILLUMI_SEARCH_PGSQL_PORT', '5432') . ';dbname=' . env('ILLUMI_SEARCH_PGSQL_DATABASE', 'test-illumi-search'),
                env('ILLUMI_SEARCH_PGSQL_USERNAME', 'postgres'),
                env('ILLUMI_SEARCH_PGSQL_PASSWORD', 'password'),
                [\PDO::ATTR_TIMEOUT => 2]
            );
            $engines['PostgreSQL'] = ['pgsql'];
        } catch (\Throwable) {
        }

        return $engines;
    }

    private function createEngine(string $type): \Moaines\IllumiSearch\Contracts\Engine
    {
        if ($type === 'pgsql') {
            $engine = new \Moaines\IllumiSearch\Engines\PgsqlEngine;
            $engine->dropTable(self::QT_MODEL);
            $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);
            return $engine;
        }

        $path = storage_path('app/illumi-search-multilang.sqlite');
        @unlink($path);
        $engine = new SqliteEngine(databasePath: $path);
        $engine->dropTable(self::QT_MODEL);
        $engine->createTable(self::QT_MODEL, self::QT_COLUMNS);
        return $engine;
    }

    protected function tearDown(): void
    {
        $path = storage_path('app/illumi-search-multilang.sqlite');
        @unlink($path);
        parent::tearDown();
    }

    #[DataProvider('engineProvider')]
    public function test_cjk_search_finds_chinese(string $type): void
    {
        $e = $this->createEngine($type);
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php software engineering guide 软件工程', 'body' => 'learn software engineering 软件工程']);
        $results = $e->search('软件工程', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'CJK search should find results');
    }

    #[DataProvider('engineProvider')]
    public function test_rtl_search_finds_arabic(string $type): void
    {
        $e = $this->createEngine($type);
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php برمجيات guide', 'body' => 'learn برمجيات']);
        $results = $e->search('برمجيات', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Arabic search should find results');
    }

    #[DataProvider('engineProvider')]
    public function test_mixed_cjk_and_latin(string $type): void
    {
        $e = $this->createEngine($type);
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php software engineering framework 软件工程', 'body' => 'learn php software 软件工程']);
        $results = $e->search('php AND 软件工程', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Mixed CJK + Latin search should find results');
    }

    #[DataProvider('engineProvider')]
    public function test_cyrillic_search(string $type): void
    {
        $e = $this->createEngine($type);
        $e->upsert(self::QT_MODEL, 1, ['title' => 'php проект guide', 'body' => 'learn проект']);
        $results = $e->search('проект', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Cyrillic search should find results');
    }

    #[DataProvider('engineProvider')]
    public function test_accent_insensitive_search(string $type): void
    {
        $e = $this->createEngine($type);
        $e->upsert(self::QT_MODEL, 1, ['title' => 'génie logiciel', 'body' => 'le génie logiciel']);
        $results = $e->search('genie', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Accent-insensitive search should find accented text');
    }

    #[DataProvider('engineProvider')]
    public function test_spanish_search(string $type): void
    {
        $e = $this->createEngine($type);
        $e->upsert(self::QT_MODEL, 1, ['title' => 'desarrollo de software', 'body' => 'ingenieria de software']);
        $results = $e->search('desarrollo', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Spanish search should find results');
    }

    #[DataProvider('engineProvider')]
    public function test_portuguese_search(string $type): void
    {
        $e = $this->createEngine($type);
        $e->upsert(self::QT_MODEL, 1, ['title' => 'engenharia de software', 'body' => 'analise de requisitos']);
        $results = $e->search('engenharia', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Portuguese search should find results');
    }

    #[DataProvider('engineProvider')]
    public function test_arabic_normalization_via_default_processor(string $type): void
    {
        $e = $this->createEngine($type);
        // Insert full Arabic word; search with a shorter form that matches after normalization.
        // With UnicodeTextProcessor (default), برمجيات → برمج via Arabic normalization.
        $e->upsert(self::QT_MODEL, 1, ['title' => 'برمجيات php', 'body' => 'تطوير برمجيات']);
        $results = $e->search('برمج', [self::QT_MODEL], 10);
        $this->assertNotEmpty($results, 'Arabic search should find documents via normalization');
    }
}
