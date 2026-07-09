<?php

namespace Moaines\LaravelFts\Tests\Unit;

use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\Engines\SqliteFtsEngine;
use Moaines\LaravelFts\Text\UnicodeTextProcessor;
use Moaines\LaravelFts\LaravelFtsServiceProvider;
use Moaines\LaravelFts\Tests\TestCase;

class LaravelFtsServiceProviderTest extends TestCase
{
    public function test_provider_is_registered(): void
    {
        $provider = $this->app->getProvider(LaravelFtsServiceProvider::class);

        $this->assertNotNull($provider);
    }

    public function test_engine_is_bound_as_concrete_instance(): void
    {
        $engine = $this->app->make(FtsEngine::class);

        $this->assertInstanceOf(SqliteFtsEngine::class, $engine);
    }

    public function test_text_processor_is_bound(): void
    {
        $processor = $this->app->make(TextProcessor::class);

        $this->assertInstanceOf(UnicodeTextProcessor::class, $processor);
    }
}
