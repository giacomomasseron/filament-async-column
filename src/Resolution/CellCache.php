<?php

namespace GiacomoMasseroni\AsyncColumn\Resolution;

use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The single owner of where - and under what key - resolved cell values live.
 *
 * Three separate call sites have to agree exactly for caching to work: the
 * write (BatchResolver::rememberAllowingNull()), the read on the next batch,
 * and AsyncColumn::forget(). Nothing enforces that agreement at runtime - a
 * mismatched prefix, store or version segment doesn't throw, it just silently
 * writes to one key and reads or removes another, which reads to the developer
 * as "caching is broken" or "forget() does nothing" with no error anywhere to
 * point at the cause. Keeping the format and the store lookup here means there
 * is only one thing to change, so they cannot drift apart.
 */
final class CellCache
{
    public static function key(
        string $componentClass,
        string $columnName,
        string $recordKey,
        string $version = '',
    ): string {
        return implode(':', [
            config('async-column.cache_prefix', 'async-column'),
            $componentClass,
            $columnName,
            $recordKey,
            $version,
        ]);
    }

    /**
     * Normalises a cacheVersion() value into its key segment.
     *
     * A DateTimeInterface becomes a Unix timestamp rather than its default
     * string cast, so that two versions differing only in timezone
     * representation - but describing the same instant - produce the same key
     * instead of silently splitting the cache in two.
     */
    public static function version(mixed $version): string
    {
        if ($version === null) {
            return '';
        }

        return $version instanceof DateTimeInterface
            ? $version->format('U')
            : (string) $version;
    }

    public static function store(): Repository
    {
        return Cache::store(config('async-column.cache_store'));
    }
}
