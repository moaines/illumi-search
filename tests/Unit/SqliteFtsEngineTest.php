<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Tests\TestCase;

class SqliteFtsEngineTest extends TestCase
{
    private FtsEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = $this->app->make(FtsEngine::class);

        $this->engine->createTable('App\Models\Post', ['title', 'body']);
    }

    public function test_creates_table(): void
    {
        $this->assertTrue($this->engine->tableExists('App\Models\Post'));
    }

    public function test_drops_table(): void
    {
        $this->engine->dropTable('App\Models\Post');
        $this->assertFalse($this->engine->tableExists('App\Models\Post'));
    }

    public function test_upsert_and_search(): void
    {
        $this->engine->upsert('App\Models\Post', 1, [
            'title' => 'hello world',
            'body' => 'this is a test post',
        ]);

        $results = $this->engine->search('hello', ['App\Models\Post'], 10);

        $this->assertCount(1, $results);
        $this->assertEquals('hello world', $results[0]->title);
        $this->assertEquals('App\Models\Post', $results[0]->modelClass);
        $this->assertEquals(1, $results[0]->modelId);
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $this->engine->upsert('App\Models\Post', 1, [
            'title' => 'hello world',
            'body' => 'test content',
        ]);

        $results = $this->engine->search('nonexistent', ['App\Models\Post'], 10);
        $this->assertCount(0, $results);
    }

    public function test_search_returns_multiple_results(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'first post', 'body' => 'content one']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'second post', 'body' => 'content two']);

        $results = $this->engine->search('post', ['App\Models\Post'], 10);
        $this->assertCount(2, $results);
    }

    public function test_count_returns_correct_number(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'hello alpha', 'body' => 'test']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'hello beta', 'body' => 'test']);

        $count = $this->engine->count('hello', ['App\Models\Post']);
        $this->assertEquals(2, $count);
    }

    public function test_delete_removes_from_index(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'hello', 'body' => 'world']);
        $this->engine->delete('App\Models\Post', 1);

        $results = $this->engine->search('hello', ['App\Models\Post'], 10);
        $this->assertCount(0, $results);
    }

    public function test_insert_batch(): void
    {
        $documents = [
            ['model_id' => 1, 'document' => ['title' => 'batch one', 'body' => 'content']],
            ['model_id' => 2, 'document' => ['title' => 'batch two', 'body' => 'content']],
        ];

        $this->engine->insertBatch('App\Models\Post', $documents);

        $results = $this->engine->search('batch', ['App\Models\Post'], 10);
        $this->assertCount(2, $results);
    }

    public function test_search_multiple_models(): void
    {
        $this->engine->createTable('App\Models\Comment', ['content', 'author']);

        $this->engine->upsert('App\Models\Post', 1, ['title' => 'php programming', 'body' => 'great']);
        $this->engine->upsert('App\Models\Comment', 1, ['content' => 'nice post about php', 'author' => 'jane']);

        $results = $this->engine->search('php', ['App\Models\Post', 'App\Models\Comment'], 10);
        $this->assertCount(2, $results);
    }

    public function test_respects_limit(): void
    {
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'test a', 'body' => '']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'test b', 'body' => '']);
        $this->engine->upsert('App\Models\Post', 3, ['title' => 'test c', 'body' => '']);

        $results = $this->engine->search('test', ['App\Models\Post'], 2);
        $this->assertCount(2, $results);
    }
}
