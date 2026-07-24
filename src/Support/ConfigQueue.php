<?php

namespace Moaines\IllumiSearch\Support;

use Moaines\IllumiSearch\Contracts\Engine;

class ConfigQueue
{
    public function __construct(
        private readonly Engine $engine,
    ) {}

    /**
     * Push an item to the front of a config-scoped list.
     * Duplicates are removed (compared by $key when provided).
     *
     * @param  array<string, mixed>  $item
     */
    public function push(string $configKey, mixed $item, int $max = 15, ?string $key = null): void
    {
        $data = $this->engine->getConfig($configKey, []);
        $data = array_values(array_filter($data, $key
            ? fn ($v) => ($v[$key] ?? null) !== ($item[$key] ?? null)
            : fn ($v) => $v !== $item,
        ));
        array_unshift($data, $item);
        $this->engine->setConfig($configKey, array_slice($data, 0, $max));
    }

    /**
     * Remove an item from a config-scoped list.
     *
     * @param  array<string, mixed>  $item
     */
    public function remove(string $configKey, mixed $item, ?string $key = null): void
    {
        $data = $this->engine->getConfig($configKey, []);
        $this->engine->setConfig($configKey, array_values(array_filter($data, $key
            ? fn ($v) => ($v[$key] ?? null) !== ($item[$key] ?? null)
            : fn ($v) => $v !== $item,
        )));
    }
}
