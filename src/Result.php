<?php

namespace Moaines\IllumiSearch;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/** @implements \Illuminate\Contracts\Support\Arrayable<string, mixed> */
class Result implements Arrayable
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $id,
        public readonly string $modelClass,
        public readonly int|string $modelId,
        public readonly float $rank,
        public readonly string $title,
        public readonly ?string $summary = null,
        public readonly array $raw = [],
        public readonly bool $authorized = true,
        public readonly ?Model $model = null,
        public readonly ?int $totalCount = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function make(
        string $modelClass,
        int|string $modelId,
        float $rank,
        string $title,
        ?string $summary = null,
        array $raw = [],
        bool $authorized = true,
        ?Model $model = null,
        ?int $totalCount = null,
    ): self {
        return new self(
            id: "{$modelClass}:{$modelId}",
            modelClass: $modelClass,
            modelId: $modelId,
            rank: $rank,
            title: $title,
            summary: $summary,
            raw: $raw,
            authorized: $authorized,
            model: $model,
            totalCount: $totalCount,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'model_class' => $this->modelClass,
            'model_id' => $this->modelId,
            'rank' => $this->rank,
            'title' => $this->title,
            'summary' => $this->summary,
            'authorized' => $this->authorized,
            'total_count' => $this->totalCount,
            'raw' => $this->raw,
        ];
    }

    public function __sleep(): array
    {
        return [
            'id', 'modelClass', 'modelId', 'rank', 'title', 'summary',
            'raw', 'authorized', 'totalCount',
        ];
    }
}
