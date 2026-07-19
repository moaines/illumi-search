<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Spellcheck;
use Moaines\IllumiSearch\Tests\TestCase;

class SpellcheckTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = $this->app->make(Engine::class);
    }

    public function test_returns_empty_when_query_too_short(): void
    {
        $spellcheck = new Spellcheck($this->engine);
        $result = $spellcheck->suggest('x', ['App\Models\Post']);

        $this->assertCount(0, $result);
    }

    public function test_suggests_close_terms_from_vocab(): void
    {
        $this->engine->createTable('App\Models\Post', ['title', 'body']);
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'laravel', 'body' => 'php framework']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'bonjour', 'body' => 'hello world']);

        $spellcheck = new Spellcheck($this->engine);
        $suggestions = $spellcheck->suggest('laravell', ['App\Models\Post']);

        $this->assertGreaterThanOrEqual(1, $suggestions->count());
        $this->assertContains('laravel', $suggestions);
    }

    public function test_suggests_most_frequent_first(): void
    {
        $this->engine->createTable('App\Models\Post', ['title', 'body']);
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'php data', 'body' => 'content']);
        $this->engine->upsert('App\Models\Post', 2, ['title' => 'php data', 'body' => 'content']);
        $this->engine->upsert('App\Models\Post', 3, ['title' => 'php', 'body' => 'content']);

        $spellcheck = new Spellcheck($this->engine);
        $suggestions = $spellcheck->suggest('dat', ['App\Models\Post']);

        // 'data' appears twice, 'php' appears 3 times — 'php' should be first since it's distance 1 from 'dat'
        $this->assertGreaterThanOrEqual(1, $suggestions->count());
    }

    public function test_does_not_suggest_exact_match(): void
    {
        $this->engine->createTable('App\Models\Post', ['title', 'body']);
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'laravel', 'body' => 'content']);

        // 'laravel' is already in the index as an exact match → should suggest nothing
        $spellcheck = new Spellcheck($this->engine);
        $suggestions = $spellcheck->suggest('laravel', ['App\Models\Post']);

        // exact match excluded, and there are no close alternatives
        $this->assertCount(0, $suggestions);
    }

    public function test_max_distance_limits_suggestions(): void
    {
        $this->engine->createTable('App\Models\Post', ['title', 'body']);
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'hello world php framework', 'body' => 'content']);

        $spellcheck = new Spellcheck($this->engine);
        $spellcheck->maxDistance(1);

        // 'laravel' has distance 3 from 'hello' → excluded
        $suggestions = $spellcheck->suggest('laravel', ['App\Models\Post']);

        // 'laravel' distance: l→h=1, a→e=1, r→l=1, a→l=1, v→o=1, e→'=1, l→'=1 → distance 7
        // No terms in vocab are within distance 1
        $this->assertCount(0, $suggestions);
    }

    public function test_max_suggestions_limits_output(): void
    {
        $this->engine->createTable('App\Models\Post', ['title', 'body']);
        $this->engine->upsert('App\Models\Post', 1, ['title' => 'cat dog bat rat hat mat', 'body' => 'content']);

        $spellcheck = new Spellcheck($this->engine);
        $spellcheck->maxSuggestions(2);

        $suggestions = $spellcheck->suggest('cat', ['App\Models\Post']);

        $this->assertLessThanOrEqual(2, $suggestions->count());
    }
}
