<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Facades\Fts;
use Moaines\LaravelFts\FtsQueryBuilder;
use Moaines\LaravelFts\Tests\TestCase;

class FtsQueryBuilderTest extends TestCase
{
    private FtsEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = $this->app->make(FtsEngine::class);
    }

    public function test_basic_query(): void
    {
        $this->indexPost(1, 'hello world', 'test body');

        $results = Fts::query('hello')->model('App\Models\Post')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('hello world', $results[0]->title);
    }

    public function test_query_single_model(): void
    {
        $this->indexPost(1, 'hello post', 'body');
        $this->indexComment(1, 'hello comment', 'author');

        $results = Fts::query('hello')
            ->model('App\Models\Post')
            ->get();

        $this->assertCount(1, $results);
    }

    public function test_query_with_limit(): void
    {
        $this->indexPost(1, 'test a', '');
        $this->indexPost(2, 'test b', '');
        $this->indexPost(3, 'test c', '');

        $results = Fts::query('test')->model('App\Models\Post')->limit(2)->get();

        $this->assertCount(2, $results);
    }

    public function test_query_with_mode(): void
    {
        $this->indexPost(1, 'hello world', 'body');

        $results = Fts::query('hello')->model('App\Models\Post')->mode('basic')->get();

        $this->assertCount(1, $results);
    }

    public function test_query_returns_empty_for_no_query(): void
    {
        $results = Fts::query('')->get();
        $this->assertCount(0, $results);
    }

    public function test_query_builder_is_fluent(): void
    {
        $builder = Fts::query('test')
            ->model('App\Models\Post')
            ->mode('advanced')
            ->limit(5);

        $this->assertInstanceOf(FtsQueryBuilder::class, $builder);
    }

    public function test_paginate(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->indexPost($i, "test post {$i}", 'body');
        }

        $paginator = Fts::query('test')->model('App\Models\Post')->paginate(2);

        $this->assertEquals(5, $paginator->total());
        $this->assertCount(2, $paginator->items());
        $this->assertEquals(3, $paginator->lastPage());
    }

    public function test_count(): void
    {
        $this->indexPost(1, 'hello alpha', 'body');
        $this->indexPost(2, 'hello beta', 'body');

        $count = Fts::query('hello')->model('App\Models\Post')->count();

        $this->assertEquals(2, $count);
    }

    public function test_paginate_page_2(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->indexPost($i, "test post {$i}", 'body');
        }

        $page1 = Fts::query('test')->model('App\Models\Post')->paginate(2, page: 1);
        $page2 = Fts::query('test')->model('App\Models\Post')->paginate(2, page: 2);

        $this->assertCount(2, $page1->items());
        $this->assertCount(2, $page2->items());
        $this->assertEquals(1, $page1->firstItem());
        $this->assertEquals(3, $page2->firstItem());
    }

    public function test_engine_explicit_setter(): void
    {
        $this->indexPost(1, 'hello world', 'body');

        $builder = Fts::query('hello')->model('App\Models\Post');

        // engine is resolved lazily — setting it via the setter should work
        $this->assertInstanceOf(\Moaines\LaravelFts\FtsQueryBuilder::class, $builder->engine($this->engine));
    }

    private function indexPost(int $id, string $title, string $body): void
    {
        if (! $this->engine->tableExists('App\Models\Post')) {
            $this->engine->createTable('App\Models\Post', ['title', 'body']);
        }
        $this->engine->upsert('App\Models\Post', $id, ['title' => $title, 'body' => $body]);
    }

    private function indexComment(int $id, string $content, string $author): void
    {
        if (! $this->engine->tableExists('App\Models\Comment')) {
            $this->engine->createTable('App\Models\Comment', ['content', 'author']);
        }
        $this->engine->upsert('App\Models\Comment', $id, ['content' => $content, 'author' => $author]);
    }
}
