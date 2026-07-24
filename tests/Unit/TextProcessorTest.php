<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Text\StemmingTextProcessor;

class TextProcessorTest extends TestCase
{
    private TextProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = $this->app->make(TextProcessor::class);
    }

    public function test_removes_accents(): void
    {
        $this->assertEquals('cafe', $this->processor->process('café'));
        $this->assertEquals('a e i o u', $this->processor->process('à é î ô ù'));
        $this->assertEquals('facade', $this->processor->process('façade'));
    }

    public function test_lowercases_text(): void
    {
        $this->assertEquals('hello world', $this->processor->process('Hello World'));
        $this->assertEquals('bonjour', $this->processor->process('BONJOUR'));
    }

    public function test_handles_mixed_content(): void
    {
        $result = $this->processor->process('L\'élégance du français');
        $this->assertStringContainsString('elegance', $result);
        $this->assertStringContainsString('francais', $result);
    }

    public function test_handles_empty_string(): void
    {
        $this->assertEquals('', $this->processor->process(''));
    }

    public function test_handles_unicode_normalization(): void
    {
        // NFD form
        $nfd = "e\u{0301}"; // e + combining acute
        $result = $this->processor->process($nfd);
        $this->assertEquals('e', $result);
    }

    public function test_strips_html(): void
    {
        $processor = $this->processor;
        $text = $processor->process('<p>Hello <b>World</b></p>');
        $this->assertEquals('hello world', $text);
    }

    public function test_clean_whitespace(): void
    {
        $result = $this->processor->process("Hello    World\n\nTest");
        $this->assertEquals('hello world test', $result);
    }

    public function test_handles_special_characters(): void
    {
        $result = $this->processor->process('Hello, World! #tag @mention');
        $this->assertStringContainsString('hello', $result);
        $this->assertStringContainsString('world', $result);
    }

    public function test_separates_cjk_characters(): void
    {
        $result = $this->processor->process('开发入门');
        $this->assertEquals('开 发 入 门', $result);
    }

    public function test_separates_cjk_mixed_with_latin(): void
    {
        $result = $this->processor->process('使用 Laravel 进行 PHP 开发');
        $this->assertEquals('使 用 laravel 进 行 php 开 发', $result);
    }

    public function test_separates_korean(): void
    {
        $result = $this->processor->process('안녕하세요');
        $this->assertEquals('안 녕 하 세 요', $result);
    }

    public function test_handles_invalid_utf8_gracefully(): void
    {
        $invalid = "\x80\x81\x82";
        $result = $this->processor->normalize($invalid);

        $this->assertIsString($result);
    }

    public function test_stemming_processor_is_bound_when_configured(): void
    {
        config(['illumi-search.processing.processor' => 'stemming']);

        $this->app->forgetInstance(TextProcessor::class);
        $processor = $this->app->make(TextProcessor::class);

        $this->assertInstanceOf(StemmingTextProcessor::class, $processor);
    }

    public function test_stemming_french_removes_verb_endings(): void
    {
        config(['illumi-search.processing.processor' => 'stemming']);

        $this->app->forgetInstance(TextProcessor::class);
        $processor = $this->app->make(TextProcessor::class);

        $result = $processor->process('mangeais mangeant mangera', 'fr');

        // All three words should stem to "mang"
        $tokens = explode(' ', $result);
        $this->assertCount(3, $tokens);
        $this->assertSame($tokens[0], $tokens[1]);
        $this->assertSame($tokens[1], $tokens[2]);
    }

    public function test_stemming_english_porter(): void
    {
        config(['illumi-search.processing.processor' => 'stemming']);

        $this->app->forgetInstance(TextProcessor::class);
        $processor = $this->app->make(TextProcessor::class);

        $result = $processor->process('running runner runs', 'en');

        $tokens = explode(' ', $result);

        // "running" and "runs" should stem to "run"
        $this->assertEquals('run', $tokens[0]);
        $this->assertEquals('run', $tokens[2]);
    }

    public function test_stemming_falls_back_to_unicode_for_unknown_locale(): void
    {
        config(['illumi-search.processing.processor' => 'stemming']);

        $this->app->forgetInstance(TextProcessor::class);
        $processor = $this->app->make(TextProcessor::class);

        $result = $processor->process('hello world', 'xx');

        // Unknown locale → no stemming → same as unicode processor
        $tokens = explode(' ', $result);
        $this->assertCount(2, $tokens);
        $this->assertEquals('hello', $tokens[0]);
        $this->assertEquals('world', $tokens[1]);
    }

    public function test_truncate_long_tokens(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $result = $this->processor->process($uuid);

        // Le token de 36 chars doit être tronqué à 32
        $this->assertLessThan(36, strlen($result));
        $this->assertStringStartsWith('550e8400-e29b-41d4-a716-44', $result);
    }

    public function test_truncate_does_not_affect_short_tokens(): void
    {
        $result = $this->processor->process('hello world php');

        $this->assertEquals('hello world php', $result);
    }

    public function test_truncate_handles_empty_string(): void
    {
        $result = $this->processor->process('');

        $this->assertEmpty($result);
    }

    public function test_truncate_handles_cjk_short(): void
    {
        $result = $this->processor->process('中文测试');

        $this->assertNotEmpty($result);
        $this->assertStringContainsString(' ', $result);
    }
}
