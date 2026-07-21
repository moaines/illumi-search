<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Text\FallbackTextProcessor;
use PHPUnit\Framework\TestCase;

class FallbackTextProcessorTest extends TestCase
{
    private FallbackTextProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new FallbackTextProcessor;
    }

    public function test_removes_french_accents(): void
    {
        $this->assertEquals('cafe', $this->processor->removeDiacritics('café'));
        $this->assertEquals('deja vu', $this->processor->removeDiacritics('déjà vu'));
        $this->assertEquals('etranger', $this->processor->removeDiacritics('étranger'));
    }

    public function test_removes_spanish_accents(): void
    {
        $this->assertEquals('n', $this->processor->removeDiacritics('ñ'));
        $this->assertEquals('ole', $this->processor->removeDiacritics('olé'));
    }

    public function test_removes_german_umlauts(): void
    {
        $this->assertEquals('uber', $this->processor->removeDiacritics('über'));
        $this->assertEquals('Munchen', $this->processor->removeDiacritics('München'));
    }

    public function test_process_lowercases_text(): void
    {
        $result = $this->processor->process('HELLO WORLD');
        $this->assertEquals('hello world', $result);
    }

    public function test_process_strips_html(): void
    {
        $result = $this->processor->process('<p>Hello <b>world</b></p>');
        $this->assertEquals('hello world', $result);
    }

    public function test_process_removes_accents_and_lowercases(): void
    {
        $result = $this->processor->process('Café Déjà Vu');
        $this->assertEquals('cafe deja vu', $result);
    }

    public function test_process_handles_empty_string(): void
    {
        $this->assertEmpty($this->processor->process(''));
    }

    public function test_process_separates_cjk_characters(): void
    {
        $result = $this->processor->process('中文测试');
        $this->assertStringContainsString(' ', $result);
    }

    public function test_process_handles_cjk_mixed_with_latin(): void
    {
        $result = $this->processor->process('中文 test');
        $this->assertStringContainsString(' ', $result);
    }

    public function test_process_cleans_whitespace(): void
    {
        $result = $this->processor->process("hello   world\n\nnew line");
        $this->assertEquals('hello world new line', $result);
    }

    public function test_arabic_tashkeel_are_removed(): void
    {
        $withTashkeel = "السَّلَامُ عَلَيْكُمْ";
        $result = $this->processor->removeDiacritics($withTashkeel);
        $this->assertStringNotContainsString("\xD9\x8E", $result, 'Fatha should be removed');
        $this->assertStringNotContainsString("\xD9\x8F", $result, 'Damma should be removed');
        $this->assertStringNotContainsString("\xD9\x91", $result, 'Shadda should be removed');
    }

    public function test_cyrillic_is_transliterated(): void
    {
        $result = $this->processor->removeDiacritics('Привет мир');
        $this->assertStringContainsString('Privet', $result, 'Cyrillic При should transliterate to Pr');
    }

    public function test_clean_whitespace_collapses_multiple_spaces(): void
    {
        $this->assertEquals('hello world', $this->processor->cleanWhitespace('hello   world'));
    }

    public function test_strip_html_removes_tags(): void
    {
        $this->assertEquals('hello', $this->processor->stripHtml('<b>hello</b>'));
    }
}
