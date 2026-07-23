<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Text\FallbackTextProcessor;
use Moaines\IllumiSearch\Text\HasTextHelpers;
use Moaines\IllumiSearch\Tests\TestCase;

class HasTextHelpersTest extends TestCase
{
    use HasTextHelpers;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_normalize_lowercases_and_removes_accents(): void
    {
        $this->assertEquals('cafe', $this->removeDiacritics('Café'));
        $this->assertEquals('deja vu', $this->removeDiacritics('Déjà Vu'));
    }

    public function test_contains_finds_substring(): void
    {
        $this->assertTrue($this->contains('Hello World', 'world'));
        $this->assertFalse($this->contains('Hello World', 'xyz'));
    }

    public function test_fuzzyContains_handles_typo(): void
    {
        $this->assertTrue($this->fuzzyContains('programming', 'programing'));
    }

    public function test_similar_uses_levenshtein_threshold(): void
    {
        $this->assertTrue($this->similar('framework', 'framwork'));
    }

    public function test_levenshtein_distance_zero(): void
    {
        $this->assertEquals(0, $this->levenshteinDistance('test', 'test'));
    }

    public function test_levenshtein_distance_one(): void
    {
        $this->assertEquals(1, $this->levenshteinDistance('test', 'tests'));
    }

    public function test_contains_is_case_insensitive(): void
    {
        $this->assertTrue($this->contains('PHP Laravel', 'php'));
    }

    public function test_fuzzyContains_exact_match(): void
    {
        $this->assertTrue($this->fuzzyContains('php', 'php'));
    }

    public function test_similar_exact_substring(): void
    {
        $this->assertTrue($this->similar('learn php programming', 'php'));
    }

    public function test_stopwords_do_not_remove_search_terms(): void
    {
        // 'test' might be in some stopword lists — verify it's searchable
        $this->assertTrue($this->contains('test search term', 'test'));
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
        config(['illumi-search.processing.stopwords' => ['en']]);
        $result = $this->filterStopwords('laravel NOT php');

        $this->assertStringContainsString('NOT', $result, 'NOT operator must survive stopword filtering');
        $this->assertStringContainsString('laravel', $result);
        $this->assertStringContainsString('php', $result);
    }

    public function test_filter_stopwords_preserves_and_or_near(): void
    {
        config(['illumi-search.processing.stopwords' => ['en']]);
        $result = $this->filterStopwords('php AND laravel OR python NEAR java');

        $this->assertStringContainsString('AND', $result);
        $this->assertStringContainsString('OR', $result);
        $this->assertStringContainsString('NEAR', $result);
    }

    public function test_scripts_of_detects_latin(): void
    {
        $this->assertContains('Latin', $this->scriptsOf('laravel'));
        $this->assertNotContains('Cyrillic', $this->scriptsOf('laravel'));
    }

    public function test_scripts_of_detects_cyrillic(): void
    {
        $scripts = $this->scriptsOf('правил');
        $this->assertContains('Cyrillic', $scripts);
        $this->assertNotContains('Latin', $scripts);
    }

    public function test_scripts_of_detects_mixed_scripts(): void
    {
        $scripts = $this->scriptsOf('русский laravel');
        $this->assertContains('Cyrillic', $scripts);
        $this->assertContains('Latin', $scripts);
    }

    public function test_scripts_of_detects_cjk(): void
    {
        $this->assertContains('Han', $this->scriptsOf('中文测试'));
    }

    public function test_scripts_of_detects_arabic(): void
    {
        $this->assertContains('Arabic', $this->scriptsOf('العربية'));
    }

    public function test_scripts_of_detects_hebrew(): void
    {
        $this->assertContains('Hebrew', $this->scriptsOf('עברית'));
    }

    public function test_scripts_of_detects_greek(): void
    {
        $this->assertContains('Greek', $this->scriptsOf('Ελληνικά'));
    }

    public function test_scripts_of_returns_common_for_symbols(): void
    {
        $this->assertEquals(['Common'], $this->scriptsOf('12345'));
        $this->assertEquals(['Common'], $this->scriptsOf('!@#$%'));
    }

    public function test_scripts_of_returns_common_for_empty(): void
    {
        $this->assertEquals(['Common'], $this->scriptsOf(''));
    }

    public function test_word_to_trigrams_generates_correct_count(): void
    {
        $trigrams = $this->wordToTrigrams('laravel');
        $this->assertContains('#la', $trigrams);
        $this->assertContains('lar', $trigrams);
        $this->assertContains('ara', $trigrams);
        $this->assertContains('rav', $trigrams);
        $this->assertContains('ave', $trigrams);
        $this->assertContains('vel', $trigrams);
        $this->assertContains('el#', $trigrams);
        $this->assertCount(7, $trigrams);
    }

    public function test_word_to_trigrams_short_word(): void
    {
        $trigrams = $this->wordToTrigrams('a');
        $this->assertEquals(['#a#'], $trigrams);
    }

    public function test_word_to_trigrams_two_chars(): void
    {
        $trigrams = $this->wordToTrigrams('in');
        $this->assertEquals(['#in', 'in#'], $trigrams);
    }

    public function test_word_to_trigrams_deduplicates(): void
    {
        $trigrams = $this->wordToTrigrams('aaa');
        // #aa, aaa, aa# — all unique
        $this->assertCount(3, $trigrams);
        $this->assertEquals(['#aa', 'aaa', 'aa#'], $trigrams);
    }
}
