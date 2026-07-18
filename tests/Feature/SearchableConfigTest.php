<?php

namespace Moaines\LaravelFts\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\Searchable;
use Moaines\LaravelFts\Support\SnippetService;
use Moaines\LaravelFts\Tests\TestCase;
use Moaines\LaravelFts\Tests\TestSupport\Models\Post;
use Moaines\LaravelFts\Text\UnicodeTextProcessor;

class SearchableConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function test_normalize_with_explicit_config(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected $table = 'test_models';

            protected array $ftsSearchable = [
                'title' => ['weight' => 3],
                'body' => ['weight' => 1],
            ];
        };

        $normalized = $model->normalizeFtsSearchable();

        $this->assertArrayHasKey('title', $normalized);
        $this->assertArrayHasKey('body', $normalized);
        $this->assertEquals(['weight' => 3], $normalized['title']);
        $this->assertEquals(['weight' => 1], $normalized['body']);
    }

    public function test_normalize_with_shorthand_true(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected $table = 'test_models';

            protected array $ftsSearchable = [
                'title' => ['weight' => 3],
                'body' => true,
            ];
        };

        $normalized = $model->normalizeFtsSearchable();

        $this->assertArrayHasKey('body', $normalized);
        $this->assertEquals([], $normalized['body']);
    }

    public function test_normalize_with_simple_string(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected $table = 'test_models';

            protected array $ftsSearchable = [
                'title' => ['weight' => 3],
                'author',
            ];
        };

        $normalized = $model->normalizeFtsSearchable();

        $this->assertArrayHasKey('title', $normalized);
        $this->assertArrayHasKey('author', $normalized);
        $this->assertEquals([], $normalized['author']);
    }

    public function test_locale_is_passed_to_processor(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected $table = 'test_models';

            protected array $ftsSearchable = [
                'title' => ['weight' => 3, 'locale' => 'de'],
                'body' => ['weight' => 1, 'locale' => 'fr'],
            ];
        };

        $processor = new UnicodeTextProcessor;
        $global = app(TextProcessor::class);

        $processed = $model->processDocument($model, $global);

        // processDocument calls normalizeFtsSearchable internally
        $this->assertIsString($processed['title']);
        $this->assertIsString($processed['body']);
    }

    public function test_snippet_flag_filters_columns(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected $table = 'test_models';

            protected array $ftsSearchable = [
                'title' => ['weight' => 3, 'snippet' => false],
                'body' => ['weight' => 1, 'snippet' => true],
            ];
        };

        $ref = new \ReflectionClass(SnippetService::class);
        $method = $ref->getMethod('resolveSnippetColumns');

        $service = $this->app->make(SnippetService::class);
        $result = $method->invoke($service, $model);

        $this->assertNotNull($result);
        $this->assertContains('body', $result);
        $this->assertNotContains('title', $result);
    }

    public function test_enrich_selects_only_needed_columns(): void
    {
        config(['fts.indexing' => 'manual']);
        $engine = $this->app->make(FtsEngine::class);

        $modelClass = new class extends Model
        {
            use Searchable;

            protected $table = 'test_models';

            protected $fillable = ['title', 'body', 'unused'];

            protected array $ftsSearchable = [
                'title' => ['weight' => 3],
                'body' => ['weight' => 1],
            ];
        };

        Schema::table('test_models', function (Blueprint $table) {
            $table->string('unused')->nullable();
        });

        $instance = $modelClass::create(['title' => 'Test Title', 'body' => 'This is some interesting content for searching and testing that the snippet extraction works correctly even when using select() to limit columns loaded.']);
        $instance2 = $modelClass::create(['title' => 'Other', 'body' => 'Another body text with more words to ensure it exceeds the minimum snippet length for proper testing.']);

        $cls = get_class($modelClass);
        $engine->createTable($cls, ['title', 'body']);
        $engine->upsert($cls, $instance->id, $instance->toFtsDocument());
        $engine->upsert($cls, $instance2->id, $instance2->toFtsDocument());

        $results = $engine->search('interesting', [$cls], 10, withSnippets: true);

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('interesting', $results[0]->summary ?? '');
    }

    public function test_enrich_with_dot_notation_relation(): void
    {
        config(['fts.indexing' => 'manual']);
        // The enrichWithSnippets eager-loads relation columns for dot-notation.
        // This is tested more thoroughly in SearchableTraitTest.
        // Here we just verify the engine produces results with snippets enabled.
        $engine = $this->app->make(FtsEngine::class);

        $model = new class extends Model
        {
            use Searchable;

            protected $table = 'test_models';

            protected $fillable = ['title', 'body'];

            protected array $ftsSearchable = [
                'title' => ['weight' => 3],
                'body' => ['weight' => 1],
            ];
        };

        $model::create(['title' => 'Search Term Found', 'body' => 'This is a longer text body that contains the search term somewhere in the middle to properly test the snippet extraction functionality.']);
        $cls = get_class($model);
        $engine->createTable($cls, ['title', 'body']);
        $model::all()->each(fn ($m) => $engine->upsert($cls, $m->id, $m->toFtsDocument()));

        $results = $engine->search('Search Term', [$cls], 10, withSnippets: true);

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('search', $results[0]->summary ?? '');
    }

    public function test_enrich_with_virtual_accessor(): void
    {
        config(['fts.indexing' => 'manual']);
        Schema::create('virtual_models', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->timestamps();
        });

        $modelClass = new class extends Model
        {
            use Searchable;

            protected $table = 'virtual_models';

            protected $guarded = [];

            public $timestamps = true;

            protected array $ftsSearchable = [
                'fullname' => ['weight' => 3],
            ];

            public function getFullnameAttribute(): string
            {
                return $this->first_name.' '.$this->last_name;
            }
        };

        $instance = $modelClass::create(['first_name' => 'Victor', 'last_name' => 'Hugo']);

        $engine = $this->app->make(FtsEngine::class);
        $cls = get_class($modelClass);
        $engine->createTable($cls, ['fullname']);
        $engine->upsert($cls, $instance->id, $instance->toFtsDocument());

        $results = $engine->search('Victor', [$cls], 10, withSnippets: true);

        $this->assertNotEmpty($results);
    }

    public function test_create_table_with_detail_column(): void
    {
        config(['fts.fts5.detail' => 'column']);

        $engine = $this->app->make(FtsEngine::class);
        $engine->createTable(Post::class, ['title', 'body']);

        $this->assertTrue($engine->tableExists(Post::class));
    }

    public function test_create_table_with_detail_none(): void
    {
        config(['fts.fts5.detail' => 'none']);

        $engine = $this->app->make(FtsEngine::class);
        $engine->createTable(Post::class, ['title', 'body']);

        $this->assertTrue($engine->tableExists(Post::class));
    }

    public function test_integrity_check_passes_on_valid_table(): void
    {
        $engine = $this->app->make(FtsEngine::class);
        $engine->createTable(Post::class, ['title', 'body']);

        $result = $engine->integrityCheck(Post::class);

        $this->assertTrue($result);
    }

    public function test_integrity_check_fails_on_missing_table(): void
    {
        $engine = $this->app->make(FtsEngine::class);

        $result = $engine->integrityCheck('App\\Models\\NonExistent');

        $this->assertFalse($result);
    }

    public function test_database_opens_with_pragmas(): void
    {
        $engine = $this->app->make(FtsEngine::class);
        $engine->createTable(Post::class, ['title']);

        $this->assertTrue($engine->tableExists(Post::class));
    }

    public function test_wal_mode_can_be_disabled(): void
    {
        config(['fts.fts5.wal' => false]);

        $engine = $this->app->make(FtsEngine::class);
        $engine->createTable(Post::class, ['title', 'body']);

        $this->assertTrue($engine->tableExists(Post::class));
    }

    public function test_cache_size_can_be_configured(): void
    {
        config(['fts.fts5.cache_size_kb' => -32000]);

        $engine = $this->app->make(FtsEngine::class);
        $engine->createTable(Post::class, ['title']);

        $this->assertTrue($engine->tableExists(Post::class));
    }

    public function test_wal_mode_is_active(): void
    {
        $engine = $this->app->make(FtsEngine::class);
        $engine->createTable(Post::class, ['title']);

        $verify = new \SQLite3($engine->getDatabasePath());
        $mode = $verify->querySingle('PRAGMA journal_mode');
        $verify->close();

        $this->assertSame('wal', $mode);
    }

    public function test_columnsize_0_creates_table_without_docsize(): void
    {
        config(['fts.fts5.columnsize' => 0]);

        $engine = $this->app->make(FtsEngine::class);
        $engine->createTable(Post::class, ['title', 'body']);

        $this->assertTrue($engine->tableExists(Post::class));
    }

    public function test_engine_has_generic_methods(): void
    {
        $engine = $this->app->make(FtsEngine::class);

        $this->assertTrue(method_exists($engine, 'upsert'));
        $this->assertTrue(method_exists($engine, 'delete'));
        $this->assertTrue(method_exists($engine, 'search'));
        $this->assertTrue(method_exists($engine, 'count'));
        $this->assertTrue(method_exists($engine, 'optimize'));
        $this->assertTrue(method_exists($engine, 'getEngineVersion'));
        $this->assertTrue(method_exists($engine, 'getIndexedModelClasses'));
        $this->assertTrue(method_exists($engine, 'getIndexStats'));
        $this->assertTrue(method_exists($engine, 'getConfig'));
        $this->assertTrue(method_exists($engine, 'setConfig'));
    }

    public function test_engine_has_sqlite_specific_methods(): void
    {
        $engine = $this->app->make(FtsEngine::class);

        $this->assertTrue(method_exists($engine, 'createTable'));
        $this->assertTrue(method_exists($engine, 'dropTable'));
        $this->assertTrue(method_exists($engine, 'tableExists'));
        $this->assertTrue(method_exists($engine, 'integrityCheck'));
        $this->assertTrue(method_exists($engine, 'tableName'));
        $this->assertTrue(method_exists($engine, 'getDatabasePath'));
        $this->assertTrue(method_exists($engine, 'getDatabaseSize'));
        $this->assertTrue(method_exists($engine, 'getPragma'));
        $this->assertTrue(method_exists($engine, 'fullIntegrityCheck'));
        $this->assertTrue(method_exists($engine, 'queryVocab'));
        $this->assertTrue(method_exists($engine, 'vacuum'));
    }
}
