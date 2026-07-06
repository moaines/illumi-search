<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Moaines\LaravelFts\FtsResult;
use Moaines\LaravelFts\Tests\TestCase;

class FtsResultTest extends TestCase
{
    public function test_creates_result_with_make(): void
    {
        $result = FtsResult::make(
            modelClass: 'App\Models\Post',
            modelId: 42,
            rank: 0.5,
            title: 'Hello World',
            summary: 'A test post',
            url: '/posts/42',
            icon: 'heroicon-o-document',
            category: 'Blog Posts',
            raw: ['title' => 'Hello World', 'body' => 'Content'],
        );

        $this->assertEquals('App\Models\Post:42', $result->id);
        $this->assertEquals('App\Models\Post', $result->modelClass);
        $this->assertEquals(42, $result->modelId);
        $this->assertEquals(0.5, $result->rank);
        $this->assertEquals('Hello World', $result->title);
        $this->assertEquals('A test post', $result->summary);
        $this->assertEquals('/posts/42', $result->url);
        $this->assertEquals('heroicon-o-document', $result->icon);
        $this->assertEquals('Blog Posts', $result->category);
        $this->assertTrue($result->authorized);
    }

    public function test_authorized_default_true(): void
    {
        $result = FtsResult::make(
            modelClass: 'App\Models\Post',
            modelId: 1,
            rank: 0.0,
            title: 'Test',
        );

        $this->assertTrue($result->authorized);
    }

    public function test_authorized_can_be_false(): void
    {
        $result = new FtsResult(
            id: 'App\Models\Post:1',
            modelClass: 'App\Models\Post',
            modelId: 1,
            rank: 0.0,
            title: 'Test',
            authorized: false,
        );

        $this->assertFalse($result->authorized);
    }

    public function test_to_array_returns_expected_structure(): void
    {
        $result = FtsResult::make(
            modelClass: 'App\Models\Post',
            modelId: 1,
            rank: 0.8,
            title: 'Test',
        );

        $array = $result->toArray();

        $this->assertEquals('App\Models\Post:1', $array['id']);
        $this->assertEquals('App\Models\Post', $array['model_class']);
        $this->assertEquals(1, $array['model_id']);
        $this->assertEquals(0.8, $array['rank']);
        $this->assertEquals('Test', $array['title']);
        $this->assertArrayHasKey('summary', $array);
        $this->assertArrayHasKey('url', $array);
        $this->assertArrayHasKey('icon', $array);
        $this->assertArrayHasKey('category', $array);
        $this->assertArrayHasKey('authorized', $array);
        $this->assertArrayHasKey('raw', $array);
    }

    public function test_constructor_sets_correct_id(): void
    {
        $result = new FtsResult(
            id: 'App\Models\Post:42',
            modelClass: 'App\Models\Post',
            modelId: 42,
            rank: 0.0,
            title: 'Test',
        );

        $this->assertEquals('App\Models\Post:42', $result->id);
    }
}
