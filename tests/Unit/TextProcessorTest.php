<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\Tests\TestCase;

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
}
