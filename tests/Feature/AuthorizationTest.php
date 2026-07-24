<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Facades\IllumiSearch;
use Moaines\IllumiSearch\QueryBuilder;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;

class AuthorizationTest extends TestCase
{
    private Engine $engine;

    private const MODEL_CLASS = Post::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the Eloquent posts table for model lookup
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        $this->engine = $this->app->make(Engine::class);
        $this->engine->createTable(self::MODEL_CLASS, ['title', 'body']);
    }

    private function createPost(int $id, string $title, string $body): void
    {
        Post::forceCreate([
            'id' => $id,
            'title' => $title,
            'body' => $body,
        ]);
        $this->engine->upsert(self::MODEL_CLASS, $id, ['title' => $title, 'body' => $body]);
    }

    public function test_without_authorization_returns_all_results(): void
    {
        $this->createPost(1, 'public post', 'content');
        $this->createPost(2, 'private post', 'content');

        $results = $this->app->make(QueryBuilder::class)
            ->query('post')
            ->models([self::MODEL_CLASS])
            ->get();

        $this->assertCount(2, $results);
    }

    public function test_authorization_filters_results_by_policy(): void
    {
        $this->createPost(1, 'public post', 'viewable');
        $this->createPost(2, 'private post', 'hidden');

        Gate::define('view', function ($user, $post) {
            return $post->getKey() === 1;
        });

        $user = new User;
        $user->id = 1;

        $results = $this->app->make(QueryBuilder::class)
            ->query('post')
            ->models([self::MODEL_CLASS])
            ->withAuthorization($user)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results->first()->modelId);
    }

    public function test_authorization_can_remove_all_results(): void
    {
        $this->createPost(1, 'secret post', 'classified');
        $this->createPost(2, 'top secret', 'classified');

        Gate::define('view', function ($user, $post) {
            return false;
        });

        $user = new User;
        $user->id = 1;

        $results = $this->app->make(QueryBuilder::class)
            ->query('post')
            ->models([self::MODEL_CLASS])
            ->withAuthorization($user)
            ->get();

        $this->assertCount(0, $results);
    }

    public function test_authorization_ignored_when_no_user(): void
    {
        $this->createPost(1, 'test post', 'content');

        $results = $this->app->make(QueryBuilder::class)
            ->query('test')
            ->models([self::MODEL_CLASS])
            ->withAuthorization()
            ->get();

        $this->assertCount(1, $results);
    }

    public function test_policy_called_for_each_result(): void
    {
        $this->createPost(1, 'post a', 'content');
        $this->createPost(2, 'post b', 'content');
        $this->createPost(3, 'post c', 'content');

        $allowedIds = [1, 3];

        Gate::define('view', function ($user, $post) use ($allowedIds) {
            return in_array($post->getKey(), $allowedIds, true);
        });

        $user = new User;
        $user->id = 1;

        $results = $this->app->make(QueryBuilder::class)
            ->query('post')
            ->models([self::MODEL_CLASS])
            ->withAuthorization($user)
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals(1, $results[0]->modelId);
        $this->assertEquals(3, $results[1]->modelId);
    }

    public function test_authorization_via_illumi_search_facade(): void
    {
        $this->createPost(1, 'visible post', 'public content');
        $this->createPost(2, 'hidden post', 'classified');

        Gate::define('view', function ($user, $post) {
            return $post->getKey() === 1;
        });

        $user = new User;
        $user->id = 1;

        $results = IllumiSearch::query('post')
            ->models([self::MODEL_CLASS])
            ->withAuthorization($user)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results->first()->modelId);
    }

    public function test_authorization_with_non_sequential_ids(): void
    {
        $this->createPost(1, 'post visible', 'public');
        $this->createPost(3, 'post hidden', 'secret'); // ID 2 skippé

        Gate::define('view', fn ($user, $post) => $post->getKey() === 1);

        $user = new User;
        $user->id = 1;

        $results = IllumiSearch::query('post')
            ->models([self::MODEL_CLASS])
            ->withAuthorization($user)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results->first()->modelId);
    }
}
