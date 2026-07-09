<?php

namespace Moaines\LaravelFts\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Moaines\LaravelFts\FtsIndexManager;
use Symfony\Component\Console\Helper\ProgressBar;

class FtsSyncCommand extends Command
{
    protected $signature = 'fts:sync
        {--model=* : Specific model classes to sync (multiple allowed)}
        {--since= : Only sync records updated after this datetime}';

    protected $description = 'Incrementally sync changed records to the FTS5 index';

    public function handle(FtsIndexManager $manager): int
    {
        $models = $this->option('model');
        $since = null;

        if ($sinceRaw = $this->option('since')) {
            $since = Carbon::parse($sinceRaw);
        }

        if (! empty($models)) {
            $this->info('Syncing models: '.implode(', ', $models));
        } else {
            $this->info('Syncing all indexed models...');
        }

        $pb = null;

        $results = $manager->sync(
            modelClasses: ! empty($models) ? $models : null,
            since: $since,
            progress: function (string $event, ...$args) use (&$pb) {
                match ($event) {
                    'startModel' => $this->startProgressBar($pb, $args[0], $args[1]),
                    'advance' => $pb?->advance($args[0]),
                    'finishModel' => $this->finishProgressBar($pb),
                };
            },
        );

        $this->clearProgressBar($pb);

        foreach ($results as $result) {
            match ($result['status']) {
                'synced' => $this->info("  ✓ {$result['model']}: {$result['records']} records synced"),
                'error' => $this->error("  ✗ {$result['model']}: {$result['message']}"),
                default => $this->line("  ? {$result['model']}: unknown status"),
            };
        }

        $this->newLine();
        $this->info('Sync complete.');

        return Command::SUCCESS;
    }

    private function startProgressBar(?ProgressBar &$pb, string $modelClass, int $total): void
    {
        $this->clearProgressBar($pb);
        $short = class_basename($modelClass);
        $this->line("  <fg=yellow>{$short}</>");
        $pb = $this->output->createProgressBar($total);
        $pb->setFormat('    %current%/%max% [%bar%] %elapsed:6s%');
        $pb->start();
    }

    private function finishProgressBar(?ProgressBar &$pb): void
    {
        if ($pb === null) {
            return;
        }
        $pb->finish();
        $this->newLine(2);
        $pb = null;
    }

    private function clearProgressBar(?ProgressBar $pb): void
    {
        if ($pb !== null) {
            $pb->clear();
        }
    }
}
