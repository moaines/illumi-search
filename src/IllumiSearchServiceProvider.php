<?php

namespace Moaines\IllumiSearch;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Moaines\IllumiSearch\Console\Commands\CheckCommand;
use Moaines\IllumiSearch\Console\Commands\DiscoverFilamentCommand;
use Moaines\IllumiSearch\Console\Commands\DoctorCommand;
use Moaines\IllumiSearch\Console\Commands\OptimizeCommand;
use Moaines\IllumiSearch\Console\Commands\RebuildCommand;
use Moaines\IllumiSearch\Console\Commands\SearchCommand;
use Moaines\IllumiSearch\Console\Commands\StatusCommand;
use Moaines\IllumiSearch\Console\Commands\SyncCommand;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Contracts\TextProcessor;
use Moaines\IllumiSearch\Engines\MySqlEngine;
use Moaines\IllumiSearch\Engines\SqliteEngine;
use Moaines\IllumiSearch\Exceptions\IllumiSearchException;
use Moaines\IllumiSearch\Http\Controllers\SearchApiController;
use Moaines\IllumiSearch\Http\Requests\SearchApiRequest;
use Moaines\IllumiSearch\Result;
use Moaines\IllumiSearch\Support\SnippetService;
use Moaines\IllumiSearch\Text\FallbackTextProcessor;
use Moaines\IllumiSearch\Text\StemmingTextProcessor;
use Moaines\IllumiSearch\Text\UnicodeTextProcessor;

class IllumiSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/illumi-search.php', 'illumi-search');

        $this->app->singleton(TenantManager::class, function () {
            return new TenantManager;
        });

        $this->app->singleton(Engine::class, function ($app) {
            $driver = config('illumi-search.driver', 'sqlite');

            if ($driver === 'mysql') {
                return new MySqlEngine($app->make(SnippetService::class));
            }

            $path = $app['config']->get('illumi-search.database_path', 'app/search/search-index.sqlite');
            $fullPath = str_starts_with($path, '/') ? $path : $app->storagePath($path);

            // Apply tenant isolation if enabled
            $tenantManager = $app->make(TenantManager::class);
            $fullPath = $tenantManager->tenantDatabasePath($fullPath);

            $dir = dirname($fullPath);
            File::ensureDirectoryExists($dir);

            $engine = new SqliteEngine(
                databasePath: $fullPath,
                snippets: $app->make(SnippetService::class),
            );
            $engine->setTextProcessor($app->make(TextProcessor::class));

            return $engine;
        });

        $this->app->singleton(TextProcessor::class, function () {
            $processor = config('illumi-search.fts5.processor', 'unicode');

            if ($processor === 'stemming') {
                return extension_loaded('intl')
                    ? new StemmingTextProcessor
                    : new FallbackTextProcessor;
            }

            return extension_loaded('intl')
                ? new UnicodeTextProcessor
                : new FallbackTextProcessor;
        });

        $this->app->singleton(SnippetService::class);

        $this->app->bind(Spellcheck::class, function ($app) {
            return new Spellcheck($app->make(Engine::class));
        });

        $this->app->alias(Engine::class, 'illumi-search.engine');
        $this->app->alias(TextProcessor::class, 'illumi-search.text-processor');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/illumi-search.php' => config_path('illumi-search.php'),
            ], 'illumi-search-config');
        }

        $this->commands([
            RebuildCommand::class,
            SyncCommand::class,
            CheckCommand::class,
            SearchCommand::class,
            StatusCommand::class,
            DiscoverFilamentCommand::class,
            OptimizeCommand::class,
            DoctorCommand::class,
        ]);

        $this->registerApiRoutes();
    }

    protected function registerApiRoutes(): void
    {
        if (! config('illumi-search.api.enabled', false)) {
            return;
        }

        Route::middleware([
            'api',
            'throttle:'.config('illumi-search.api.rate_limit', 30).',1',
        ])
            ->prefix(config('illumi-search.api.prefix', 'api/search'))
            ->group(function () {
                Route::get('/', SearchApiController::class);
            });
    }
}
