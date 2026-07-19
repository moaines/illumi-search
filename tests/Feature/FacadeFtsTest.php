<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Moaines\IllumiSearch\Facades\Fts;
use Moaines\IllumiSearch\Tests\TestCase;

class FacadeFtsTest extends TestCase
{
    public function test_did_you_mean_returns_collection(): void
    {
        $engine = $this->app->make(\Moaines\IllumiSearch\Contracts\FtsEngine::class);
        $engine->createTable('App\Models\Post', ['title', 'body']);
        $engine->upsert('App\Models\Post', 1, ['title' => 'laravel', 'body' => 'php framework']);

        $result = Fts::didYouMean('laravell', ['App\Models\Post']);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }
}
