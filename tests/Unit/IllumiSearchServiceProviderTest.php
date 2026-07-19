<?php

namespace Moaines\IllumiSearch\Tests\Unit;

use Moaines\IllumiSearch\Contracts\FtsEngine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Engines\SqliteFtsEngine;
use Moaines\IllumiSearch\Text\UnicodeTextProcessor;
use Moaines\IllumiSearch\IllumiSearchServiceProvider;
use Moaines\IllumiSearch\Tests\TestCase;

class IllumiSearchServiceProviderTest extends TestCase
{
    public function test_provider_is_registered(): void
    {
        $provider = $this->app->getProvider(IllumiSearchServiceProvider::class);

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
