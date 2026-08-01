<?php

return [
    // Cache store used by ->cacheFor(). Null = the application default store.
    'cache_store' => env('ASYNC_COLUMN_CACHE_STORE'),

    // Prefix for every cache key written by this package.
    'cache_prefix' => 'async-column',

    // Maximum number of cell tokens BatchResolver will process in a single
    // request. Extra tokens beyond this cap are silently truncated (never an
    // error), so a single request cannot force arbitrarily many resolver
    // invocations.
    'max_batch_size' => (int) env('ASYNC_COLUMN_MAX_BATCH_SIZE', 200),
];
