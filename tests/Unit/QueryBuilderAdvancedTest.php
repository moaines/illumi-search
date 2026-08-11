<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Facades\IllumiSearch;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;

class QueryBuilderAdvancedTest extends TestCase
{
    private Engine $engine;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('app/illumi-search-qb-adv.sqlite');
        @unlink($this->path);

        config(['illumi-search.engine' => 'sqlite']);
        config(['illumi-search.engines.sqlite.database_path' => $this->path]);

        $this->engine = new SqliteEngine(databasePath: $this->path);
        $this->app->singleton(Engine::class, fn () => $this->engine);

        $this->engine->createTable(Post::class, ['title', 'body']);

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->integer('category')->nullable();
            $table->decimal('price', 8, 2)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        $this->seedPosts();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('posts');
        @unlink($this->path);
        parent::tearDown();
    }

    private function seedPosts(): void
    {
        $docs = [
            ['title' => 'php laravel guide', 'body' => 'learn php', 'category' => 1, 'price' => 10.00, 'published_at' => now()],
            ['title' => 'php advanced', 'body' => 'php deep', 'category' => 1, 'price' => 25.00, 'published_at' => now()->subDays(5)],
            ['title' => 'python for beginners', 'body' => 'learn py', 'category' => 2, 'price' => 15.00, 'published_at' => now()->subDays(40)],
            ['title' => 'javascript basics', 'body' => 'learn js', 'category' => 2, 'price' => 5.00, 'published_at' => now()->subDays(40)],
        ];

        foreach ($docs as $i => $doc) {
            $model = Post::create([
                'title' => $doc['title'],
                'body' => $doc['body'],
                'category' => $doc['category'],
                'price' => $doc['price'],
                'published_at' => $doc['published_at'],
            ]);
            $this->engine->upsert(Post::class, $model->id, ['title' => $doc['title'], 'body' => $doc['body']]);
        }
    }

    // ─── where() ────────────────────────────────────

    public function test_where_filters_by_equality(): void
    {
        $results = IllumiSearch::query('learn')
            ->model(Post::class)
            ->where('category', 1)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('php laravel guide', $results[0]->title);
    }

    public function test_where_not_equal_filters_out(): void
    {
        $results = IllumiSearch::query('learn')
            ->model(Post::class)
            ->where('category', '!=', 1)
            ->get();

        $titles = collect($results)->pluck('title')->all();
        $this->assertContains('python for beginners', $titles);
        $this->assertContains('javascript basics', $titles);
        $this->assertNotContains('php laravel guide', $titles);
    }

    public function test_where_greater_than_filters(): void
    {
        $results = IllumiSearch::query('learn')
            ->model(Post::class)
            ->where('price', '>', 10)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('python for beginners', $results[0]->title);
    }

    public function test_where_between_filters(): void
    {
        $results = IllumiSearch::query('learn')
            ->model(Post::class)
            ->whereBetween('price', [12, 16])
            ->get();

        // price 15 (python) in range, price 10 (php) and 5 (js) outside.
        $titles = collect($results)->pluck('title')->all();
        $this->assertContains('python for beginners', $titles);
        $this->assertNotContains('php laravel guide', $titles);
        $this->assertNotContains('javascript basics', $titles);
    }

    public function test_where_in_filters(): void
    {
        $results = IllumiSearch::query('learn')
            ->model(Post::class)
            ->whereIn('category', [2])
            ->get();

        $titles = collect($results)->pluck('title')->all();
        $this->assertContains('python for beginners', $titles);
        $this->assertContains('javascript basics', $titles);
        $this->assertNotContains('php laravel guide', $titles);
    }

    public function test_where_null_filters(): void
    {
        $results = IllumiSearch::query('learn')
            ->model(Post::class)
            ->whereNull('published_at')
            ->get();

        $this->assertCount(0, $results, 'all posts have published_at set');
    }

    // ─── aggregate() ────────────────────────────────

    public function test_aggregate_counts_by_column(): void
    {
        $result = IllumiSearch::query('learn')
            ->model(Post::class)
            ->aggregate('category');

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        // 2 posts in category 1 ('php laravel guide' matches 'learn'), 0 in category 2.
        $this->assertEquals(1, $result->get(1));
    }

    public function test_aggregate_counts_all_matching_groups(): void
    {
        $result = IllumiSearch::query('php')
            ->model(Post::class)
            ->aggregate('category');

        // 'php' matches 'php laravel guide' + 'php advanced' → both category 1.
        $this->assertEquals(2, $result->get(1));
        $this->assertNull($result->get(2));
    }

    // ─── boost() ────────────────────────────────────

    public function test_boost_raises_recent_rank(): void
    {
        // The boost multiplies the base score; a rare term gives non-zero base
        // ranks so the effect is observable.
        $boosted = IllumiSearch::query('deep')
            ->model(Post::class)
            ->boost('published_at', 1.0)
            ->get();

        // 'php advanced' (published 5 days ago) matches 'deep' exactly and must
        // rank above a same-score result when boost is applied.
        $titles = collect($boosted)->pluck('title')->all();
        $this->assertContains('php advanced', $titles);
        $this->assertSame('php advanced', $titles[0]);
    }

    public function test_boost_increases_rank_of_recent_result(): void
    {
        $plain = IllumiSearch::query('deep')
            ->model(Post::class)
            ->get()
            ->firstWhere('title', 'php advanced');

        $boosted = IllumiSearch::query('deep')
            ->model(Post::class)
            ->boost('published_at', 1.0)
            ->get()
            ->firstWhere('title', 'php advanced');

        $this->assertNotNull($plain, 'base search should find php advanced');
        $this->assertNotNull($boosted, 'boosted search should find php advanced');
        $this->assertGreaterThan($plain->rank, $boosted->rank, 'recent result rank should increase with boost');
    }

    public function test_boost_with_zero_factor_keeps_order(): void
    {
        $plain = IllumiSearch::query('learn')
            ->model(Post::class)
            ->get();

        $zeroBoost = IllumiSearch::query('learn')
            ->model(Post::class)
            ->boost('published_at', 0.0)
            ->get();

        $plainTitles = collect($plain)->pluck('title')->all();
        $zeroTitles = collect($zeroBoost)->pluck('title')->all();

        $this->assertSame($plainTitles, $zeroTitles, 'boost factor 0 must not change ranking');
    }
}
