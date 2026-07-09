<?php

namespace Moaines\LaravelFts\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ModelIndexed
{
    use Dispatchable;

    public function __construct(
        public readonly string $modelClass,
        public readonly int $records,
    ) {}
}
