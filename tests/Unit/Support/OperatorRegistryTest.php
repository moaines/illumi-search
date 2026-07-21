<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Support\OperatorRegistry;
use PHPUnit\Framework\TestCase;

class OperatorRegistryTest extends TestCase
{
    public function test_tokenize_simple_terms(): void
    {
        $this->assertEquals(['php', 'laravel'], OperatorRegistry::tokenize('php laravel'));
    }

    public function test_tokenize_phrase(): void
    {
        $this->assertEquals(['"php moderne"', 'framework'], OperatorRegistry::tokenize('"php moderne" framework'));
    }

    public function test_tokenize_mixed_operators(): void
    {
        $this->assertEquals(
            ['php', 'AND', 'laravel'],
            OperatorRegistry::tokenize('php AND laravel'),
        );
    }

    public function test_is_operator_returns_true_for_and_or_not_near(): void
    {
        $this->assertTrue(OperatorRegistry::isOperator('AND'));
        $this->assertTrue(OperatorRegistry::isOperator('OR'));
        $this->assertTrue(OperatorRegistry::isOperator('NOT'));
        $this->assertTrue(OperatorRegistry::isOperator('NEAR'));
        $this->assertTrue(OperatorRegistry::isOperator('and'));
        $this->assertTrue(OperatorRegistry::isOperator('not'));
    }

    public function test_is_operator_returns_false_for_regular_words(): void
    {
        $this->assertFalse(OperatorRegistry::isOperator('laravel'));
        $this->assertFalse(OperatorRegistry::isOperator('php'));
        $this->assertFalse(OperatorRegistry::isOperator('framework'));
    }

    public function test_mask_operators_protects_keywords(): void
    {
        [$masked, $replacements] = OperatorRegistry::maskOperators('laravel NOT php');
        $this->assertStringNotContainsString('NOT', $masked);
        $this->assertStringContainsString('laravel', $masked);
        $this->assertStringContainsString('php', $masked);
        $this->assertNotEmpty($replacements);
    }

    public function test_mask_operators_uses_safe_placeholders(): void
    {
        [$masked, $replacements] = OperatorRegistry::maskOperators('php AND laravel');
        $keys = array_keys($replacements);
        $this->assertStringNotContainsString("\x00", $keys[0], 'No null bytes in placeholders');
        $this->assertStringStartsWith('__OP', $keys[0]);
        $this->assertStringEndsWith('__', $keys[0]);
    }

    public function test_unmask_operators_restores_keywords(): void
    {
        [$masked, $replacements] = OperatorRegistry::maskOperators('php AND laravel OR python');
        $restored = OperatorRegistry::unmaskOperators($masked, $replacements);
        $this->assertStringContainsString('AND', $restored);
        $this->assertStringContainsString('OR', $restored);
        $this->assertEquals('php AND laravel OR python', $restored);
    }

    public function test_mask_unmask_roundtrip_preserves_original(): void
    {
        $query = 'data NOT python AND "exact phrase" OR near test NEAR php';
        [$masked, $replacements] = OperatorRegistry::maskOperators($query);
        $restored = OperatorRegistry::unmaskOperators($masked, $replacements);
        $this->assertEquals($query, $restored);
    }
}
