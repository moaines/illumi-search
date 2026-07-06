<?php

namespace Moaines\LaravelFts\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Moaines\LaravelFts\FtsIndexManager;

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

        $results = $manager->sync(
            modelClasses: ! empty($models) ? $models : null,
            since: $since,
        );

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
}
