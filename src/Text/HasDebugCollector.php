<?php

namespace Moaines\IllumiSearch\Text;

use DebugBar\StandardDebugBar;
use Moaines\IllumiSearch\Debug\IllumiSearchCollector;

/**
 * Standardises DebugBar integration for all engines.
 *
 * Provides resolveCollector(), recordQuery(), and setCollectorEngineInfo()
 * so that every engine sends query metrics and engine info to the debugbar.
 */
trait HasDebugCollector
{
    private ?IllumiSearchCollector $debugCollector = null;

    /**
     * Resolve the IllumiSearchCollector from the DebugBar (if available).
     */
    protected function resolveCollector(): ?IllumiSearchCollector
    {
        if ($this->debugCollector !== null) {
            return $this->debugCollector;
        }

        if (! class_exists(StandardDebugBar::class)) {
            return $this->debugCollector = null;
        }

        try {
            $debugbar = app('debugbar');

            if (! $debugbar?->hasCollector('illumi-search')) {
                $collector = new IllumiSearchCollector;
                $debugbar->addCollector($collector);
            }

            $this->debugCollector = $debugbar?->getCollector('illumi-search');
        } catch (\Exception) {
            $this->debugCollector = null;
        }

        return $this->debugCollector;
    }

    /**
     * Record a search query in the debug collector.
     *
     * @param  float[]  $topScores
     */
    protected function recordSearchQuery(
        string $matchQuery,
        string $table,
        string $modelClass,
        string $mode,
        int $resultCount,
        float $durationMs,
        array $topScores = [],
    ): void {
        $collector = $this->resolveCollector();

        if ($collector !== null) {
            $collector->addQuery(
                matchQuery: $matchQuery,
                table: $table,
                modelClass: $modelClass,
                mode: $mode,
                resultCount: $resultCount,
                durationMs: $durationMs,
                topScores: $topScores,
            );
        }
    }

    /**
     * Set engine info on the debug collector.
     *
     * @param  array<string, mixed>  $info
     */
    protected function setCollectorEngineInfo(array $info): void
    {
        $collector = $this->resolveCollector();

        if ($collector !== null) {
            $collector->setEngineInfo($info);
        }
    }
}
