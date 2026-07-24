<?php

namespace Moaines\IllumiSearch\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Moaines\IllumiSearch\QueryBuilder;
use Moaines\IllumiSearch\Spellcheck;

/**
 * @method static QueryBuilder query(string $query)
 *
 * @see QueryBuilder
 */
class IllumiSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'illumi-search.engine';
    }

    /**
     * Start a new search query.
     *
     * @example IllumiSearch::query('laravel')->model(Post::class)->get()
     *
     * @param  string  $query  The search terms
     */
    public static function query(string $query): QueryBuilder
    {
        return app(QueryBuilder::class)->query($query);
    }

    /**
     * Get spelling suggestions for a query term.
     *
     * @example IllumiSearch::didYouMean('laravell', [Post::class])
     *
     * @param  string  $query  The (potentially misspelled) search term
     * @param  string[]  $modelClasses  Optional model classes to scope suggestions
     * @return Collection<int, string>
     */
    public static function didYouMean(string $query, array $modelClasses = []): Collection
    {
        return app(Spellcheck::class)->suggest($query, $modelClasses);
    }
}
