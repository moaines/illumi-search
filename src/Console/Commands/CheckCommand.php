<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\IndexManager;

class CheckCommand extends Command
{
    protected $signature = 'illumi-search:check';

    protected $description = 'Check FTS5 index schema status for all searchable models';

    public function handle(IndexManager $manager): int
    {
        $checks = $manager->checkSchema();

        if (empty($checks)) {
            $this->warn('No searchable models found. Ensure your models use the Searchable trait.');

            return Command::SUCCESS;
        }

        $headers = ['Model', 'Version', 'Columns', 'Status'];
        $rows = [];

        foreach ($checks as $check) {
            $columns = implode(', ', $check['declared_columns']);
            $statusBadge = match ($check['status']) {
                'ok' => '<fg=green>OK</>',
                'missing' => '<fg=yellow>MISSING</>',
                'drift' => '<fg=red>DRIFT</>',
                default => '<fg=gray>?</>',
            };

            $rows[] = [
                $check['model'],
                $check['exists'] ? '1' : '-',
                $columns,
                $statusBadge,
            ];
        }

        $this->table($headers, $rows);

        $hasDrift = collect($checks)->contains(fn ($c) => $c['status'] === 'drift');
        $hasMissing = collect($checks)->contains(fn ($c) => $c['status'] === 'missing');

        if ($hasDrift) {
            $this->newLine();
            $this->warn('Some models have schema drift. Run "php artisan illumi-search:rebuild --model=..." to re-index.');
        }

        if ($hasMissing) {
            $this->newLine();
            $this->warn('Some models have no index yet. Run "php artisan illumi-search:rebuild" to create them.');
        }

        if (! $hasDrift && ! $hasMissing) {
            $this->info('All indexes are up to date.');
        }

        return Command::SUCCESS;
    }
}
