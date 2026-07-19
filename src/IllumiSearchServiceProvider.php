<?php

namespace Moaines\IllumiSearch;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Moaines\IllumiSearch\Console\Commands\FtsCheckCommand;
use Moaines\IllumiSearch\Console\Commands\FtsDiscoverFilamentCommand;
use Moaines\IllumiSearch\Console\Commands\FtsDoctorCommand;
use Moaines\IllumiSearch\Console\Commands\FtsOptimizeCommand;
use Moaines\IllumiSearch\Console\Commands\FtsRebuildCommand;
use Moaines\IllumiSearch\Console\Commands\FtsSearchCommand;
use Moaines\IllumiSearch\Console\Commands\FtsStatusCommand;
use Moaines\IllumiSearch\Console\Commands\FtsSyncCommand;
use Moaines\IllumiSearch\Contracts\FtsEngine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Engines\SqliteFtsEngine;
use Moaines\IllumiSearch\Exceptions\FtsException;
use Moaines\IllumiSearch\Exceptions\FtsExtensionMissingException;
use Moaines\IllumiSearch\Http\Controllers\SearchApiController;
use Moaines\IllumiSearch\Support\SnippetService;
use Moaines\IllumiSearch\Text\StemmingTextProcessor;
use Moaines\IllumiSearch\Text\UnicodeTextProcessor;

class IllumiSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fts.php', 'fts');

        $this->app->singleton(TenantManager::class, function () {
            return new TenantManager;
        });

        $this->app->singleton(FtsEngine::class, function ($app) {
            $path = $app['config']->get('fts.database_path', 'app/fts/fts-index.sqlite');
            $fullPath = str_starts_with($path, '/') ? $path : $app->storagePath($path);

            // Apply tenant isolation if enabled
            $tenantManager = $app->make(TenantManager::class);
            $fullPath = $tenantManager->tenantDatabasePath($fullPath);

            $dir = dirname($fullPath);
            if (! is_dir($dir)) {
                try {
                    mkdir($dir, 0755, true);
                } catch (\Exception) {
                    // Best-effort: will fail later with a clearer error
                }
            }

            $engine = new SqliteFtsEngine(
                databasePath: $fullPath,
                snippets: $app->make(SnippetService::class),
            );
            $engine->setTextProcessor($app->make(TextProcessor::class));

            return $engine;
        });

        $this->app->singleton(TextProcessor::class, function () {
            $processor = config('fts.fts5.processor', 'unicode');

            return $processor === 'stemming'
                ? new StemmingTextProcessor
                : new UnicodeTextProcessor;
        });

        $this->app->singleton(SnippetService::class);

        $this->app->bind(FtsSpellcheck::class, function ($app) {
            return new FtsSpellcheck($app->make(FtsEngine::class));
        });

        $this->app->alias(FtsEngine::class, 'illumi-search.engine');
        $this->app->alias(TextProcessor::class, 'illumi-search.text-processor');
    }

    public function boot(): void
    {
        $this->validateRequirements();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/fts.php' => config_path('fts.php'),
            ], 'fts-config');
        }

        $this->commands([
            FtsRebuildCommand::class,
            FtsSyncCommand::class,
            FtsCheckCommand::class,
            FtsSearchCommand::class,
            FtsStatusCommand::class,
            FtsDiscoverFilamentCommand::class,
            FtsOptimizeCommand::class,
            FtsDoctorCommand::class,
        ]);

        $this->registerApiRoutes();
    }

    protected function validateRequirements(): void
    {
        if (! extension_loaded('sqlite3')) {
            throw new FtsExtensionMissingException('sqlite3');
        }

        if (! extension_loaded('intl')) {
            throw new FtsExtensionMissingException('intl');
        }

        if (! extension_loaded('mbstring')) {
            throw new FtsExtensionMissingException('mbstring');
        }

        try {
            $db = new \SQLite3(':memory:');
            $db->exec('CREATE VIRTUAL TABLE _fts_validation_test USING fts5(content)');
            $db->close();
        } catch (\Exception $e) {
            throw FtsException::fts5NotAvailable();
        }
    }

    protected function registerApiRoutes(): void
    {
        if (! config('fts.api.enabled', false)) {
            return;
        }

        Route::middleware([
            'api',
            'throttle:'.config('fts.api.rate_limit', 30).',1',
        ])
            ->prefix(config('fts.api.prefix', 'api/search'))
            ->group(function () {
                Route::get('/', SearchApiController::class);
            });
    }
}
