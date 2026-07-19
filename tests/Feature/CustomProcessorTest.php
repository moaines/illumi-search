<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Contracts\FtsEngine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Tests\TestSupport\Models\Post;
use Moaines\IllumiSearch\Tests\TestSupport\Processors\PorterStemmerProcessor;
use Moaines\IllumiSearch\Tests\TestCase;

class CustomProcessorTest extends TestCase
{
    private FtsEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        $this->engine = $this->app->make(FtsEngine::class);
    }

    public function test_model_can_define_custom_processor(): void
    {
        $model = new class extends Post
        {
            public function ftsTextProcessor(): ?string
            {
                return PorterStemmerProcessor::class;
            }
        };

        $global = $this->app->make(TextProcessor::class);
        $resolved = $model->resolveProcessorFor($model, $global);

        $this->assertInstanceOf(PorterStemmerProcessor::class, $resolved);
    }

    public function test_model_uses_global_processor_when_no_custom(): void
    {
        $model = new Post;
        $global = $this->app->make(TextProcessor::class);
        $resolved = $model->resolveProcessorFor($model, $global);

        $this->assertSame($global, $resolved);
    }

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
