<?php

namespace Moaines\LaravelFts;

use Illuminate\Contracts\Support\Arrayable;

class FtsResult implements Arrayable
{
    public function __construct(
        public readonly string $id,
        public readonly string $modelClass,
        public readonly int|string $modelId,
        public readonly float $rank,
        public readonly string $title,
        public readonly ?string $summary = null,
        public readonly ?string $url = null,
        public readonly ?string $icon = null,
        public readonly ?string $category = null,
        public readonly array $raw = [],
        public readonly bool $authorized = true,
    ) {}

    public static function make(
        string $modelClass,
        int|string $modelId,
        float $rank,
        string $title,
        ?string $summary = null,
        ?string $url = null,
        ?string $icon = null,
        ?string $category = null,
        array $raw = [],
        bool $authorized = true,
    ): self {
        return new self(
            id: "{$modelClass}:{$modelId}",
            modelClass: $modelClass,
            modelId: $modelId,
            rank: $rank,
            title: $title,
            summary: $summary,
            url: $url,
            icon: $icon,
            category: $category,
            raw: $raw,
            authorized: $authorized,
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
            'url' => $this->url,
            'icon' => $this->icon,
            'category' => $this->category,
            'authorized' => $this->authorized,
            'raw' => $this->raw,
        ];
    }
}
