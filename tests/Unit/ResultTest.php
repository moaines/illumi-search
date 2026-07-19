<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Moaines\IllumiSearch\Result;
use Moaines\IllumiSearch\Tests\TestCase;

class ResultTest extends TestCase
{
    public function test_creates_result_with_make(): void
    {
        $result = Result::make(
            modelClass: 'App\Models\Post',
            modelId: 42,
            rank: 0.5,
            title: 'Hello World',
            summary: 'A test post',
            raw: ['title' => 'Hello World', 'body' => 'Content'],
        );

        $this->assertEquals('App\Models\Post:42', $result->id);
        $this->assertEquals('App\Models\Post', $result->modelClass);
        $this->assertEquals(42, $result->modelId);
        $this->assertEquals(0.5, $result->rank);
        $this->assertEquals('Hello World', $result->title);
        $this->assertEquals('A test post', $result->summary);
        $this->assertTrue($result->authorized);
    }

    public function test_authorized_default_true(): void
    {
        $result = Result::make(
            modelClass: 'App\Models\Post',
            modelId: 1,
            rank: 0.0,
            title: 'Test',
        );

        $this->assertTrue($result->authorized);
    }

    public function test_authorized_can_be_false(): void
    {
        $result = new Result(
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
        $result = Result::make(
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
        $this->assertArrayHasKey('authorized', $array);
        $this->assertArrayHasKey('raw', $array);
        $this->assertArrayNotHasKey('model', $array);
        $this->assertArrayNotHasKey('url', $array);
        $this->assertArrayNotHasKey('icon', $array);
        $this->assertArrayNotHasKey('category', $array);
    }

    public function test_constructor_sets_correct_id(): void
    {
        $result = new Result(
            id: 'App\Models\Post:42',
            modelClass: 'App\Models\Post',
            modelId: 42,
            rank: 0.0,
            title: 'Test',
        );

        $this->assertEquals('App\Models\Post:42', $result->id);
    }

    public function test_model_attached_via_make(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test';
        };

        $result = Result::make(
            modelClass: 'App\Models\Post',
            modelId: 1,
            rank: 0.0,
            title: 'Test',
            model: $model,
        );

        $this->assertNotNull($result->model);
        $this->assertSame($model, $result->model);
    }

    public function test_model_provides_search_url(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test';

            public function searchUrl(): string
            {
                return '/custom-url';
            }
        };

        $result = Result::make(
            modelClass: get_class($model),
            modelId: 1,
            rank: 0.0,
            title: 'Test',
            model: $model,
        );

        $this->assertEquals('/custom-url', $result->model->searchUrl());
    }

    public function test_model_provides_search_category(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test';

            public function searchCategory(): ?string
            {
                return 'CustomCategory';
            }
        };

        $result = Result::make(
            modelClass: get_class($model),
            modelId: 1,
            rank: 0.0,
            title: 'Test',
            model: $model,
        );

        $this->assertEquals('CustomCategory', $result->model->searchCategory());
    }

    public function test_model_excluded_from_sleep(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test';
        };

        $result = Result::make(
            modelClass: 'App\Models\Post',
            modelId: 1,
            rank: 0.0,
            title: 'Test',
            model: $model,
        );

        $serialized = serialize($result);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(Result::class, $unserialized);
        $this->assertEquals('App\Models\Post', $unserialized->modelClass);
    }
}
