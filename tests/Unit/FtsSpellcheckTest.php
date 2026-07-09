<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\FtsSpellcheck;
use Moaines\LaravelFts\Tests\TestCase;

class FtsSpellcheckTest extends TestCase
{
    private FtsEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = $this->createMock(FtsEngine::class);
    }

    public function test_short_query_returns_empty(): void
    {
        $spellcheck = new FtsSpellcheck($this->engine);

        $this->assertTrue($spellcheck->suggest('a')->isEmpty());
    }

    public function test_empty_query_returns_empty(): void
    {
        $spellcheck = new FtsSpellcheck($this->engine);

        $this->assertTrue($spellcheck->suggest('')->isEmpty());
    }

    public function test_max_distance_clamps_between_1_and_5(): void
    {
        $spellcheck = new FtsSpellcheck($this->engine);
        $spellcheck->maxDistance(10);

        $ref = new \ReflectionClass($spellcheck);
        $prop = $ref->getProperty('maxDistance');
        $prop->setAccessible(true);

        $this->assertEquals(5, $prop->getValue($spellcheck));
    }

    public function test_max_distance_floor_at_1(): void
    {
        $spellcheck = new FtsSpellcheck($this->engine);
        $spellcheck->maxDistance(0);

        $ref = new \ReflectionClass($spellcheck);
        $prop = $ref->getProperty('maxDistance');
        $prop->setAccessible(true);

        $this->assertEquals(1, $prop->getValue($spellcheck));
    }

    public function test_max_suggestions_clamps_between_1_and_20(): void
    {
        $spellcheck = new FtsSpellcheck($this->engine);
        $spellcheck->maxSuggestions(50);

        $ref = new \ReflectionClass($spellcheck);
        $prop = $ref->getProperty('maxSuggestions');
        $prop->setAccessible(true);

        $this->assertEquals(20, $prop->getValue($spellcheck));
    }

    public function test_suggest_returns_unique_terms(): void
    {
        $this->engine->method('getIndexedModelClasses')->willReturn([]);

        $spellcheck = new FtsSpellcheck($this->engine);

        $result = $spellcheck->suggest('hello world');

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }
}
