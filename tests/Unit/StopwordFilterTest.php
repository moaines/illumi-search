<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Stopwords\StopwordFilter;
use PHPUnit\Framework\TestCase;

class StopwordFilterTest extends TestCase
{
    private StopwordFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new StopwordFilter;
    }

    public function test_filters_english_stopwords(): void
    {
        $result = $this->filter->filter('the quick brown fox jumps over the lazy dog', 'en');
        $this->assertEquals('quick brown fox jumps lazy dog', $result);
    }

    public function test_filters_french_stopwords(): void
    {
        $result = $this->filter->filter('le chat et la souris dans le jardin', 'fr');
        $this->assertEquals('chat souris jardin', $result);
    }

    public function test_returns_original_when_no_stopwords_configured(): void
    {
        $result = $this->filter->filter('some random text', 'xx');
        $this->assertEquals('some random text', $result);
    }

    public function test_handles_empty_text(): void
    {
        $result = $this->filter->filter('', 'en');
        $this->assertEmpty($result);
    }

    public function test_handles_text_with_only_stopwords(): void
    {
        $result = $this->filter->filter('the the the and the', 'en');
        $this->assertEmpty($result);
    }

    public function test_arabic_stopwords_exist(): void
    {
        $words = $this->filter->load('ar');
        $this->assertNotEmpty($words);
        $this->assertContains('في', $words);
    }

    public function test_chinese_stopwords_exist(): void
    {
        $words = $this->filter->load('zh');
        $this->assertNotEmpty($words);
    }

    public function test_russian_stopwords_exist(): void
    {
        $words = $this->filter->load('ru');
        $this->assertNotEmpty($words);
    }
}
