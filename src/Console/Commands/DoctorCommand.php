<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Moaines\IllumiSearch\Console\Commands\Concerns\HasFormatBytes;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Engines\SqliteEngine;

class DoctorCommand extends Command
{
    use HasFormatBytes;

    protected $signature = 'illumi-search:doctor';

    protected $description = 'Diagnose the FTS5 search environment';

    private bool $allOk = true;

    public function handle(Engine $engine): int
    {
        $this->info('🔍 FTS Environment Diagnostics');
        $this->newLine();

        $this->checkPhpExtensions();
        $this->checkEngineSupport();
        $stats = $this->checkDatabase($engine);
        $this->checkIntegrity($engine, $stats);
        $this->showConfig();
        $this->validateConfig();
        $this->checkOperators();

        $this->newLine();
        if ($this->allOk) {
            $this->info('✅ All checks passed');
        } else {
            $this->error('❌ Some checks failed — review the output above');
        }

        return $this->allOk ? Command::SUCCESS : Command::FAILURE;
    }

    private function checkPhpExtensions(): void
    {
        $this->line('1. PHP Extensions');
        foreach (['sqlite3', 'mbstring', 'pdo_sqlite'] as $ext) {
            $loaded = extension_loaded($ext);
            $this->line('   '.($loaded ? '<fg=green>✓</>' : '<fg=red>✗</>')." ext-{$ext}");
            if (! $loaded) {
                $this->allOk = false;
            }
        }

        if (extension_loaded('intl')) {
            $this->line('   <fg=green>✓</> ext-intl (full Unicode support)');
        } else {
            $this->line('   <fg=yellow>⚠</> ext-intl: missing (fallback accent processor active)');
            $this->line('   <fg=yellow>  → Install php-intl for advanced Unicode normalization</>');
        }
        $this->newLine();
    }

    private function checkEngineSupport(): void
    {
        $this->line('2. SQLite FTS5 Support');
        try {
            $db = new \SQLite3(':memory:');
            $db->exec('CREATE VIRTUAL TABLE _fts_check USING fts5(content)');
            $version = \SQLite3::version();
            $this->line('   <fg=green>✓</> FTS5 is available (SQLite '.$version['versionString'].')');
            $db->close();
        } catch (\Exception $e) {
            $this->line('   <fg=red>✗</> FTS5 is NOT available: '.$e->getMessage());
            $this->line('   <fg=yellow>  → SQLite must be compiled with --enable-fts5 or SQLITE_ENABLE_FTS5</>');
            $this->line('   <fg=yellow>  → Most distributions: apt install php-sqlite3 / yum install php-sqlite3</>');
            $this->line('   <fg=yellow>  → Verify with: php -r "echo SQLite3::version()[\'versionString\'];"</>');
            $this->allOk = false;
        }
        $this->newLine();
    }

    /** @return array<int, array{model_class: string, record_count: int, last_synced_at: ?string, columns: ?string}> */
    private function checkDatabase(Engine $engine): array
    {
        $this->line('3. FTS Database');
        $dbPath = $engine->getDatabasePath();

        if (! file_exists($dbPath)) {
            $this->line('   <fg=yellow>⚠</> Database does not exist yet');
            $this->line('   Path would be: '.$dbPath);
            $this->line('   Run <comment>php artisan illumi-search:rebuild</comment> to create it');
            $this->newLine();

            return [];
        }

        $sizeHuman = $this->formatBytes($engine->getDatabaseSize());
        $freeSpace = @disk_free_space(dirname($dbPath));
        $freeHuman = $freeSpace !== false ? $this->formatBytes((int) $freeSpace) : 'unknown';
        $isAbsolute = str_starts_with($dbPath, '/');

        $this->line("   <fg=green>✓</> Path: {$dbPath}");
        $this->line('   <fg=green>✓</> Path type: '.($isAbsolute ? 'absolute' : 'relative (via storage_path())'));
        $this->line("   <fg=green>✓</> Size: {$sizeHuman}");
        $this->line("   <fg=green>✓</> Free space on volume: {$freeHuman}");
        $this->line('   '.(is_readable($dbPath) ? '<fg=green>✓</> Readable' : '<fg=red>✗</> Not readable'));
        $this->line('   '.(is_writable($dbPath) ? '<fg=green>✓</> Writable' : '<fg=red>✗</> Not writable'));

        $stats = $engine->getIndexStats();
        if (! empty($stats)) {
            $this->newLine();
            $this->line('   Indexes:');
            foreach ($stats as $stat) {
                $this->line("     - {$stat['model_class']}: {$stat['record_count']} records");
            }
        } else {
            $this->line('   <fg=yellow>⚠</> No indexes found. Run php artisan illumi-search:rebuild');
        }
        $this->newLine();

        return $stats;
    }

    /** @param array<int, array{model_class: string, record_count: int, last_synced_at: ?string, columns: ?string}> $stats */
    private function checkIntegrity(Engine $engine, array $stats): void
    {
        if (empty($stats)) {
            return;
        }

        $this->line('3b. Integrity');
        foreach ($stats as $stat) {
            $ok = $engine->integrityCheck($stat['model_class']);
            $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("     {$icon} {$stat['model_class']}");
        }
        $this->newLine();
    }

    private function showConfig(): void
    {
        $this->line('4. Configuration');
        $keys = [
            'illumi-search.indexing', 'illumi-search.mode', 'illumi-search.fts5.tokenizer', 'illumi-search.fts5.processor',
            'illumi-search.fts5.detail', 'illumi-search.fts5.synchronous', 'illumi-search.fts5.temp_store',
            'illumi-search.fts5.columnsize', 'illumi-search.fts5.wal', 'illumi-search.fts5.busy_timeout',
            'illumi-search.tenancy.enabled', 'illumi-search.authorization.enabled',
        ];
        foreach ($keys as $key) {
            $value = config($key);
            $display = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            $this->line("   <info>{$key}</info> = {$display}");
        }
        $this->newLine();
    }

    private function validateConfig(): void
    {
        $this->line('4b. Config Validation');
        $rules = [
            ['illumi-search.mode', config('illumi-search.mode'), ['basic', 'advanced']],
            ['illumi-search.indexing', config('illumi-search.indexing'), ['queue', 'sync', 'manual']],
            ['illumi-search.fts5.processor', config('illumi-search.fts5.processor'), ['unicode', 'stemming']],
            ['illumi-search.fts5.detail', config('illumi-search.fts5.detail'), ['full', 'column', 'none']],
            ['illumi-search.fts5.synchronous', config('illumi-search.fts5.synchronous'), ['NORMAL', 'FULL', 'OFF']],
            ['illumi-search.fts5.temp_store', config('illumi-search.fts5.temp_store'), ['MEMORY', 'FILE', 'DEFAULT']],
            ['illumi-search.fts5.columnsize', config('illumi-search.fts5.columnsize'), []],
            ['illumi-search.fts5.wal', config('illumi-search.fts5.wal'), []],
            ['illumi-search.fts5.busy_timeout', config('illumi-search.fts5.busy_timeout'), []],
        ];

        foreach ($rules as [$key, $value, $accepted]) {
            $isValid = match ($key) {
                'illumi-search.fts5.columnsize' => in_array((int) $value, [0, 1], true),
                'illumi-search.fts5.wal' => is_bool($value),
                'illumi-search.fts5.busy_timeout' => is_numeric($value) && (int) $value >= 0,
                default => in_array($value, $accepted, true),
            };

            $this->line('   '.($isValid ? '<fg=green>✓</>' : '<fg=red>✗</>')." {$key} = ".json_encode($value));

            if (! $isValid) {
                $expected = $key === 'illumi-search.fts5.busy_timeout'
                    ? 'must be a non-negative integer'
                    : 'accepted: '.implode('|', $accepted);
                $this->line("     <fg=yellow>⚠ Expected {$expected}</>");
                $this->allOk = false;
            }
        }
        $this->newLine();
    }

    private function checkOperators(): void
    {
        $this->line('5. FTS5 Operators');
        $rawOps = SqliteEngine::getRawSupportedOperators();
        $allowedOps = SqliteEngine::getSupportedOperators();

        foreach (['AND', 'OR', 'NOT', 'NEAR'] as $op) {
            $sqlite = in_array($op, $rawOps, true);
            $configOk = in_array($op, $allowedOps, true);
            $note = match (true) {
                $configOk => 'SQLite: ✓, Config: allowed',
                $sqlite => 'SQLite: ✓, Config: restricted',
                default => 'SQLite: ✗, Config: —',
            };
            $this->line('   '.($configOk ? '<fg=green>✓</>' : '<fg=red>✗</>')." {$op} ({$note})");
        }
        $this->newLine();
    }
}
