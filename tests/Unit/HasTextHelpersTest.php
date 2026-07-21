<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Text\FallbackTextProcessor;
use Moaines\IllumiSearch\Tests\TestCase;

class HasTextHelpersTest extends TestCase
{
    private FallbackTextProcessor $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new FallbackTextProcessor;
    }

    public function test_normalize_lowercases_and_removes_accents(): void
    {
        $this->assertEquals('cafe', $this->helper->normalize('Café'));
        $this->assertEquals('deja vu', $this->helper->normalize('Déjà Vu'));
    }

    public function test_contains_finds_substring(): void
    {
        $this->assertTrue($this->helper->contains('Hello World', 'world'));
        $this->assertFalse($this->helper->contains('Hello World', 'xyz'));
    }

    public function test_fuzzyContains_handles_typo(): void
    {
        $this->assertTrue($this->helper->fuzzyContains('programming', 'programing'));
    }

    public function test_similar_uses_levenshtein_threshold(): void
    {
        $this->assertTrue($this->helper->similar('framework', 'framwork'));
    }

    public function test_levenshtein_distance_zero(): void
    {
        $this->assertEquals(0, $this->helper->levenshteinDistance('test', 'test'));
    }

    public function test_levenshtein_distance_one(): void
    {
        $this->assertEquals(1, $this->helper->levenshteinDistance('test', 'tests'));
    }

    public function test_contains_is_case_insensitive(): void
    {
        $this->assertTrue($this->helper->contains('PHP Laravel', 'php'));
    }

    public function test_fuzzyContains_exact_match(): void
    {
        $this->assertTrue($this->helper->fuzzyContains('php', 'php'));
    }

    public function test_similar_exact_substring(): void
    {
        $this->assertTrue($this->helper->similar('learn php programming', 'php'));
    }

    public function test_stopwords_do_not_remove_search_terms(): void
    {
        // 'test' might be in some stopword lists — verify it's searchable
        $this->assertTrue($this->helper->contains('test search term', 'test'));
    }

    public function test_fallback_processor_preserves_cjk(): void
    {
        $processor = new \Moaines\IllumiSearch\Text\FallbackTextProcessor;
        $result = $processor->process('中文测试');

        $this->assertNotEmpty($result, 'Fallback processor must preserve CJK');
        $this->assertStringContainsString(' ', $result, 'CJK should be space-separated');
    }

    public function test_unicode_processor_preserves_cjk(): void
    {
        $processor = new \Moaines\IllumiSearch\Text\UnicodeTextProcessor;
        $result = $processor->process('中文测试');

        $this->assertNotEmpty($result, 'Unicode processor must preserve CJK');
        $this->assertStringContainsString(' ', $result, 'CJK should be space-separated');
    }

    public function test_filter_stopwords_preserves_not_operator(): void
    {
        config(['illumi-search.stopwords' => ['en']]);
        $result = $this->helper->filterStopwords('laravel NOT php');

        $this->assertStringContainsString('NOT', $result, 'NOT operator must survive stopword filtering');
        $this->assertStringContainsString('laravel', $result);
        $this->assertStringContainsString('php', $result);
    }

    public function test_filter_stopwords_preserves_and_or_near(): void
    {
        config(['illumi-search.stopwords' => ['en']]);
        $result = $this->helper->filterStopwords('php AND laravel OR python NEAR java');

        $this->assertStringContainsString('AND', $result);
        $this->assertStringContainsString('OR', $result);
        $this->assertStringContainsString('NEAR', $result);
    }
}
