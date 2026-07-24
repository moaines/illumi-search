<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Illuminate\Support\Collection;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Facades\IllumiSearch;
use Moaines\IllumiSearch\Tests\TestCase;

class FacadeIllumiSearchTest extends TestCase
{
    public function test_did_you_mean_returns_collection(): void
    {
        $engine = $this->app->make(Engine::class);
        $engine->createTable('App\Models\Post', ['title', 'body']);
        $engine->upsert('App\Models\Post', 1, ['title' => 'laravel', 'body' => 'php framework']);

        $result = IllumiSearch::didYouMean('laravell', ['App\Models\Post']);

        $this->assertInstanceOf(Collection::class, $result);
    }
}
