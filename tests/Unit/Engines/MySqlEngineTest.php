<?php

namespace Moaines\IllumiSearch\Tests\Unit\Engines;

use Moaines\IllumiSearch\Engines\MySqlEngine;
use Moaines\IllumiSearch\Tests\TestCase;

class MySqlEngineTest extends TestCase
{
    private MySqlEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new MySqlEngine;
    }

    // ─── Declarative traits (no live MySQL server) ─────────────────

    public function test_engine_specifics(): void
    {
        $this->assertFalse($this->engine->isFts5Available());
        $this->assertSame('illumi-search-mysql', $this->engine->getDatabasePath());
    }

    public function test_table_name_is_always_search_index(): void
    {
        $this->assertSame('illumi_search_index', $this->engine->tableName('App\Models\Post'));
        $this->assertSame('illumi_search_index', $this->engine->tableName('App\Models\Book'));
    }

    public function test_supports_phrase_and_prefix(): void
    {
        $this->assertTrue($this->engine->supportsPhraseSearch());
        $this->assertTrue($this->engine->supportsPrefixWildcard());
    }

    public function test_supported_operators_include_boolean_ops(): void
    {
        $operators = $this->engine->getSupportedOperators();
        $this->assertContains('AND', $operators);
        $this->assertContains('OR', $operators);
        $this->assertContains('NOT', $operators);
    }

    // ─── toBooleanMode() — MySQL BOOLEAN MODE translation ─────────

    private function toBooleanMode(string $query, string $mode = 'advanced'): string
    {
        $ref = new \ReflectionMethod(MySqlEngine::class, 'toBooleanMode');
        $ref->setAccessible(true);

        return $ref->invoke($this->engine, $query, $mode);
    }

    public function test_to_boolean_mode_simple_term_gets_wildcard(): void
    {
        $this->assertStringContainsString('laravel*', $this->toBooleanMode('laravel'));
    }

    public function test_to_boolean_mode_and_marks_both_terms_required(): void
    {
        $result = $this->toBooleanMode('php AND laravel');
        $this->assertStringContainsString('+php', $result);
        $this->assertStringContainsString('+laravel', $result);
    }

    public function test_to_boolean_mode_not_marks_negative(): void
    {
        $result = $this->toBooleanMode('php NOT java');
        $this->assertStringContainsString('php', $result);
        $this->assertStringContainsString('-java', $result);
    }

    public function test_to_boolean_mode_or_makes_both_optional(): void
    {
        $result = $this->toBooleanMode('php OR python');
        $this->assertStringNotContainsString('+', $result);
        $this->assertStringContainsString('php', $result);
        $this->assertStringContainsString('python', $result);
    }

    public function test_to_boolean_mode_preserves_phrase(): void
    {
        $result = $this->toBooleanMode('"software engineering"');
        $this->assertStringContainsString('"software engineering"', $result);
    }

    public function test_to_boolean_mode_raw_passthrough(): void
    {
        $result = $this->toBooleanMode('php AND "laravel*"', 'raw');
        $this->assertSame('php AND "laravel*"', $result);
    }

    public function test_to_boolean_mode_strips_sql_injection_chars(): void
    {
        $result = $this->toBooleanMode("'; DROP TABLE posts; --");
        $this->assertStringNotContainsString(';', $result);
        $this->assertStringNotContainsString('--', $result);
    }
}
