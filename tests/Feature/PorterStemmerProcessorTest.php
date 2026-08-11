<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Tests\TestCase;
use Moaines\IllumiSearch\Tests\TestSupport\Processors\PorterStemmerProcessor;

class PorterStemmerProcessorTest extends TestCase
{
    public function test_custom_processor_stems_text(): void
    {
        $processor = new PorterStemmerProcessor;

        $this->assertEquals('runn', $processor->process('running'));
        $this->assertEquals('play', $processor->process('played'));
        $this->assertEquals('cat', $processor->process('cats'));
        $this->assertEquals('hello', $processor->process('hello'));
    }

    public function test_custom_processor_produces_different_output_than_global(): void
    {
        $global = $this->app->make(TextProcessor::class);
        $custom = new PorterStemmerProcessor;

        $input = 'running cats played';
        $globalResult = $global->process($input);
        $customResult = $custom->process($input);

        $this->assertNotEquals($globalResult, $customResult);
        $this->assertEquals('runn cat play', $customResult);
    }
}
