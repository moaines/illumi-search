<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Contracts\FtsEngine;

class FtsSearchCommand extends Command
{
    protected $signature = 'fts:search
        {query : Search terms}
        {--models= : Comma-separated model classes (default: all indexed)}
        {--limit=10 : Max results}
        {--mode=advanced : Search mode: basic, advanced, raw}
        {--json : Output as JSON}
        {--suggest : Include spellcheck suggestions}';

    protected $description = 'Search the FTS index';

    public function handle(FtsEngine $engine): int
    {
        $query = $this->argument('query');
        $limit = (int) $this->option('limit');
        $mode = $this->option('mode');
        $asJson = (bool) $this->option('json');
        $withSuggest = (bool) $this->option('suggest');

        $modelsInput = $this->option('models');
        $modelClasses = $modelsInput
            ? array_filter(explode(',', $modelsInput))
            : $engine->getIndexedModelClasses();

        $results = $engine->search($query, $modelClasses, $limit, 0, $mode, withSnippets: true);
        $total = count($results);

        if ($asJson) {
            $this->line(json_encode([
                'query'       => $query,
                'total'       => $total,
                'results'     => $results,
                'suggestions' => $this->getSuggestions($withSuggest, $results, $query, $modelClasses),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($total === 0) {
            $this->warn("No results for \"{$query}\"");

            if ($withSuggest && mb_strlen($query) > 2) {
                $suggestions = app(\Moaines\IllumiSearch\FtsSpellcheck::class)
                    ->suggest($query, $modelClasses)
                    ->values()
                    ->toArray();

                if (! empty($suggestions)) {
                    $this->line('  Did you mean:');
                    foreach ($suggestions as $s) {
                        $this->line("    <fg=green>{$s}</>");
                    }
                }
            }

            return Command::SUCCESS;
        }

        $this->line("  <fg=green>{$total} results for \"{$query}\"</>\n");
        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result->modelClass][] = $result;
        }

        foreach ($grouped as $model => $items) {
            $this->line("  <fg=yellow>{$model}</> (" . count($items) . ')');
            foreach ($items as $item) {
                $title = $item->title ?? '(no title)';
                $this->line("    ✓ {$title}");
            }
            $this->newLine();
        }

        $withSuggestions = $this->getSuggestions($withSuggest, $results, $query, $modelClasses);
        if (! empty($withSuggestions)) {
            $this->line('  Suggestions: ' . implode(', ', $withSuggestions));
        }

        return Command::SUCCESS;
    }

    private function getSuggestions(bool $withSuggest, array $results, string $query, array $modelClasses): array
    {
        if (! $withSuggest || ! empty($results) || mb_strlen($query) <= 2) {
            return [];
        }

        return app(\Moaines\IllumiSearch\FtsSpellcheck::class)
            ->suggest($query, $modelClasses)
            ->values()
            ->toArray();
    }
}
