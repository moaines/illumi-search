<?php

namespace Moaines\LaravelFts\Console\Commands;

use Illuminate\Console\Command;
use Moaines\LaravelFts\Console\Commands\Concerns\HasFtsFormatBytes;
use Moaines\LaravelFts\Contracts\FtsEngine;

class FtsDoctorCommand extends Command
{
    use HasFtsFormatBytes;
    protected $signature = 'fts:doctor';

    protected $description = 'Diagnose the FTS5 search environment';

    public function handle(FtsEngine $engine): int
    {
        $this->info('🔍 FTS Environment Diagnostics');
        $this->newLine();

        $allOk = true;

        // 1. PHP Extensions
        $this->line('1. PHP Extensions');
        $checks = [
            'sqlite3' => extension_loaded('sqlite3'),
            'intl'    => extension_loaded('intl'),
            'mbstring' => extension_loaded('mbstring'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        ];

        foreach ($checks as $ext => $loaded) {
            $status = $loaded ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("   {$status} ext-{$ext}");
            if (! $loaded) {
                $allOk = false;
            }
        }
        $this->newLine();

        // 2. FTS5 Support
        $this->line('2. SQLite FTS5 Support');
        try {
            $db = new \SQLite3(':memory:');
            $db->exec("CREATE VIRTUAL TABLE _fts_check USING fts5(content)");
            $version = \SQLite3::version();
            $this->line('   <fg=green>✓</> FTS5 is available (SQLite ' . $version['versionString'] . ')');
            $db->close();
        } catch (\Exception $e) {
            $this->line('   <fg=red>✗</> FTS5 is NOT available: ' . $e->getMessage());
            $allOk = false;
        }
        $this->newLine();

        // 3. Database
        $this->line('3. FTS Database');
        $dbPath = $engine->getDatabasePath();

        if (file_exists($dbPath)) {
            $size = $engine->getDatabaseSize();
            $sizeHuman = $this->formatBytes($size);
            $isWritable = is_writable($dbPath);
            $isReadable = is_readable($dbPath);
            $this->line("   <fg=green>✓</> Path: {$dbPath}");
            $this->line("   <fg=green>✓</> Size: {$sizeHuman}");
            $this->line("   " . ($isReadable ? '<fg=green>✓</> Readable' : '<fg=red>✗</> Not readable'));
            $this->line("   " . ($isWritable ? '<fg=green>✓</> Writable' : '<fg=red>✗</> Not writable'));

            // Check indexes
            $stats = $engine->getIndexStats();
            if (! empty($stats)) {
                $this->newLine();
                $this->line('   Indexes:');
                foreach ($stats as $stat) {
                    $this->line("     - {$stat['model_class']}: {$stat['record_count']} records");
                }
            } else {
                $this->line('   <fg=yellow>⚠</> No indexes found. Run php artisan fts:rebuild');
            }
        } else {
            $this->line('   <fg=yellow>⚠</> Database does not exist yet');
            $this->line('   Path would be: ' . $dbPath);
            $this->line('   Run <comment>php artisan fts:rebuild</comment> to create it');
        }
        $this->newLine();

        // 4. Config
        $this->line('4. Configuration');
        $configKeys = [
            'fts.indexing'        => config('fts.indexing'),
            'fts.mode'            => config('fts.mode'),
            'fts.fts5.tokenizer'  => config('fts.fts5.tokenizer'),
            'fts.tenancy.enabled' => config('fts.tenancy.enabled') ? 'true' : 'false',
            'fts.authorization.enabled' => config('fts.authorization.enabled') ? 'true' : 'false',
        ];

        foreach ($configKeys as $key => $value) {
            $this->line("   <info>{$key}</info> = {$value}");
        }
        $this->newLine();

        // Summary
        if ($allOk) {
            $this->info('✅ All checks passed');
        } else {
            $this->error('❌ Some checks failed — review the output above');
        }

        return $allOk ? Command::SUCCESS : Command::FAILURE;
    }
}
