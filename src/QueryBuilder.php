<?php

namespace Moaines\IllumiSearch;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Support\IllumiSearchConfig;

class QueryBuilder
{
    private string $query = '';

    /** @var array<class-string> */
    private array $modelClasses = [];

    private string $mode = 'advanced';
    private int $limit = 20;
    private int $offset = 0;
    private ?Engine $engine = null;
    private bool $authorizationEnabled = false;
    private ?Authenticatable $user = null;
    private ?int $totalCache = null;

    public function __construct(?Engine $engine = null)
    {
        $this->engine = $engine;
    }

    /**
     * Set the search query string.
     *
     * @example IllumiSearch::query('laravel php') ...;
     *
     * @return $this
     */
    public function query(string $query): static
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Limit search to a single model class.
     *
     * @example IllumiSearch::query('laravel')->model(Post::class)->get()
     */
    public function model(string $modelClass): static
    {
        $this->modelClasses = [$modelClass];

        return $this;
    }

    /**
     * Search across multiple model classes.
     *
     * @example IllumiSearch::query('php')->models([Post::class, Comment::class])->get()
     */
    /** @param array<class-string> $modelClasses */
    public function models(array $modelClasses): static
    {
        $this->modelClasses = $modelClasses;

        return $this;
    }

    public function mode(string $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = max(1, min($limit, app(IllumiSearchConfig::class)->maxResults()));

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function engine(Engine $engine): static
    {
        $this->engine = $engine;

        return $this;
    }

    /**
     * Enable authorization filtering via Eloquent Policies.
     * Unauthorized results are removed from the collection.
     */
    public function withAuthorization(?Authenticatable $user = null): static
    {
        $this->authorizationEnabled = true;

        if ($user !== null) {
            $this->user = $user;
        }

        return $this;
    }

    /**
     * Set the user for authorization checks.
     */
    public function user(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Execute the search and return results.
     *
     * @example $results = IllumiSearch::query('laravel')->model(Post::class)->get()
     *
     * @return Collection<int, Result>
     */
    public function get(): Collection
    {
        $modelClasses = $this->resolveModelClasses();

        $results = collect($this->resolveEngine()->search(
            query: $this->query,
            modelClasses: $modelClasses,
            limit: $this->limit,
            offset: $this->offset,
            mode: $this->mode,
        ));

        if ($this->authorizationEnabled || app(IllumiSearchConfig::class)->authorizationEnabled()) {
            $results = $this->filterAuthorized($results);
        }

        return $results;
    }

    /**
     * @param  Collection<int, Result>  $results
     * @return Collection<int, Result>
     */
    protected function filterAuthorized(Collection $results): Collection
    {
        $user = $this->user ?? Auth::user();

        if ($user === null) {
            return $results;
        }

        $grouped = $results->groupBy('modelClass');
        $models = [];

        foreach ($grouped as $class => $entries) {
            if (! class_exists($class)) {
                continue;
            }

            $ids = $entries->pluck('modelId')->unique()->values();
            $models[$class] = $class::findMany($ids)->keyBy->getKey();
        }

        return $results->filter(function (Result $result) use ($user, $models): bool {
            $model = $models[$result->modelClass][$result->modelId] ?? null;

            if ($model === null) {
                return false;
            }

            if (method_exists($user, 'can')) {
                return $user->can('view', $model);
            }

            return true;
        })->values();
    }

    /**
     * Get the total count of matching results without retrieving them.
     *
     * @example IllumiSearch::query('laravel')->model(Post::class)->count()
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        if ($this->totalCache !== null) {
            return $this->totalCache;
        }

        return $this->totalCache = $this->resolveEngine()->count(
            query: $this->query,
            modelClasses: $this->resolveModelClasses(),
        );
    }

    /**
     * Paginate search results.
     *
     * @example IllumiSearch::query('laravel')->model(Post::class)->paginate(15)
     *
     * @param  int<1, max>  $perPage
     * @return Paginator<int, Result>
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): Paginator
    {
        $this->modelClasses = $this->resolveModelClasses();

        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $this->limit = $perPage;
        $this->offset = max(0, ($page - 1) * $perPage);

        $results = $this->get();
        $first = $results->first();
        $total = $first instanceof Result
            ? ($first->totalCount ?? $this->count())
            : $this->count();

        return new Paginator(
            items: $results,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        );
    }

    private function resolveEngine(): Engine
    {
        if ($this->engine === null) {
            $this->engine = app(Engine::class);
        }

        return $this->engine;
    }

    /** @return array<class-string> */
    private function resolveModelClasses(): array
    {
        if (! empty($this->modelClasses)) {
            return $this->modelClasses;
        }

        return $this->resolveEngine()->getIndexedModelClasses();
    }
}
