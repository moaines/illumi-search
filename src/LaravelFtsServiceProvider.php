<?php

namespace Moaines\LaravelFts;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Moaines\LaravelFts\Console\Commands\FtsCheckCommand;
use Moaines\LaravelFts\Console\Commands\FtsDoctorCommand;
use Moaines\LaravelFts\Console\Commands\FtsOptimizeCommand;
use Moaines\LaravelFts\Console\Commands\FtsRebuildCommand;
use Moaines\LaravelFts\Console\Commands\FtsSearchCommand;
use Moaines\LaravelFts\Console\Commands\FtsStatusCommand;
use Moaines\LaravelFts\Console\Commands\FtsSuggestCommand;
use Moaines\LaravelFts\Console\Commands\FtsSyncCommand;
use Moaines\LaravelFts\Contracts\FtsEngine;
use Moaines\LaravelFts\Contracts\TextProcessor;
use Moaines\LaravelFts\Engines\SqliteFtsEngine;
use Moaines\LaravelFts\Exceptions\FtsException;
use Moaines\LaravelFts\Exceptions\FtsExtensionMissingException;
use Moaines\LaravelFts\FtsSpellcheck;
use Moaines\LaravelFts\Text\StemmingTextProcessor;
use Moaines\LaravelFts\Text\UnicodeTextProcessor;

class LaravelFtsServiceProvider extends ServiceProvider
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
                @mkdir($dir, 0755, true);
            }

            $engine = new SqliteFtsEngine($fullPath);
            $engine->setTextProcessor($app->make(TextProcessor::class));

            return $engine;
        });

        $this->app->singleton(TextProcessor::class, function () {
            $processor = config('fts.fts5.processor', 'unicode');

            return $processor === 'stemming'
                ? new StemmingTextProcessor
                : new UnicodeTextProcessor;
        });

        $this->app->bind(FtsSpellcheck::class, function ($app) {
            return new FtsSpellcheck($app->make(FtsEngine::class));
        });

        $this->app->alias(FtsEngine::class, 'laravel-fts.engine');
        $this->app->alias(TextProcessor::class, 'laravel-fts.text-processor');
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
            FtsSuggestCommand::class,
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
            'throttle:' . config('fts.api.rate_limit', 30) . ',1',
        ])
            ->prefix(config('fts.api.prefix', 'api/search'))
            ->group(function () {
                Route::get('/', \Moaines\LaravelFts\Http\Controllers\SearchApiController::class);
            });
    }
}
