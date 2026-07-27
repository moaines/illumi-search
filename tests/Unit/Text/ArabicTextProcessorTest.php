<?php

namespace Moaines\IllumiSearch\Tests\Unit\Text;

use Moaines\IllumiSearch\Text\ArabicTextProcessor;
use PHPUnit\Framework\TestCase;

class ArabicTextProcessorTest extends TestCase
{
    private ArabicTextProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new ArabicTextProcessor;
    }

    public function test_normalizes_tashkeel(): void
    {
        $result = $this->processor->process('بِسْمِ');
        $this->assertSame('بسم', $result, 'Tashkeel should be removed');
    }

    public function test_normalizes_hamza(): void
    {
        $result = $this->processor->process('إسلام آدم');
        $this->assertSame('اسلام ادم', $result, 'إ and آ should become ا');
    }

    public function test_normalizes_taa_marbuta(): void
    {
        $result = $this->processor->process('مدرسة');
        // ة → ه (normalization), then ه removed as suffix → مدرس
        $this->assertStringNotContainsString('ة', $result, 'Taa marbuta should be normalized');
        $this->assertSame('مدرس', $result, 'ة → ه → removed (stem = مدرس)');
    }

    public function test_removes_prefix_al(): void
    {
        $result = $this->processor->process('السلام');
        $this->assertSame('سلام', $result, 'ال prefix should be removed');
    }

    public function test_removes_suffix_plural(): void
    {
        $result = $this->processor->process('برمجيات');
        $this->assertSame('برمج', $result, 'ات suffix should be removed');
    }

    public function test_removes_prefix_waw(): void
    {
        // و is no longer in the prefix list (too aggressive).
        // وبرمجيات → should still remove ات suffix → برمجي
        $result = $this->processor->process('وبرمجيات');
        // و is not a prefix (too aggressive), ات and ي suffixes removed
        $this->assertSame('وبرمج', $result, 'و prefix kept, ات and ي suffixes removed');
    }

    public function test_stems_arabic_word_for_searching(): void
    {
        // Both the indexed word and the search term should reduce to the same root
        $indexed = $this->processor->process('برمجيات');
        $query = $this->processor->process('برمج');

        $this->assertSame($query, $indexed, 'برمجيات should stem to same root as برمج');
    }

    public function test_does_not_affect_latin_text(): void
    {
        $result = $this->processor->process('php laravel framework');
        $this->assertSame('php laravel framework', $result, 'Latin text should not be modified');
    }

    public function test_handles_mixed_text(): void
    {
        $result = $this->processor->process('php برمجيات framework');
        $this->assertStringContainsString('php', $result, 'Latin words should survive');
        $this->assertStringContainsString('برمج', $result, 'Arabic word should be stemmed');
        $this->assertStringContainsString('framework', $result, 'Latin words should survive');
    }
}
