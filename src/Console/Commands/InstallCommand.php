<?php

namespace Moaines\IllumiSearch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallCommand extends Command
{
    protected $signature = 'illumi-search:install
        {--force : Overwrite existing config without confirmation}';

    protected $description = 'Interactive installation and configuration of illumi-search';

    private const ENGINES = [
        'sqlite' => ['ext' => 'sqlite3', 'speed' => '⚡⚡⚡', 'multilang' => true,  'max_docs' => '~50k'],
        'mysql'  => ['ext' => 'pdo_mysql', 'speed' => '⚡⚡',   'multilang' => false, 'max_docs' => '~50k'],
        'pgsql'  => ['ext' => 'pdo_pgsql', 'speed' => '⚡⚡⚡', 'multilang' => true,  'max_docs' => '> 500k'],
        'file'   => ['ext' => 'mbstring',  'speed' => '⚡',    'multilang' => true,  'max_docs' => '> 1M'],
    ];

    private const RECOMMENDATIONS = [
        'small' => [
            'sqlite' => ['recommended', 'green'],
            'pgsql'  => ['', ''],
            'mysql'  => ['', ''],
            'file'   => ['', ''],
        ],
        'medium' => [
            'sqlite' => ['plafond ~50k', 'yellow'],
            'pgsql'  => ['recommended', 'green'],
            'mysql'  => ['CJK limité', 'yellow'],
            'file'   => ['', ''],
        ],
        'large' => [
            'sqlite' => ['plafond ~50k', 'yellow'],
            'pgsql'  => ['recommended', 'green'],
            'mysql'  => ['avec config innodb', 'yellow'],
            'file'   => ['avec précaution', 'yellow'],
        ],
    ];

    private const STORAGE_DIRS = ['storage/', 'storage/app/'];

    private const INSTALL_CMDS = [
        'Linux' => [
            'sqlite3'   => 'apt install php-sqlite3',
            'pdo_mysql' => 'apt install php-mysql',
            'pdo_pgsql' => 'apt install php-pgsql',
            'mbstring'  => 'apt install php-mbstring',
        ],
        'Darwin' => [
            'sqlite3'   => 'brew install php-sqlite3',
            'pdo_mysql' => 'brew install php-mysql',
            'pdo_pgsql' => 'brew install php-pgsql',
            'mbstring'  => 'brew install php-mbstring',
        ],
    ];

    private array $available = [];
    private ?string $projectSize = null;

    public function handle(): int
    {
        $this->info('🔧 illumi-search Installer');
        $this->newLine();

        if ($this->alreadyConfigured()) {
            return Command::FAILURE;
        }

        $this->checkEnvironment();
        $this->estimateProjectSize();
        $choice = $this->selectEngine();
        $dbChoice = $this->resolveDatabase($choice);
        $this->generateEnvConfig($choice, $dbChoice);
        $this->printNextSteps($choice);

        return Command::SUCCESS;
    }

    // ─── 1. Déjà configuré ───────────────────────────

    private function alreadyConfigured(): bool
    {
        if (config('illumi-search.driver') === null) {
            return false;
        }
        if ($this->option('force')) {
            return false;
        }

        $this->error('⚠  illumi-search is already configured.');
        $this->line('   Current: <fg=red>ILLUMI_SEARCH_DRIVER=' . config('illumi-search.driver') . '</>');

        if (! $this->confirm('   Reconfigure?', false)) {
            $this->info('   Aborted.');
            return true;
        }

        return false;
    }

    // ─── 2. Environnement ────────────────────────────

    private function checkEnvironment(): void
    {
        $this->line('1. Checking environment...');
        $this->newLine();

        $this->checkExtensions();
        $this->showInstallHints();
        $this->checkStorage();
        $this->abortIfNoEngine();
    }

    private function checkExtensions(): void
    {
        foreach (self::ENGINES as $name => $cfg) {
            $ok = extension_loaded($cfg['ext']);
            if ($ok) {
                $this->available[$name] = $cfg;
            }
            $this->line('   ' . ($ok ? '<fg=green>✓</>' : '<fg=red>✗</>')
                . " ext-{$cfg['ext']}  "
                . ($ok ? '' : "<fg=yellow>({$name} disabled)</>"));
        }
        $this->newLine();
    }

    private function showInstallHints(): void
    {
        $missing = array_filter(self::ENGINES, fn ($n) => ! isset($this->available[$n]), ARRAY_FILTER_USE_KEY);
        if (empty($missing)) {
            return;
        }

        $os = PHP_OS_FAMILY;
        $this->line('   Missing extensions can be installed with:');
        foreach ($missing as $name => $cfg) {
            $cmd = self::INSTALL_CMDS[$os][$cfg['ext']] ?? '';
            if ($cmd) {
                $this->line("     <fg=yellow>{$name}:</> {$cmd}");
            }
        }
        $this->newLine();
    }

    private function checkStorage(): void
    {
        foreach (self::STORAGE_DIRS as $label) {
            $path = $label === 'storage/' ? storage_path() : storage_path('app');
            $writable = is_dir($path) && is_writable($path);
            $this->line('   ' . ($writable ? '<fg=green>✓</>' : '<fg=red>✗</>')
                . " {$label} (" . ($writable ? 'writable' : 'not writable') . ')');
        }
        $this->newLine();
    }

    private function abortIfNoEngine(): void
    {
        if (empty($this->available)) {
            $this->error('✗ No engine available.');
            $this->line('   Install one of the PHP extensions above, then run the installer again.');
            exit(Command::FAILURE);
        }
    }

    // ─── 3. Estimer la taille ────────────────────────

    private function estimateProjectSize(): void
    {
        $this->line('2. Estimating project scale...');

        if (! $this->tryAutoDetectSize()) {
            $this->askProjectSize();
        }
    }

    private function tryAutoDetectSize(): bool
    {
        try {
            $driver = config('database.default');
            $conn = DB::connection($driver);
            $result = null;

            if ($driver === 'mysql') {
                $result = $conn->selectOne("
                    SELECT SUM(table_rows) AS total
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                      AND table_type = 'BASE TABLE'
                ");
            } elseif ($driver === 'pgsql') {
                $result = $conn->selectOne("
                    SELECT SUM(n_live_tup) AS total
                    FROM pg_stat_user_tables
                ");
            }

            if ($result && ($total = (int) ($result->total ?? 0)) > 0) {
                $human = $total >= 1000 ? round($total / 1000, 1) . 'k' : (string) $total;
                $this->line("   <fg=green>✓</> Detected ~{$human} rows in database.");
                $this->projectSize = match (true) {
                    $total < 10000  => 'small',
                    $total < 100000 => 'medium',
                    default         => 'large',
                };
                $this->newLine();
                return true;
            }
        } catch (\Throwable) {
            // Fall through
        }

        return false;
    }

    private function askProjectSize(): void
    {
        $this->newLine();
        $this->line('   Could not detect database size automatically.');
        $this->line('   How many records do you expect in your search index?');
        $this->newLine();

        $choice = $this->choice(
            '   Expected volume',
            ['small'    => 'Small (< 10k) — blog, portfolio',
             'medium'   => 'Medium (10k–100k) — CMS, e-commerce',
             'large'    => 'Large (> 100k) — SaaS, marketplace',
             'not-sure' => 'Not sure — skip'],
            'not-sure'
        );
        $this->projectSize = $choice === 'not-sure' ? null : $choice;
        $this->newLine();
    }

    // ─── 4. Sélection engine ───────────────────────────

    private function selectEngine(): string
    {
        $this->line('3. Select an engine:');
        $this->newLine();

        $rows = [];
        $choices = [];
        $index = 1;
        $defaultKey = array_key_first($this->available) ?? 'sqlite';

        foreach ($this->available as $name => $cfg) {
            [$label, $style] = $this->recommendation($name);
            $tag = $this->formatTag($label, $style, $cfg['max_docs']);
            $isDefault = $name === 'sqlite' ? ' (default)' : '';
            $rows[] = [$index, strtoupper($name) . $isDefault, $cfg['speed'], $cfg['multilang'] ? '✅' : '⚠ Latin', $tag];
            $choices[$index] = $name;
            $index++;
        }

        $this->table(['', 'Engine', 'Speed', 'Multi-lang', 'Max docs'], $rows);
        $this->newLine();

        foreach (self::ENGINES as $name => $cfg) {
            if (! isset($this->available[$name])) {
                $this->line("   <fg=gray>✗ {$name}</> — install ext-{$cfg['ext']} to enable");
            }
        }
        $this->newLine();

        return $choices[(int) $this->ask('   Your choice', '1')] ?? $defaultKey;
    }

    /** @return array{string, string} */
    private function recommendation(string $engine): array
    {
        $size = $this->projectSize ?? 'unknown';
        $map = self::RECOMMENDATIONS[$size] ?? [];

        return $map[$engine] ?? ['', ''];
    }

    private function formatTag(string $label, string $style, string $maxDocs): string
    {
        if ($label === '') {
            return $maxDocs;
        }

        $tag = match ($style) {
            'green'  => "<fg=green>← {$label}</>",
            'yellow' => "<fg=yellow>← {$label}</>",
            default  => "← {$label}",
        };

        return "{$maxDocs} {$tag}";
    }

    // ─── 5. Base de données ──────────────────────────

    private function resolveDatabase(string $engine): ?array
    {
        if (! in_array($engine, ['mysql', 'pgsql'], true)) {
            return null;
        }

        $this->newLine();
        $this->line("4. Database configuration for {$engine}:");

        return $this->confirm('   Use the app database connection?', true)
            ? $this->appDatabaseConfig($engine)
            : $this->manualDatabaseConfig($engine);
    }

    private function appDatabaseConfig(string $engine): array
    {
        return [
            'host' => config("database.connections.{$engine}.host", '127.0.0.1'),
            'port' => config("database.connections.{$engine}.port", $engine === 'mysql' ? '3306' : '5432'),
            'database' => config("database.connections.{$engine}.database", 'illumi_search'),
        ];
    }

    private function manualDatabaseConfig(string $engine): array
    {
        $this->newLine();
        $this->line('   Enter dedicated search database details:');

        return [
            'host' => $this->ask('   Host', '127.0.0.1'),
            'port' => $this->ask('   Port', $engine === 'mysql' ? '3306' : '5432'),
            'database' => $this->ask('   Database name', 'illumi_search'),
        ];
    }

    // ─── 6. Génération .env ───────────────────────────

    private function generateEnvConfig(string $engine, ?array $db): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath) || ! is_writable($envPath)) {
            $this->printManualEnv($engine, $db);
            return;
        }

        $content = file_get_contents($envPath);
        $this->setEnvValue($content, 'ILLUMI_SEARCH_DRIVER', $engine);

        if ($engine === 'sqlite') {
            $this->setEnvValue($content, 'ILLUMI_SEARCH_DATABASE_PATH', 'database/search.sqlite');
        }

        if ($db) {
            $p = strtoupper($engine);
            $this->setEnvValue($content, "ILLUMI_SEARCH_{$p}_HOST", $db['host']);
            $this->setEnvValue($content, "ILLUMI_SEARCH_{$p}_PORT", $db['port']);
            $this->setEnvValue($content, "ILLUMI_SEARCH_{$p}_DATABASE", $db['database']);
        }

        file_put_contents($envPath, $content);
        $this->line('   <fg=green>✓</> .env updated.');
        $this->newLine();

        $this->tryMysqlTuning($engine);
    }

    private function printManualEnv(string $engine, ?array $db): void
    {
        $this->warn('   Cannot write to .env — add the following lines manually:');
        $this->line("   ILLUMI_SEARCH_DRIVER={$engine}");
        if (! $db) {
            return;
        }

        $p = strtoupper($engine);
        $this->line("   ILLUMI_SEARCH_{$p}_HOST={$db['host']}");
        $this->line("   ILLUMI_SEARCH_{$p}_PORT={$db['port']}");
        $this->line("   ILLUMI_SEARCH_{$p}_DATABASE={$db['database']}");
    }

    private function tryMysqlTuning(string $engine): void
    {
        if ($engine !== 'mysql' || $this->projectSize !== 'large') {
            return;
        }

        try {
            DB::connection('illumi-search-mysql')
                ->statement('SET GLOBAL innodb_ft_min_token_size = 1');
            $this->line('   <fg=green>✓</> innodb_ft_min_token_size set to 1.');
        } catch (\Throwable) {
            $this->line('   <fg=yellow>⚠</> Could not set innodb_ft_min_token_size=1.');
            $this->line('   Add to my.cnf manually: [mysqld] -> innodb_ft_min_token_size=1');
        }
        $this->newLine();
    }

    private function setEnvValue(string &$content, string $key, string $value): void
    {
        $line = "{$key}={$value}";
        if (preg_match("/^{$key}=.*/m", $content)) {
            $content = preg_replace("/^{$key}=.*/m", $line, $content);
        } else {
            $content .= "\n{$line}";
        }
    }

    // ─── 7. Étapes suivantes ─────────────────────────

    private function printNextSteps(string $engine): void
    {
        $this->info('✅ illumi-search installed!');
        $this->newLine();
        $this->line('   Next steps:');
        $this->newLine();
        $this->line('   1. Add the Searchable trait to your model:');
        $this->line('       use Moaines\IllumiSearch\Searchable;');
        $this->newLine();
        $this->line('   2. Configure searchable columns:');
        $this->line('       protected array $searchable = [');
        $this->line("           'title' => ['weight' => 3],");
        $this->line("           'body',");
        $this->line('       ];');
        $this->newLine();
        $this->line('   3. Rebuild the search index:');
        $this->line('       php artisan illumi-search:rebuild');
        $this->newLine();
        $this->line('   4. Search!');
        $this->line('       $results = IllumiSearch::query("laravel")->get();');
        $this->newLine();
        $this->line('   📖 Full documentation: https://github.com/moaines/illumi-search');
        $this->newLine();

        if ($engine === 'mysql') {
            $this->line('   ⚠ MySQL recommendation for CJK support:');
            $this->line('   Add to my.cnf then rebuild:');
            $this->line('   [mysqld]');
            $this->line('   innodb_ft_min_token_size = 1');
            $this->newLine();
        }

        if ($this->projectSize === 'large' && $engine === 'sqlite') {
            $this->line('   ⚠ Large project detected. Consider PostgreSQL for > 50k docs.');
            $this->newLine();
        }
    }
}
