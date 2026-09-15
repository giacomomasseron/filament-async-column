# Filament Async Column

[![Latest Version on Packagist](https://img.shields.io/packagist/v/giacomomasseron/filament-async-column.svg?style=flat-square)](https://packagist.org/packages/giacomomasseron/filament-async-column)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/giacomomasseron/filament-async-column/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/giacomomasseron/filament-async-column/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/giacomomasseron/filament-async-column/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/giacomomasseron/filament-async-column/actions?query=workflow%3A%22Fix+PHP+code+style+issues%22+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/giacomomasseron/filament-async-column.svg?style=flat-square)](https://packagist.org/packages/giacomomasseron/filament-async-column)

**A [Filament](https://filamentphp.com) v5 table column that renders now and loads later.**

## Why?

One slow column shouldn't hold up your whole page.

If a column calls an API, reads a remote aggregate, or does anything slower than a plain SQL
select, your table waits for it - once per row. Twenty rows means twenty round-trips, and nothing
appears on screen until the last one finishes.

`AsyncColumn` splits that in two:

1. The table renders straight away, with a light skeleton where each async cell will go.
2. Once the page has painted, the browser makes **one** Livewire call that resolves every visible
   async cell in the table.
3. The values drop into place.

One request for the whole table, not one per cell. No blocked render.

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Formatting the value](#formatting-the-value)
- [Recipes](#recipes)
- [Options reference](#options-reference)
- [Caching](#caching)
- [Guardrails](#guardrails)
- [Limitations](#limitations)
- [Security](#security)
- [Configuration](#configuration)

## Installation

You need PHP 8.2+ and `filament/tables` ^5.0. If you already have `filament/filament` ^5.0, you
have it.

```bash
composer require giacomomasseron/filament-async-column
```

That's the whole install for panel users - no build step, no npm. The column's JS and CSS go
through Filament's own asset pipeline (`FilamentAsset`, under the package name
`giacomomasseron/filament-async-column`), so they're served anywhere `@filamentStyles` and
`@filamentScripts` render. Every Filament panel renders both already.

Using `filament/tables` on its own, outside a panel? Include those two directives in your layout,
and read this next part.

> [!IMPORTANT]
> **Outside a panel, run `php artisan filament:assets` after installing - and again after every
> `composer update`.**
>
> ```bash
> php artisan filament:assets
> ```
>
> Registering an asset isn't the same as publishing it. Filament still has to *copy* the file into
> `public/` before `@filamentStyles` / `@filamentScripts` can serve it.
>
> - **Panels already handle this.** `filament:install --panels` adds a `post-autoload-dump` hook to
>   your application's `composer.json` that re-copies assets on every `composer update`. That hook
>   belongs to the panel installer - neither this package nor `filament/tables` can add it for you.
> - **Skip it and the JS and CSS 404.** Alpine throws a console error on every cell, and every
>   column sits on its loading skeleton forever.
> - **It fails quietly, not loudly.** The store lookup is optional-chained
>   (`$store.asyncColumn?.register($el)`), so a missing asset leaves a static skeleton instead of
>   breaking the page - which makes it easy to misread as a bug in the column.

The defaults work with no configuration at all. To change the cache store or the batch cap, see
[Configuration](#configuration).

## Quick start

Add the column and give it a `resolveUsing()` callback:

```php
use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;

public function table(Table $table): Table
{
    return $table->columns([
        AsyncColumn::make('open_support_tickets')
            ->resolveUsing(fn ($record) => Http::get('https://support.example.com/api/tickets/count', [
                'customer_id' => $record->id,
            ])->json('count')),
    ]);
}
```

That's it. `resolveUsing()` is the only method every `AsyncColumn` needs.

`AsyncColumn` extends Filament's `TextColumn`, so every formatting method you already know keeps
working - it just applies to the *resolved* value instead of a database one. `badge()`, `color()`,
`icon()`, `weight()`, `copyable()`, `money()`, `date()`, `limit()`, `prefix()`/`suffix()`,
`markdown()`, `formatStateUsing()`, and the rest:

```php
AsyncColumn::make('lifetime_value')
    ->resolveUsing(fn ($record) => $this->billingService->lifetimeValue($record))
    ->money('USD')
    ->weight('bold')
    ->color(fn (?float $state) => $state > 1000 ? 'success' : 'gray');
```

## Formatting the value

You can render a resolved value three ways, exactly as you would for any other column type.

**1. Plain text (the default).** The value is cast to a string and HTML-escaped:

```php
AsyncColumn::make('status')->resolveUsing(fn ($record) => $record->status);
```

**2. Raw HTML**, via `->html()`. Use this when your resolver returns markup you trust:

```php
AsyncColumn::make('status_badge')
    ->resolveUsing(fn ($record) => '<span class="badge">'.$record->status.'</span>')
    ->html();
```

**3. A view**, via `->view()`. The resolved state and the record are available just like they are
in any other Filament column view:

```php
AsyncColumn::make('status')
    ->resolveUsing(fn ($record) => $record->status)
    ->view('columns.status-pill');
```

## Recipes

### Wait until the cell scrolls into view

By default every visible cell resolves as soon as the page paints. For a column far down a long
table, `whenVisible()` holds off until the cell actually scrolls into the viewport (it uses
`IntersectionObserver`):

```php
AsyncColumn::make('inventory_level')
    ->resolveUsing(fn ($record) => $this->warehouse->stockFor($record))
    ->whenVisible();
```

### Change what the cell shows while loading, or when it fails

The default is a CSS skeleton while loading and a translated "Could not load" on failure. Both
take a string, an `Htmlable`, or a closure:

```php
AsyncColumn::make('shipment_eta')
    ->resolveUsing(fn ($record) => $this->carrier->eta($record))
    ->loadingState('Checking carrier...')
    ->errorState('Carrier unavailable');
```

If you need the exception itself, use `errorStateUsing()` - its closure is the **only** place the
underlying `Throwable` is ever exposed, and the parameter has to be named `$exception` for
Filament to inject it:

```php
->errorStateUsing(fn (Throwable $exception) => $exception instanceof ConnectionException
    ? 'Carrier offline'
    : 'Could not load')
```

Never render `$exception->getMessage()` straight into the cell. Resolvers hit APIs with
credentials in URLs and databases that echo SQL back in their errors - see [Security](#security).

### Turn off click-to-retry

A failed cell can be clicked to retry. Pass `false` if that isn't appropriate - for a resolver
that isn't safe to repeat, say, or one that costs money per call:

```php
AsyncColumn::make('credit_check')
    ->resolveUsing(fn ($record) => $this->bureau->check($record))
    ->retryable(false);
```

### Cache an expensive resolution

`cacheFor()` stores the resolved value so the next page load doesn't repeat the work:

```php
AsyncColumn::make('open_support_tickets')
    ->resolveUsing(fn ($record) => $this->tickets->count($record))
    ->cacheFor(300); // seconds
```

**Read [Caching](#caching) before you use this** - the keys are shared across users, which is what
you want for record-specific data and a problem for viewer-specific data.

## Options reference

| Method | Description |
|---|---|
| `resolveUsing(Closure $callback)` | **Required.** Supplies the resolved value. Receives `$record` (and other Filament-standard parameters via dependency injection). |
| `loadingState(string \| Htmlable \| Closure \| null $state)` / `loadingStateUsing(Closure $callback)` | What's shown in the cell before it resolves. Defaults to a CSS skeleton (`<span class="fi-async-column-skeleton">`). |
| `errorState(string \| Htmlable \| Closure \| null $state)` / `errorStateUsing(Closure $callback)` | What's shown if the resolver throws. The default is a translated "Could not load". `errorStateUsing()`'s closure is the *only* place the underlying `Throwable` is ever exposed - see [Security](#security). |
| `whenVisible(bool \| Closure $condition = true)` | Defers resolving a column's cells until they scroll into the viewport (via `IntersectionObserver`), instead of resolving them immediately after paint. Useful for columns far down a long table. |
| `retryable(bool \| Closure $condition = true)` | Whether a failed cell can be clicked to retry. Defaults to `true`. |
| `cacheFor(int \| CarbonInterface \| Closure \| null $ttl)` | Opt-in caching of the resolved value. `null` (the default) disables caching. **Read [Caching](#caching) before using this.** |
| `cacheVersion(mixed $version)` | A value folded into the cache key, for busting the cache (deploys, viewer scoping - see [Caching](#caching)). Accepts a scalar, a `DateTimeInterface`, or a `Closure`. |

There's also one static method, [`AsyncColumn::forget()`](#invalidating-a-cached-cell), for
removing a cached cell from outside a table render.

## Caching

Caching is off unless you ask for it. When you do turn it on with `cacheFor()`, the key looks like
this:

```
{cache_prefix}:{livewire_component_class}:{column_name}:{record_key}:{cache_version}
```

Note what isn't in there: any notion of *who is looking*.

> [!WARNING]
> **`cacheFor()` keys are shared across users.** The key is built from column + record + version,
> deliberately, so a warm cache benefits everyone. If your resolver returns data specific to the
> *viewer* rather than the *record*, scope it yourself:
>
> ```php
> AsyncColumn::make('my_price')
>     ->cacheFor(300)
>     ->cacheVersion(fn () => auth()->id())
> ```

### Invalidating a cached cell

Before `AsyncColumn::forget()`, a cached cell could only be cleared two ways: wait out its TTL, or
change `cacheVersion()` - which busts the column for *every* record, not just the one that
changed. Neither helps much when one record's data moves and that single cell is now wrong.

`AsyncColumn::forget()` removes cells written by `cacheFor()`. It takes the key's parts rather
than a column instance, so you can call it from anywhere - a model observer, a queued job,
tinker - where no table is being rendered:

```php
use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;

// One cell.
AsyncColumn::forget(ListPosts::class, 'stock_level', $post->getKey());

// Many cells of the same column, in one call.
AsyncColumn::forget(ListPosts::class, 'stock_level', [1, 2, 3]);

// A cell written under a ->cacheVersion().
AsyncColumn::forget(ListPosts::class, 'stock_level', $post->getKey(), version: 'v2');
```

The usual home for it is an observer, so the cell disappears the moment the data behind it
changes:

```php
class PostObserver
{
    public function saved(Post $post): void
    {
        AsyncColumn::forget(ListPosts::class, 'stock_level', $post->getKey());
    }
}
```

The same call suits a queued job or an external sync that already knows which records moved - pass
their keys as an array - and tinker, when a resolver bug has cached bad output and you'd rather not
deploy to clear it.

Reach for `cacheVersion()` instead when what changed is the *column* rather than a record: the
resolver's logic, a formatting change, a deploy. `forget()` is for "this record changed"; a new
version is for "this column changed".

Four things worth knowing:

- **The record key is the one the *table* uses** - `$table->getRecordKey($record)`, which is
  `(string) $record->getKey()` unless your component overrides `getTableRecordKey()`. If it does,
  pass that same value or you'll remove a key nothing ever wrote.
- **The version has to match.** It's part of the key, so a `forget()` without a `version:` removes
  only the unversioned cell. It isn't a wildcard.
- **A `Closure` version can't be forgotten**, and throws a `LogicException` rather than failing
  quietly. There's no column instance or record to evaluate it against outside a render, and a
  request-scoped version like `cacheVersion(fn () => auth()->id())` writes one key *per viewer* -
  which plain string keys can't enumerate. Bust those by changing the version instead.
- **It doesn't touch the client-side cache.** A cell already resolved in an open browser tab keeps
  its HTML until that page is reloaded - see the client-side cache note under
  [Limitations](#limitations). `forget()` only affects what the *next* resolution computes.

## Guardrails

Two `TextColumn` methods are deliberately blocked on `AsyncColumn`. Both only make sense for a
value that exists at render time, and not needing one is this column's entire purpose:

- **`getStateUsing()` throws.** It evaluates during the initial, synchronous table render - which
  would put back exactly the page-blocking slowness this package exists to remove. Use
  `resolveUsing()` instead.
- **`sortable()` / `searchable()` throw**, unless you pass a `query:` closure. Your value doesn't
  exist in SQL; the resolver produces it after the table's query has already run, so there's
  nothing for Filament to `ORDER BY` or `WHERE ... LIKE`. If the underlying data *is* sortable some
  other way, supply that query yourself:

  ```php
  AsyncColumn::make('open_support_tickets')
      ->resolveUsing(fn ($record) => $this->ticketService->count($record))
      ->sortable(query: fn (Builder $query, string $direction) => $query
          ->withCount('supportTickets')
          ->orderBy('support_tickets_count', $direction))
  ```

## Limitations

These are deliberate trade-offs rather than bugs. They're written down here so you meet them on
this page instead of in production:

- **`cacheFor(0)` never persists anything.** Laravel's cache `put()` reads a TTL of `0` or less as
  "forget", not "store forever" or "store briefly" - so `cacheFor(0)` quietly behaves like caching
  being off, just with an extra round-trip to the cache store on every resolution. Use
  `cacheFor(null)`, or leave `cacheFor()` off entirely, to disable caching. A resolver that
  legitimately returns `null` *is* cached correctly when you set a real TTL; that case is handled
  explicitly.
- **Array-backed and other non-Eloquent tables aren't supported.** `BatchResolver` hydrates records
  through the table's own Eloquent query (`$table->getQuery()`). If that returns `null`, the
  placeholder renders but never resolves.
- **A `TrashedFilter` doesn't hide soft-deleted records from resolution the way it hides them from
  the listing.** A forged token can resolve a cell for a soft-deleted record even when the table's
  `TrashedFilter` excludes trashed rows. This isn't specific to this package - it matches
  Filament's own single-record resolution (`getTableRecord()`) exactly, and that consistency is
  worth more than diverging.
- **`BelongsToMany` tables using `allowsDuplicates()` aren't supported.** When a pivot relation
  lets the same related record appear more than once (keyed by pivot row rather than by the related
  model's primary key), the record keys `BatchResolver` relies on stop mapping cleanly onto a
  single `whereKey()` lookup.
- **The client-side cache is per page-load, unbounded, and unrelated to `cacheFor()`.** Once a cell
  resolves, its HTML stays in memory on the client for the lifetime of that page/Livewire
  component - so sorting or paginating back to a row you've already seen repaints instantly, with
  no server round-trip. It isn't persisted across page loads and has nothing to do with the
  server-side `cacheFor()` TTL.

## Security

Cell tokens travel to the browser and come back again, so they're treated as untrusted input. The
guarantee is:

> A forged token cannot reach data - or even reveal that a column exists - that the requesting
> user could not already see through the table itself.

Two mechanisms enforce it.

**Records are hydrated only through the table's own Eloquent query.** That query is scoped exactly
the way Filament scopes its own single-record resolution, so tenancy, global scopes and active
table filters all apply automatically instead of being reimplemented here. A hand-crafted token
for a row outside that scope simply never comes back from the query, and no cell is produced.

**Only columns genuinely defined on the table can be resolved** - and among those, only
`AsyncColumn` instances that are currently visible and not toggled off. An unknown, mistyped,
hidden or foreign column name is dropped silently rather than reported, so nobody can use error
responses to probe which columns exist.

> [!NOTE]
> **The active search term is a deliberate exception: it isn't applied when resolving.** Search
> narrows what's *displayed*; it doesn't gate what's *authorized*. Applying it would let a cell
> that was already in flight vanish out from under its batch request the moment someone typed into
> the search box.

### Reporting a vulnerability

Please don't open a public issue. Follow the [security policy](.github/SECURITY.md) to report it
privately.

## Configuration

Everything has a working default. To change any of it, publish the config file:

```bash
php artisan vendor:publish --tag="filament-async-column-config"
```

```php
// config/async-column.php

return [
    // Cache store used by ->cacheFor(). Null = the application's default store.
    'cache_store' => env('ASYNC_COLUMN_CACHE_STORE'),

    // Prefix for every cache key this package writes.
    'cache_prefix' => 'async-column',

    // Maximum number of cell tokens resolved in a single batch request. Extra
    // tokens beyond this cap are silently truncated, never an error. A value
    // <= 0 (including a malformed env value) falls back to the default of
    // 200 rather than disabling the cap.
    'max_batch_size' => (int) env('ASYNC_COLUMN_MAX_BATCH_SIZE', 200),
];
```

### `max_batch_size` bounds each request, not the request rate

The cap limits how many resolvers a single request can invoke. It does **not** limit how often
those requests can be made - anyone holding a valid Livewire snapshot for a page can keep issuing
them, each one costing up to `max_batch_size` resolver invocations.

If your resolvers call a paid, rate-limited, or otherwise expensive upstream service, treat this
like any other authenticated endpoint and add your own throttling: Laravel's `throttle` middleware
on the Livewire update route, a per-user rate limiter inside the resolver, or `cacheFor()` so
repeat work is served from cache instead of the upstream.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
