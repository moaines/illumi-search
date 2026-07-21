<?php

namespace Moaines\IllumiSearch\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RebuildComplete
{
    use Dispatchable;

    /** @param array<int, array<string, mixed>> $results */
    public function __construct(
        public readonly array $results,
    ) {}
}
