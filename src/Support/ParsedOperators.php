<?php

namespace Moaines\IllumiSearch\Support;

class ParsedOperators
{
    /** @var list<string> */
    public array $required = [];

    /** @var list<string> */
    public array $optional = [];

    /** @var list<string> */
    public array $excluded = [];

    /** @var list<array{string, string}> */
    public array $nearPairs = [];

    /** @var list<string> */
    public array $phrases = [];

    public int $nearMaxDistance = 5;
}
