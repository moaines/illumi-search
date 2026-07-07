<?php

namespace Moaines\LaravelFts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Moaines\LaravelFts\Contracts\FtsEngine;

class FtsQueryBuilder
{
    private string $query = '';

    /** @var array<class-string> */
    private array $modelClasses = [];

    private string $mode = 'advanced';

    private int $limit = 20;

    private int $offset = 0;

    private ?FtsEngine $engine = null;

    private bool $authorizationEnabled = false;

    private ?Authenticatable $user = null;

    public function __construct(?FtsEngine $engine = null)
    {
        $this->engine = $engine;
    }

    public function query(string $query): static
    {
        $this->query = $query;

        return $this;
    }

    public function model(string $modelClass): static
    {
        $this->modelClasses = [$modelClass];

        return $this;
    }

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
        $this->limit = max(1, min($limit, config('fts.max_results', 50)));

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function engine(FtsEngine $engine): static
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

    public function get(): Collection
    {
        $modelClasses = $this->modelClasses;

        // Auto-discover all indexed models when none specified
        if (empty($modelClasses)) {
            $modelClasses = $this->resolveEngine()->getIndexedModelClasses();
        }

        $results = collect($this->resolveEngine()->search(
            query: $this->query,
            modelClasses: $modelClasses,
            limit: $this->limit,
            offset: $this->offset,
            mode: $this->mode,
        ));

        if ($this->authorizationEnabled || config('fts.authorization.enabled', false)) {
            $results = $this->filterAuthorized($results);
        }

        return $results;
    }

    protected function filterAuthorized(Collection $results): Collection
    {
        $user = $this->user ?? Auth::user();

        if ($user === null) {
            return $results;
        }

        return $results->filter(function (FtsResult $result) use ($user): bool {
            $modelClass = $result->modelClass;

            if (! class_exists($modelClass)) {
                return false;
            }

            try {
                $model = $result->model ?? $modelClass::find($result->modelId);
            } catch (\Exception) {
                return false;
            }

            if ($model === null) {
                return false;
            }

            if (method_exists($user, 'can')) {
                return $user->can('view', $model);
            }

            return true;
        })->values();
    }

    public function count(): int
    {
        $modelClasses = $this->modelClasses;

        if (empty($modelClasses)) {
            $modelClasses = $this->resolveEngine()->getIndexedModelClasses();
        }

        return $this->resolveEngine()->count(
            query: $this->query,
            modelClasses: $modelClasses,
        );
    }

    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): Paginator
    {
        $modelClasses = $this->modelClasses;

        if (empty($modelClasses)) {
            $modelClasses = $this->resolveEngine()->getIndexedModelClasses();
        }
        $this->modelClasses = $modelClasses;

        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $this->limit = $perPage;
        $this->offset = max(0, ($page - 1) * $perPage);

        $results = $this->get();
        $total = $this->count();

        return new Paginator(
            items: $results,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        );
    }

    private function resolveEngine(): FtsEngine
    {
        if ($this->engine === null) {
            $this->engine = app(FtsEngine::class);
        }

        return $this->engine;
    }
}
