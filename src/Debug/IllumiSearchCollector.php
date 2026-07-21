<?php

namespace Moaines\IllumiSearch\Debug;

use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\Renderable;

class IllumiSearchCollector extends DataCollector implements Renderable
{
    /** @var list<string> */
    protected array $queries = [];

    /** @var array<string, mixed>|null */
    protected ?array $engineInfo = null;

    /** @param float[] $topScores */
    public function addQuery(
        string $matchQuery,
        string $table,
        string $modelClass,
        string $mode,
        int $resultCount,
        float $durationMs,
        array $topScores = [],
    ): void {
        $duration = round($durationMs, 2);
        $parts = ["MATCH '{$matchQuery}'"];
        $parts[] = "→ {$modelClass} ({$resultCount} results, {$duration}ms)";

        if ($topScores) {
            $scores = array_map(fn ($s) => round($s, 3), $topScores);
            $parts[] = 'BM25: '.implode(', ', $scores);
        }

        if ($mode !== 'advanced') {
            $parts[] = "mode: {$mode}";
        }

        $this->queries[] = $duration > 1 ? "[{$duration}ms] ".implode(' — ', $parts) : implode(' — ', $parts);
    }

    /** @param array<string, mixed> $info */
    public function setEngineInfo(array $info): void
    {
        $this->engineInfo = $info;
    }

    /**
     * @return array{count: int<0, max>, data: list<string>}
     */
    public function collect(): array
    {
        $data = [];

        if ($this->engineInfo) {
            $data[] = '⚙️ Engine: '.($this->engineInfo['version'] ?? '?');
            $data[] = '   Tokenizer: '.($this->engineInfo['tokenizer'] ?? '?');
            $data[] = '   Indexed: '.number_format($this->engineInfo['indexed_records'] ?? 0).' records';
        }

        if ($this->queries) {
            if ($this->engineInfo) {
                $data[] = '';
            }
            array_push($data, ...$this->queries);
        }

        return [
            'count' => count($this->queries),
            'data' => $data,
        ];
    }

    public function getName(): string
    {
        return 'illumi-search';
    }

    /**
     * @return array<string, array{icon?: string, widget?: string, map: string, default?: mixed}>
     */
    public function getWidgets(): array
    {
        return [
            'illumi-search' => [
                'icon' => 'search',
                'widget' => 'PhpDebugBar.Widgets.ListWidget',
                'map' => 'illumi-search.data',
                'default' => '[]',
            ],
            'illumi-search:badge' => [
                'map' => 'illumi-search.count',
                'default' => 0,
            ],
        ];
    }
}
