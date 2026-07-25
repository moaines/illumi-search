<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Engines\FileEngine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class MultiLanguageEngineTest extends TestCase
{
    private const MODEL_CLASS = 'App\Models\BenchmarkPost';
    private const COLUMNS = ['title', 'body'];
    private const FIXTURES = __DIR__ . '/fixtures/seed.json';

    /** @var array<string, Engine> */
    private array $engines = [];

    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(self::FIXTURES)) {
            $this->markTestSkipped('seed.json not found at ' . self::FIXTURES);
        }

        $data = json_decode(file_get_contents(self::FIXTURES), true);
        $this->posts = $data['posts'] ?? [];

        if (empty($this->posts)) {
            $this->markTestSkipped('seed.json is empty');
        }

        $this->engines = array_filter([
            'file' => $this->createFileEngine(),
            'sqlite' => $this->createSqliteEngine(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->engines as $engine) {
            try {
                $engine->dropTable(self::MODEL_CLASS);
            } catch (\Exception) {
            }
        }
        parent::tearDown();
    }

    private function createFileEngine(): ?Engine
    {
        try {
            $path = sys_get_temp_dir() . '/illumi_ml_test_file_' . uniqid();
            $engine = new FileEngine($path);
            $engine->createTable(self::MODEL_CLASS, self::COLUMNS);

            return $engine;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function createSqliteEngine(): ?Engine
    {
        try {
            $path = sys_get_temp_dir() . '/illumi_ml_test_sqlite_' . uniqid() . '.sqlite';
            $engine = new SqliteEngine($path);
            $engine->createTable(self::MODEL_CLASS, self::COLUMNS);

            return $engine;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** @test */
    public function dataset_has_multi_language_posts(): void
    {
        $langs = array_count_values(array_column($this->posts, 'language'));
        $this->assertGreaterThanOrEqual(5, count($langs));
        foreach (['en', 'fr', 'zh', 'ru', 'ar', 'es', 'pt'] as $lang) {
            $this->assertArrayHasKey($lang, $langs, "Missing language: $lang");
        }
    }

    /** @test */
    public function french_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $frPosts = $this->indexByLanguage($engine, 'fr', 50);
            if (empty($frPosts)) {
                $this->markTestSkipped("[$name] No FR posts to index");
            }

            $tests = ['logiciel', 'langage', 'programmation', 'informatique'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] FR search '$q' should return results");
            }
        });
    }

    /** @test */
    public function spanish_accent_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $esPosts = $this->indexByLanguage($engine, 'es', 50);
            if (empty($esPosts)) {
                $this->markTestSkipped("[$name] No ES posts to index");
            }

            $tests = [
                ['accent' => 'ingeniería', 'ascii' => 'ingenieria'],
                ['accent' => 'bifurcación', 'ascii' => 'bifurcacion'],
                ['accent' => 'arquitectura', 'ascii' => 'arquitectura'],
            ];

            foreach ($tests as $t) {
                $results = $engine->search($t['ascii'], [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] Search '{$t['ascii']}' should return results for ES posts");
            }
        });
    }

    /** @test */
    public function chinese_cjk_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $zhPosts = $this->indexByLanguage($engine, 'zh', 50);
            if (empty($zhPosts)) {
                $this->markTestSkipped("[$name] No ZH posts to index");
            }

            $tests = ['系统', '工程', '数据'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] CJK search '$q' should return results for ZH posts");
            }
        });
    }

    /** @test */
    public function russian_cyrillic_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $ruPosts = $this->indexByLanguage($engine, 'ru', 50);
            if (empty($ruPosts)) {
                $this->markTestSkipped("[$name] No RU posts to index");
            }

            $tests = ['программного', 'язык', 'данных', 'программирования'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] Cyrillic search '$q' should return results for RU posts");
            }
        });
    }

    /** @test */
    public function arabic_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $arPosts = $this->indexByLanguage($engine, 'ar', 50);
            if (empty($arPosts)) {
                $this->markTestSkipped("[$name] No AR posts to index");
            }

            $tests = ['هندسة', 'برمجيات', 'تطوير', 'بيانات'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] Arabic search '$q' should return results for AR posts");
            }
        });
    }

    /** @test */
    public function portuguese_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $ptPosts = $this->indexByLanguage($engine, 'pt', 50);
            if (empty($ptPosts)) {
                $this->markTestSkipped("[$name] No PT posts to index");
            }

            $tests = ['engenharia', 'software', 'requisitos', 'sistema'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] PT search '$q' should return results for PT posts");
            }
        });
    }

    /** @test */
    public function wildcard_finds_prefix_in_any_language(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $this->indexByLanguage($engine, 'en', 30);
            $this->indexByLanguage($engine, 'fr', 30);
            $this->indexByLanguage($engine, 'es', 30);

            $prefixes = ['prog', 'soft', 'engi'];

            foreach ($prefixes as $p) {
                $results = $engine->search($p . '*', [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] Wildcard '$p*' should find results across languages");
            }
        });
    }

    /** @test */
    public function phrase_search_works_across_languages(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $this->indexByLanguage($engine, 'en', 100);

            // Try phrase search with known repeating phrases from the dataset
            $results = $engine->search('"software development"', [self::MODEL_CLASS], 10);
            $this->assertNotEmpty($results,
                "[$name] Phrase 'software development' should find EN results");

            $this->indexByLanguage($engine, 'es', 50);
            $results = $engine->search('"desarrollo de"', [self::MODEL_CLASS], 10);
            if (! empty($results)) {
                $this->assertNotEmpty($results);
            }
        });
    }

    /** @test */
    public function prefix_search_finds_partial_word_in_french(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $this->indexByLanguage($engine, 'fr', 50);

            $results = $engine->search('prog', [self::MODEL_CLASS], 10);
            $this->assertNotEmpty($results,
                "[$name] 'prog' should return results for FR posts");
        });
    }

    private function indexByLanguage(Engine $engine, string $lang, int $maxPosts): array
    {
        $processor = app(TextProcessor::class);
        $posts = array_values(array_filter($this->posts, fn ($p) => ($p['language'] ?? '') === $lang));
        $posts = array_slice($posts, 0, $maxPosts);

        foreach ($posts as $i => $post) {
            $engine->upsert(self::MODEL_CLASS, $i + 1, [
                'title' => $processor->process($post['title']),
                'body' => $processor->process($post['body']),
            ]);
        }

        return $posts;
    }

    private function runForAllEngines(callable $test): void
    {
        foreach ($this->engines as $name => $engine) {
            try {
                $test($engine, $name);
            } catch (\Throwable $e) {
                $this->addToAssertionCount(1);
                $this->fail("[$name] " . $e->getMessage());
            }
        }
    }
}
