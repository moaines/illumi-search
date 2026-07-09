<?php

namespace Moaines\LaravelFts\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FtsSuggestCommand extends Command
{
    protected $signature = 'fts:suggest
        {--panel= : The Filament panel ID (defaults to current)}
        {--format=table : Output format (table|json)}';

    protected $description = 'Suggest $ftsSearchable columns based on Filament Resources';

    public function handle(): int
    {
        $resources = $this->resolvePanelResources();

        if ($resources === null) {
            return Command::SUCCESS;
        }

        if (empty($resources)) {
            $this->warn('No Filament resources found in the panel.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($resources as $resource) {
            try {
                $modelClass = $resource::getModel();
            } catch (\Exception) {
                continue;
            }

            if ($modelClass === null || ! class_exists($modelClass)) {
                continue;
            }

            $attrs = $this->resolveSearchableAttributes($resource);

            if (empty($attrs)) {
                continue;
            }

            $model = new $modelClass;
            $existing = $this->usesSearchable($modelClass)
                ? array_keys($model->normalizeFtsSearchable())
                : [];

            foreach ($attrs as $attr) {
                $alreadyPresent = in_array($attr, $existing, true);
                $titleAttr = $resource::getRecordTitleAttribute();
                $weight = ($titleAttr !== null && $attr === $titleAttr) ? 3 : 1;

                $exists = $this->attributeExists($model, $attr);
                $notes = match (true) {
                    $attr === $titleAttr => 'title',
                    $alreadyPresent => 'already set',
                    $exists === 'column' => 'ok',
                    $exists === 'relation' => 'relation',
                    $exists === 'virtual' => '⚠ virtual — verify',
                    default => '⚠ not found',
                };

                $rows[] = [
                    'model' => $modelClass,
                    'resource' => class_basename($resource),
                    'column' => $attr,
                    'weight' => $weight,
                    'exists' => $alreadyPresent ? '✅' : '❌',
                    'notes' => $notes,
                ];
            }
        }

        if (empty($rows)) {
            $this->info('No suggestions found. All models already have $ftsSearchable configured.');

            return Command::SUCCESS;
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $headers = ['Model', 'Resource', 'Suggested Column', 'Weight', 'In FTS?', 'Notes'];
        $this->table($headers, $rows);

        $missingCount = collect($rows)->filter(fn ($r) => $r['exists'] === '❌')->count();

        if ($missingCount > 0) {
            $this->newLine();
            $this->warn("{$missingCount} columns not yet in \$ftsSearchable. Add them to your model's \$ftsSearchable array or run fts:rebuild after.");
        }

        return Command::SUCCESS;
    }

    protected function resolvePanelResources(): ?array
    {
        if (! class_exists(\Filament\Facades\Filament::class)) {
            $this->warn('Filament is not installed. This command requires Filament panels to analyze resources.');

            return null;
        }

        $panel = $this->option('panel')
            ? \Filament\Facades\Filament::getPanel($this->option('panel'))
            : \Filament\Facades\Filament::getCurrentPanel();

        if ($panel === null) {
            $panels = \Filament\Facades\Filament::getPanels();

            if (! empty($panels)) {
                $panel = reset($panels);
                $this->line("<fg=yellow>ℹ Using panel:</> {$panel->getId()}");

                return $panel->getResources();
            }

            $this->warn('No Filament panel found. Make sure you are in a Filament context or specify --panel.');

            return null;
        }

        return $panel->getResources();
    }

    protected function resolveSearchableAttributes(string $resource): array
    {
        try {
            $attrs = $resource::getGloballySearchableAttributes();
        } catch (\Exception) {
            return [];
        }

        if ($attrs !== null && ! empty($attrs)) {
            return $attrs;
        }

        try {
            $titleAttr = $resource::getRecordTitleAttribute();
        } catch (\Exception) {
            return [];
        }

        return $titleAttr !== null ? [$titleAttr] : [];
    }

    protected function attributeExists(Model $model, string $attr): string
    {
        if (! str_contains($attr, '.')) {
            if (Schema::hasColumn($model->getTable(), $attr)) {
                return 'column';
            }

            if (method_exists($model, $attr)) {
                return 'relation';
            }

            if ($model->hasGetMutator($attr) || $model->hasAttributeGetMutator($attr)) {
                return 'column';
            }

            if (isset($model->$attr)) {
                return 'column';
            }

            return 'virtual';
        }

        $segments = explode('.', $attr);
        $relName = $segments[0];

        if (! method_exists($model, $relName)) {
            return 'virtual';
        }

        return 'relation';
    }

    protected function usesSearchable(string $modelClass): bool
    {
        return in_array('Moaines\\LaravelFts\\Searchable', class_uses_recursive($modelClass));
    }
}
