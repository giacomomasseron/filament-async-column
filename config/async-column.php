<?php

return [
    // Cache store used by ->cacheFor(). Null = the application default store.
    'cache_store' => env('ASYNC_COLUMN_CACHE_STORE'),

    // Prefix for every cache key written by this package.
    'cache_prefix' => 'async-column',
];
