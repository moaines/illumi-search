<?php

namespace Moaines\IllumiSearch\Tests\Feature\Engines;
use PHPUnit\Framework\Attributes\Test;

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

        // Index all languages once — each test searches without re-indexing
        $this->indexAllLanguages($this->engines);
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

    private function indexAllLanguages(array $engines): void
    {
        $processor = app(TextProcessor::class);
        $perLanguage = 25; // 25 posts per language = 175 total, covers all query types

        foreach ($engines as $engine) {
            $docId = 0;
            foreach (['en', 'fr', 'zh', 'ru', 'ar', 'es', 'pt'] as $lang) {
                $filtered = array_values(array_filter($this->posts, fn ($p) => ($p['language'] ?? '') === $lang));
                foreach (array_slice($filtered, 0, $perLanguage) as $post) {
                    $docId++;
                    $engine->upsert(self::MODEL_CLASS, $docId, [
                        'title' => $processor->process($post['title']),
                        'body' => $processor->process($post['body']),
                    ]);
                }
            }
        }
    }

    #[Test]
    public function dataset_has_multi_language_posts(): void
    {
        $langs = array_count_values(array_column($this->posts, 'language'));
        $this->assertGreaterThanOrEqual(5, count($langs));
        foreach (['en', 'fr', 'zh', 'ru', 'ar', 'es', 'pt'] as $lang) {
            $this->assertArrayHasKey($lang, $langs, "Missing language: $lang");
        }
    }

    #[Test]
    public function french_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $tests = ['logiciel', 'langage', 'programmation', 'informatique'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] FR search '$q' should return results");
            }
        });
    }

    #[Test]
    public function spanish_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $tests = ['software', 'desarrollo', 'arquitectura'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] ES search '$q' should return results");
            }
        });
    }

    #[Test]
    public function chinese_cjk_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $tests = ['系统', '工程', '数据'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] CJK search '$q' should return results for ZH posts");
            }
        });
    }

    #[Test]
    public function russian_cyrillic_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $tests = ['программного', 'язык', 'данных', 'программирования'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] Cyrillic search '$q' should return results for RU posts");
            }
        });
    }

    #[Test]
    public function arabic_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $tests = ['هندسة', 'برمجيات', 'تطوير', 'بيانات'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] Arabic search '$q' should return results for AR posts");
            }
        });
    }

    #[Test]
    public function portuguese_search_finds_results(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $tests = ['engenharia', 'software', 'requisitos', 'sistema'];

            foreach ($tests as $q) {
                $results = $engine->search($q, [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] PT search '$q' should return results for PT posts");
            }
        });
    }

    #[Test]
    public function wildcard_finds_prefix_in_any_language(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $prefixes = ['prog', 'soft', 'engi'];

            foreach ($prefixes as $p) {
                $results = $engine->search($p . '*', [self::MODEL_CLASS], 10);
                $this->assertNotEmpty($results,
                    "[$name] Wildcard '$p*' should find results across languages");
            }
        });
    }

    #[Test]
    public function phrase_search_works_across_languages(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $results = $engine->search('"software development"', [self::MODEL_CLASS], 10);
            $this->assertNotEmpty($results,
                "[$name] Phrase 'software development' should find EN results");
        });
    }

    #[Test]
    public function prefix_search_finds_partial_word_in_french(): void
    {
        $this->runForAllEngines(function (Engine $engine, string $name) {
            $results = $engine->search('prog', [self::MODEL_CLASS], 10);
            $this->assertNotEmpty($results,
                "[$name] 'prog' should return results for FR posts");
        });
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
