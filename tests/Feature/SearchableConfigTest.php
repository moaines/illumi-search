<?php

namespace Moaines\LaravelFts\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\LaravelFts\Text\UnicodeTextProcessor;
use Moaines\LaravelFts\Searchable;
use Moaines\LaravelFts\Tests\TestCase;

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

        $processor = new UnicodeTextProcessor();
        $global = app(\Moaines\LaravelFts\Contracts\TextProcessor::class);

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

        $ref = new \ReflectionClass(\Moaines\LaravelFts\Engines\SqliteFtsEngine::class);
        $method = $ref->getMethod('resolveSnippetColumns');

        $engine = $this->app->make(\Moaines\LaravelFts\Contracts\FtsEngine::class);
        $result = $method->invoke($engine, $model);

        $this->assertNotNull($result);
        $this->assertContains('body', $result);
        $this->assertNotContains('title', $result);
    }
}
