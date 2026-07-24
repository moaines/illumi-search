<?php

namespace Moaines\IllumiSearch\Tests\Unit\Support;

use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Support\EngineProxy;
use Moaines\IllumiSearch\Tests\TestCase;

class EngineProxyTest extends TestCase
{
    /** @test */
    public function proxy_delegates_search_to_engine(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->expects($this->once())
            ->method('search')
            ->with('test', ['App\Models\Post'], 10, 0, 'advanced', true)
            ->willReturn([]);

        $proxy = new EngineProxy(fn () => $engine);
        $result = $proxy->search('test', ['App\Models\Post'], 10);

        $this->assertIsArray($result);
    }

    /** @test */
    public function proxy_caches_engine_across_calls(): void
    {
        $callCount = 0;
        $proxy = new EngineProxy(function () use (&$callCount) {
            $callCount++;

            return $this->createMock(Engine::class);
        });

        $proxy->search('a', ['Test'], 10);
        $proxy->search('b', ['Test'], 10);
        $proxy->search('c', ['Test'], 10);

        $this->assertEquals(1, $callCount, 'Engine should be resolved once and cached');
    }

    /** @test */
    public function refresh_creates_new_engine(): void
    {
        $callCount = 0;
        $proxy = new EngineProxy(function () use (&$callCount) {
            $callCount++;

            return $this->createMock(Engine::class);
        });

        $proxy->search('a', ['Test'], 10);
        $proxy->refresh();
        $proxy->search('b', ['Test'], 10);

        $this->assertEquals(2, $callCount, 'After refresh, a new engine should be resolved');
    }

    /** @test */
    public function proxy_delegates_all_engine_methods(): void
    {
        $engine = $this->createMock(Engine::class);
        $engine->method('getEngineVersion')->willReturn('1.0');
        $engine->method('getDatabasePath')->willReturn('/tmp/test');
        $engine->method('getDatabaseSize')->willReturn(1024);
        $engine->method('getSupportedOperators')->willReturn(['AND', 'OR']);
        $engine->method('supportsPhraseSearch')->willReturn(true);
        $engine->method('supportsPrefixWildcard')->willReturn(true);
        $engine->method('isFts5Available')->willReturn(false);
        $engine->method('getEngineStatus')->willReturn(['driver' => 'test']);

        $proxy = new EngineProxy(fn () => $engine);

        $this->assertEquals('1.0', $proxy->getEngineVersion());
        $this->assertEquals('/tmp/test', $proxy->getDatabasePath());
        $this->assertEquals(1024, $proxy->getDatabaseSize());
        $this->assertEquals(['AND', 'OR'], $proxy->getSupportedOperators());
        $this->assertTrue($proxy->supportsPhraseSearch());
        $this->assertTrue($proxy->supportsPrefixWildcard());
        $this->assertFalse($proxy->isFts5Available());
        $this->assertEquals(['driver' => 'test'], $proxy->getEngineStatus());
    }
}
