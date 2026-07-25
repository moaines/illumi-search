<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Console\Commands\Concerns\HasProgressBar;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\IndexManager;

class RebuildCommand extends Command
{
    use HasProgressBar;

    protected $signature = 'illumi-search:rebuild
        {--model=* : Specific model classes to rebuild (multiple allowed)}
        {--force : Skip confirmation prompt}
        {--vacuum : Run VACUUM after rebuilding (slower but reclaims disk space)}
        {--batch-size= : Records to sync before switching to queue (default: config)}';
    protected $description = 'Rebuild the search index from scratch';

    private ?float $modelStartTime = null;
    private ?string $currentModelShort = null;
    private int $currentModelRecords = 0;
    private int $currentModelTotal = 0;
    private int $totalRecords = 0;
    private int $totalModels = 0;
    private float $totalDuration = 0;
    private int $totalWarnings = 0;
    private int $totalQueued = 0;
    private int $totalSkipped = 0;
    private int $totalErrors = 0;
    private ?float $startTime = null;
    private int $processingCounter = 0;
    private int $modelProgress = 0;

    public function handle(IndexManager $manager, Engine $engine): int
    {
        $driver = config('illumi-search.driver');
        $version = $engine->getEngineVersion();

        $this->line(" Illumi Search — <fg=yellow>{$driver}</> ({$version})");
        $this->line(str_repeat("\u{2550}", 47));
        $this->newLine();

        // FileEngine needs more memory for large datasets
        if ($driver === 'file') {
            $current = ini_get('memory_limit');
            if ($current !== '-1' && $this->parseMemory($current) < 256) {
                ini_set('memory_limit', '256M');
                $this->line('   <fg=yellow>ℹ memory_limit increased to 256M for file engine</>');
            }
        }

        $models = $this->option('model');

        if (empty($models) && ! $this->option('force')) {
            if (! $this->confirm('This will rebuild ALL indexed models. Continue?')) {
                $this->info('Rebuild cancelled.');
                return Command::SUCCESS;
            }
        }

        $batchSize = $this->option('batch-size');
        if ($batchSize !== null) {
            $batchSize = (int) $batchSize;
        }

        $vacuum = (bool) $this->option('vacuum');

        $this->startTime = microtime(true);

        $results = $manager->rebuild(
            modelClasses: ! empty($models) ? $models : null,
            batchSize: $batchSize,
            vacuum: $vacuum,
            progress: function (string $event, ...$args) {
                match ($event) {
                    'startModel' => $this->onStartModel($args[0], $args[1]),
                    'advance' => $this->onAdvance($args[0]),
                    'finishModel' => $this->onFinishModel(),
                    'processing' => $this->onProcessing($args[0], $args[1]),
                    'loading' => $this->onLoading($args[1]),
                    default => null,
                };
            },
        );

        // Display per-model results
        foreach ($results as $result) {
            match ($result['status']) {
                'indexed' => $this->indexedResult($result),
                'skipped' => $this->skippedResult($result),
                'error' => $this->errorResult($result),
                'warning' => $this->warningResult($result),
                'vacuumed' => $this->vacuumResult($result),
                'cleaned' => $this->cleanedResult($result),
                default => $this->line("  ? {$result['model']}: unknown status"),
            };
        }

        $this->showSummary();

        return Command::SUCCESS;
    }

    private function onStartModel(string $modelClass, int $total): void
    {
        if ($this->currentModelShort !== null) {
            $this->outputModelResult();
        }
        $this->totalModels++;
        $this->modelStartTime = microtime(true);
        $this->currentModelShort = class_basename($modelClass);
        $this->currentModelRecords = 0;
        $this->currentModelTotal = $total;
        $this->processingCounter = 0;
        $this->modelProgress = 0;
        $this->line("  <fg=yellow>{$this->currentModelShort}</>");
    }

    private function onAdvance(int $count): void
    {
        $this->modelProgress += $count;
        $this->currentModelRecords += $count;
        $this->totalRecords += $count;
    }

    private function onFinishModel(): void
    {
        if ($this->currentModelShort !== null) {
            $this->outputModelResult();
            $this->currentModelShort = null;
        }
    }

    private function outputModelResult(): void
    {
        $duration = $this->modelStartTime !== null ? microtime(true) - $this->modelStartTime : 0;
        $rate = $duration > 0 ? round($this->totalRecords / $duration, 1) : 0;

        $this->line("  ✓ {$this->currentModelShort}: {$this->currentModelRecords}/{$this->currentModelTotal}");
        $this->line("    <fg=gray>" . round($duration, 1) . "s {$rate} docs/s</>");
    }

    private function onProcessing(int|string $id, string $title): void
    {
        $this->processingCounter++;
        if ($title === (string) $id) {
            return;
        }
        if ($this->processingCounter % 50 !== 0) {
            return;
        }
        $display = mb_strlen($title) > 50 ? mb_substr($title, 0, 50) . '…' : $title;
        $this->output->write("\r    <fg=gray>#{$id}: {$display}</>");
    }

    private function onLoading(string $label): void
    {
        $this->newLine();
        $this->line("    <fg=gray>loading {$label}...</>");
        $this->output->write("    ");
    }

    /** @param array{model: string, records?: int, queued?: int, total?: int, message?: string, warnings?: array} $result */
    private function indexedResult(array $result): void
    {
        $this->totalQueued += $result['queued'] ?? 0;
        $this->totalWarnings += count($result['warnings'] ?? []);

        foreach ($result['warnings'] ?? [] as $w) {
            $this->line("    <fg=yellow>⚠ {$w['message']}</>");
        }
    }

    private function skippedResult(array $result): void
    {
        $this->totalSkipped++;
        $short = class_basename($result['model']);
        $this->line("  — {$short}: <fg=gray>{$result['message']}</>");
    }

    private function errorResult(array $result): void
    {
        $this->totalErrors++;
        $short = class_basename($result['model']);
        $this->line("  <fg=red>✗ {$short}: {$result['message']}</>");
    }

    private function warningResult(array $result): void
    {
        $short = class_basename($result['model']);
        $this->line("    <fg=yellow>⚠ {$short}: {$result['message']}</>");
    }

    private function vacuumResult(array $result): void
    {
        $reclaimed = $result['reclaimed'] ?? 0;
        if ($reclaimed > 0) {
            $mb = round($reclaimed / 1048576, 1);
            $this->line("  <fg=green>◇ VACUUM:</> reclaimed {$mb} MB");
        }
    }

    private function cleanedResult(array $result): void
    {
        $short = class_basename($result['model']);
        $this->line("  <fg=gray>◇ cleaned orphaned table: {$short}</>");
    }

    private function showSummary(): void
    {
        $this->newLine();
        $this->line(str_repeat("\u{2500}", 47));

        $totalTime = microtime(true) - ($this->startTime ?? microtime(true));
        $overallRate = $totalTime > 0 ? round($this->totalRecords / $totalTime, 1) : 0;

        $this->line("  {$this->totalModels} models indexed  {$this->totalRecords} records  " . round($totalTime, 1) . "s  {$overallRate} docs/s");

        if ($this->totalQueued > 0) {
            $this->line("  <fg=gray>  +{$this->totalQueued} records queued for background processing</>");
        }

        $issues = [];
        if ($this->totalSkipped > 0) $issues[] = "{$this->totalSkipped} skipped";
        if ($this->totalErrors > 0) $issues[] = "<fg=red>{$this->totalErrors} errors</>";
        if ($this->totalWarnings > 0) $issues[] = "<fg=yellow>{$this->totalWarnings} warnings</>";

        if (! empty($issues)) {
            $this->line('  ' . implode(', ', $issues));
        }

        // Index size
        try {
            $engine = app(Engine::class);
            $size = $engine->getDatabaseSize();
            if ($size && $size > 0) {
                $mb = round($size / 1048576, 1);
                $this->line("  Index size: {$mb} MB");
            }
        } catch (\Exception) {
        }

        $this->newLine();
        $this->info('Rebuild complete.');
    }

    private function parseMemory(string $setting): int
    {
        $value = (int) $setting;
        $unit = strtolower(substr(trim($setting), -1));

        return match ($unit) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => (int) ($value / 1024),
            default => (int) ($value / 1024 / 1024),
        };
    }
}
