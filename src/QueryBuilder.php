<?php

namespace Moaines\IllumiSearch;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
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

    private ?string $boostColumn = null;
    private float $boostFactor = 0.1;

    /** @var array<int, array{column: string, operator: string, value: mixed}> */
    private array $whereClauses = [];

    private ?string $aggregateColumn = null;

    public function __construct(?Engine $engine = null)
    {
        $this->engine = $engine;
    }

    /**
     * Set the search query string.
     *
     * @example IllumiSearch::query('laravel php') ...;
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
     *
     * @param  array<class-string>  $modelClasses
     */
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

    // ─── Boost (recency/popularity) ─────────────────────

    /**
     * Boost results based on a model attribute (e.g. created_at, popularity).
     * Applied as a post-processing step after search.
     *
     * @param  string  $column  Eloquent model attribute (e.g. 'created_at', 'updated_at', 'published_at')
     * @param  float  $factor  Boost intensity (0.1 = +10% per day of recency, up to 30 days)
     */
    public function boost(string $column, float $factor = 0.1): static
    {
        $this->boostColumn = $column;
        $this->boostFactor = $factor;

        return $this;
    }

    // ─── Facets (WHERE filters) ─────────────────────────

    /**
     * Filter results by a field. Applied after the index search (PHP-based).
     *
     * @param  string  $column    Eloquent model attribute to filter on
     * @param  mixed   $operator  Operator: '=', '!=', '>', '<', '>=', '<=', or the value itself for '='
     * @param  mixed   $value     Value to compare against (null if $operator is the value)
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if ($value !== null) {
            $this->whereClauses[] = ['column' => $column, 'operator' => $operator, 'value' => $value];
        } elseif (is_array($operator)) {
            $this->whereClauses[] = ['column' => $column, 'operator' => 'IN', 'value' => $operator];
        } elseif ($operator !== null) {
            $this->whereClauses[] = ['column' => $column, 'operator' => '=', 'value' => $operator];
        } else {
            $this->whereClauses[] = ['column' => $column, 'operator' => '=', 'value' => true];
        }

        return $this;
    }

    /**
     * Filter results where column value is in the given array.
     *
     * @param  string  $column  Eloquent model attribute
     * @param  array  $values  Array of allowed values
     */
    public function whereIn(string $column, array $values): static
    {
        $this->whereClauses[] = ['column' => $column, 'operator' => 'IN', 'value' => $values];

        return $this;
    }

    /**
     * Filter results where column value is NOT in the given array.
     *
     * @param  string  $column  Eloquent model attribute
     * @param  array  $values  Array of excluded values
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->whereClauses[] = ['column' => $column, 'operator' => 'NOT_IN', 'value' => $values];

        return $this;
    }

    /**
     * Filter results where column value is null.
     *
     * @param  string  $column  Eloquent model attribute
     */
    public function whereNull(string $column): static
    {
        $this->whereClauses[] = ['column' => $column, 'operator' => 'NULL', 'value' => null];

        return $this;
    }

    /**
     * Filter results where column value is NOT null.
     *
     * @param  string  $column  Eloquent model attribute
     */
    public function whereNotNull(string $column): static
    {
        $this->whereClauses[] = ['column' => $column, 'operator' => 'NOT_NULL', 'value' => null];

        return $this;
    }

    /**
     * Filter results where column value is between min and max.
     *
     * @param  string  $column  Eloquent model attribute
     * @param  array{int, int}  $range  [min, max] inclusive
     */
    public function whereBetween(string $column, array $range): static
    {
        $this->whereClauses[] = ['column' => $column, 'operator' => 'BETWEEN', 'value' => $range];

        return $this;
    }

    // ─── Aggregations (GROUP BY) ────────────────────────

    /**
     * Count results grouped by a model attribute.
     *
     * @param  string  $column  Eloquent model attribute to group by
     * @return Collection<string, int>  e.g. collect(['Category A' => 42, 'Category B' => 15])
     */
    public function aggregate(string $column): Collection
    {
        $this->aggregateColumn = $column;

        return collect($this->computeAggregation());
    }

    // ─── Execute ────────────────────────────────────

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

        if ($this->boostColumn !== null) {
            $results = $this->applyBoost($results);
        }

        if (! empty($this->whereClauses)) {
            $results = $this->applyWhere($results);
        }

        return $results;
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

    // ─── Private ────────────────────────────────────

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

    /**
     * Load Eloquent models grouped by class for a result collection.
     * Shared by applyWhere, computeAggregation, and filterAuthorized.
     *
     * @param  Collection<int, Result>  $results
     * @return array<string, array<int|string, Model|null>>
     */
    private function loadModels(Collection $results): array
    {
        $grouped = $results->groupBy('modelClass');
        $models = [];

        foreach ($grouped as $class => $entries) {
            if (! class_exists($class)) {
                continue;
            }

            $ids = $entries->pluck('modelId')->unique()->values();
            $models[$class] = $class::findMany($ids)->keyBy(fn ($m) => (string) $m->getKey());
        }

        return $models;
    }

    /** @param  Collection<int, Result>  $results */
    private function applyBoost(Collection $results): Collection
    {
        if ($this->boostFactor <= 0) {
            return $results;
        }

        $now = Carbon::now();

        $results->each(function (Result $r) use ($now) {
            $date = $r->model?->{$this->boostColumn};
            if ($date === null) {
                return;
            }

            $days = $date instanceof Carbon
                ? $date->diffInDays($now)
                : Carbon::parse($date)->diffInDays($now);

            // Boost decays over 30 days: new items get +30%, old items get 0%
            $recency = max(0, 30 - $days) / 30;
            $boost = 1 + $this->boostFactor * $recency * 3;
            $r->rank = min($r->rank * $boost, 100.0);
        });

        return $results->sortByDesc('rank')->values();
    }

    /** @param  Collection<int, Result>  $results */
    private function applyWhere(Collection $results): Collection
    {
        $models = $this->loadModels($results);

        return $results->filter(function (Result $result) use ($models): bool {
            $model = $models[$result->modelClass][(string) $result->modelId] ?? null;

            if ($model === null) {
                return false;
            }

            foreach ($this->whereClauses as $clause) {
                $col = $clause['column'];
                $actual = $model->{$col};

                if (! $this->matchesWhere($actual, $clause['operator'], $clause['value'])) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    private function matchesWhere(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '='  => $actual == $expected,
            '!=' => $actual != $expected,
            '>'  => $actual > $expected,
            '>=' => $actual >= $expected,
            '<'  => $actual < $expected,
            '<=' => $actual <= $expected,
            'IN' => is_array($expected) && in_array($actual, $expected, true),
            'NOT_IN' => is_array($expected) && ! in_array($actual, $expected, true),
            'NULL' => $actual === null,
            'NOT_NULL' => $actual !== null,
            'BETWEEN' => is_array($expected) && count($expected) === 2
                && $actual >= $expected[0] && $actual <= $expected[1],
            default => $actual == $expected,
        };
    }

    private function computeAggregation(): array
    {
        $modelClasses = $this->resolveModelClasses();
        $column = $this->aggregateColumn;

        $results = collect($this->resolveEngine()->search(
            query: $this->query,
            modelClasses: $modelClasses,
            limit: max($this->limit, app(IllumiSearchConfig::class)->maxResults()),
            offset: $this->offset,
            mode: $this->mode,
        ));

        $models = $this->loadModels($results);
        $aggregates = [];

        foreach ($models as $class => $classModels) {
            foreach ($classModels as $model) {
                $key = (string) ($model->{$column} ?? 'unknown');
                $aggregates[$key] = ($aggregates[$key] ?? 0) + 1;
            }
        }

        arsort($aggregates);

        return $aggregates;
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

        $models = $this->loadModels($results);

        return $results->filter(function (Result $result) use ($user, $models): bool {
            $model = $models[$result->modelClass][(string) $result->modelId] ?? null;

            if ($model === null) {
                return false;
            }

            if (method_exists($user, 'can')) {
                return $user->can('view', $model);
            }

            return true;
        })->values();
    }
}
