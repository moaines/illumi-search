<?php

namespace Moaines\IllumiSearch\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RebuildComplete
{
    use Dispatchable;

    public function __construct(
        public readonly array $results,
    ) {}
}
