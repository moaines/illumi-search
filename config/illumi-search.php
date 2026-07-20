<?php

return [

    'database_path' => env('ILLUMI_SEARCH_DATABASE_PATH', 'app/search/search-index.sqlite'),

    'mode' => env('ILLUMI_SEARCH_MODE', 'advanced'),

    'indexing' => env('ILLUMI_SEARCH_INDEXING', 'queue'),

    'queue_connection' => env('ILLUMI_SEARCH_QUEUE_CONNECTION'),

    'rebuild_batch_size' => env('ILLUMI_SEARCH_REBUILD_BATCH_SIZE', 0),

    'max_results' => 50,

    'model_paths' => [
        app_path('Models'),
    ],

    'fts5' => [

        'tokenizer' => 'unicode61',

        'processor' => env('ILLUMI_SEARCH_PROCESSOR', 'unicode'),

        'prefix_lengths' => [2, 3, 4],

        'detail' => 'full',

        'columnsize' => env('ILLUMI_SEARCH_COLUMNSIZE', 1),

        'automerge' => 4,

        'crisismerge' => 16,

        'pgsz' => 1000,

        'wal' => env('ILLUMI_SEARCH_WAL', true),

        'cache_size_kb' => env('ILLUMI_SEARCH_CACHE_SIZE_KB', -64000),

        'synchronous' => env('ILLUMI_SEARCH_SYNCHRONOUS', 'NORMAL'),

        'temp_store' => env('ILLUMI_SEARCH_TEMP_STORE', 'MEMORY'),

        'busy_timeout' => env('ILLUMI_SEARCH_BUSY_TIMEOUT', 15000),

        'mmap_size' => env('ILLUMI_SEARCH_MMAP_SIZE', 0),
    ],

    'authorization' => [
        'enabled' => env('ILLUMI_SEARCH_AUTHORIZATION', false),
    ],

    'tenancy' => [
        'enabled' => env('ILLUMI_SEARCH_TENANCY', false),
        'directory' => env('ILLUMI_SEARCH_TENANCY_DIRECTORY', 'app/search/tenants'),
    ],

    'spellcheck' => [
        'vocab_limit' => env('ILLUMI_SEARCH_SPELLCHECK_VOCAB_LIMIT', 1000),
    ],

    'operators' => [
        'enabled' => env('ILLUMI_SEARCH_OPERATORS'),
    ],

    'max_related_values' => 100,

    'api' => [
        'enabled' => env('ILLUMI_SEARCH_API_ENABLED', false),
        'middleware' => ['api'],
        'prefix' => 'api/search',
        'rate_limit' => 30,
    ],
];
