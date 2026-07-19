<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Spellcheck;
use Moaines\IllumiSearch\Tests\TestCase;

class SpellcheckTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = $this->createMock(Engine::class);
    }

    public function test_short_query_returns_empty(): void
    {
        $spellcheck = new Spellcheck($this->engine);

        $this->assertTrue($spellcheck->suggest('a')->isEmpty());
    }

    public function test_empty_query_returns_empty(): void
    {
        $spellcheck = new Spellcheck($this->engine);

        $this->assertTrue($spellcheck->suggest('')->isEmpty());
    }

    public function test_max_distance_clamps_between_1_and_5(): void
    {
        $spellcheck = new Spellcheck($this->engine);
        $spellcheck->maxDistance(10);

        $ref = new \ReflectionClass($spellcheck);
        $prop = $ref->getProperty('maxDistance');
        $prop->setAccessible(true);

        $this->assertEquals(5, $prop->getValue($spellcheck));
    }

    public function test_max_distance_floor_at_1(): void
    {
        $spellcheck = new Spellcheck($this->engine);
        $spellcheck->maxDistance(0);

        $ref = new \ReflectionClass($spellcheck);
        $prop = $ref->getProperty('maxDistance');
        $prop->setAccessible(true);

        $this->assertEquals(1, $prop->getValue($spellcheck));
    }

    public function test_max_suggestions_clamps_between_1_and_20(): void
    {
        $spellcheck = new Spellcheck($this->engine);
        $spellcheck->maxSuggestions(50);

        $ref = new \ReflectionClass($spellcheck);
        $prop = $ref->getProperty('maxSuggestions');
        $prop->setAccessible(true);

        $this->assertEquals(20, $prop->getValue($spellcheck));
    }

    public function test_suggest_returns_unique_terms(): void
    {
        $this->engine->method('getIndexedModelClasses')->willReturn([]);

        $spellcheck = new Spellcheck($this->engine);

        $result = $spellcheck->suggest('hello world');

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }
}
