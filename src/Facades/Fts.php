<?php

namespace Moaines\LaravelFts\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Moaines\LaravelFts\FtsQueryBuilder;
use Moaines\LaravelFts\FtsSpellcheck;

/**
 * @method static FtsQueryBuilder query(string $query)
 *
 * @see \Moaines\LaravelFts\Contracts\FtsEngine
 */
class Fts extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-fts.engine';
    }

    public static function query(string $query): FtsQueryBuilder
    {
        return app(FtsQueryBuilder::class)->query($query);
    }

    /**
     * Get spelling suggestions for a query term.
     *
     * @param string $query The (potentially misspelled) search term
     * @param array $modelClasses Optional model classes to scope suggestions
     * @return Collection<string>
     */
    public static function didYouMean(string $query, array $modelClasses = []): Collection
    {
        return app(FtsSpellcheck::class)->suggest($query, $modelClasses);
    }
}
