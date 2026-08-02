# Filament Async Column

A [Filament](https://filamentphp.com) v5 table column that renders instantly and resolves its
value afterwards, in **one batched follow-up request** — instead of making every page load wait
on however many expensive lookups your columns need (API calls, remote aggregates, anything
slower than a plain SQL select).

The table renders immediately with a lightweight skeleton in place of each `AsyncColumn` cell.
Once the page has painted, the browser asks the server to resolve every visible async cell for
that table in a single Livewire call, and the results are injected in place — no extra query per
row, no blocked render.

## Installation

Requires PHP 8.2+ and `filament/tables` ^5.0 (which `filament/filament` ^5.0 already pulls in, so
panel users don't need to add anything separately).

```bash
composer require giacomomasseron/filament-async-column
```

No build step, no npm install: the column's JS and CSS are registered through Filament's own
asset pipeline (`FilamentAsset`, under the package name `giacomomasseron/filament-async-column`)
and are served automatically wherever `@filamentStyles` / `@filamentScripts` render — which every
Filament panel already does. If you're using `filament/tables` on its own, outside a panel, make
sure your layout includes those two directives.

> [!IMPORTANT]
> **Standalone `filament/tables` users must run `php artisan filament:assets` after installing.**
> A registered asset still has to be *copied* into `public/` before `@filamentStyles` /
> `@filamentScripts` can serve it. A Filament panel install (`filament:install --panels`) wires a
> `post-autoload-dump` hook into your app's own `composer.json` that keeps this in sync
> automatically on every `composer update` — but that hook is a side effect of the panel
> installer, not something this package (or `filament/tables` alone) can register on its own.
> Without it, the JS and CSS 404, Alpine throws a console error on every cell, and every column
> is stuck on its skeleton permanently. The Alpine store lookup itself degrades gracefully in this
> case (`$store.asyncColumn?.register($el)`), so a missing asset means a static skeleton rather
> than a broken page — but running `php artisan filament:assets` (and re-running it after every
> `composer update`, or adding the same hook Filament's own installer adds) is what actually fixes
> it.

```bash
php artisan filament:assets
```

The package ships sensible defaults and works with zero configuration. To customize the cache
store or the per-request batch cap, publish the config file:

```bash
php artisan vendor:publish --tag="filament-async-column-config"
```

## Minimal example

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

That's it — `resolveUsing()` is the one thing every `AsyncColumn` needs. `AsyncColumn` extends
Filament's `TextColumn`, so every formatting method you already know keeps working on the
*resolved* value: `badge()`, `color()`, `icon()`, `weight()`, `copyable()`, `money()`, `date()`,
`limit()`, `prefix()`/`suffix()`, `markdown()`, `formatStateUsing()`, and so on.

```php
AsyncColumn::make('lifetime_value')
    ->resolveUsing(fn ($record) => $this->billingService->lifetimeValue($record))
    ->money('USD')
    ->weight('bold')
    ->color(fn (?float $state) => $state > 1000 ? 'success' : 'gray');
```

## The three formatting modes

An `AsyncColumn`'s resolved value can be rendered three ways, same as it would be for any other
column type:

**1. Plain text (default)** — the resolved value is cast to a string and HTML-escaped:

```php
AsyncColumn::make('status')->resolveUsing(fn ($record) => $record->status);
```

**2. Raw HTML**, via `->html()` — use this when your resolver already returns markup you trust:

```php
AsyncColumn::make('status_badge')
    ->resolveUsing(fn ($record) => '<span class="badge">'.$record->status.'</span>')
    ->html();
```

**3. A view**, via `->view()` — the resolved state and the record are available in the view like
any other Filament column view:

```php
AsyncColumn::make('status')
    ->resolveUsing(fn ($record) => $record->status)
    ->view('columns.status-pill');
```

## Async options

| Method | Description |
|---|---|
| `resolveUsing(Closure $callback)` | **Required.** Supplies the resolved value. Receives `$record` (and other Filament-standard parameters via dependency injection). |
| `loadingState(string \| Htmlable \| Closure \| null $state)` / `loadingStateUsing(Closure $callback)` | What's shown in the cell before it resolves. Defaults to a CSS skeleton (`<span class="fi-async-column-skeleton">`). |
| `errorState(string \| Htmlable \| Closure \| null $state)` / `errorStateUsing(Closure $callback)` | What's shown if the resolver throws. The default is a translated "Could not load". `errorStateUsing()`'s closure is the *only* place the underlying `Throwable` is ever exposed — see [Security](#security). |
| `whenVisible(bool \| Closure $condition = true)` | Defers resolving a column's cells until they scroll into the viewport (via `IntersectionObserver`), instead of resolving them immediately after paint. Useful for columns far down a long table. |
| `retryable(bool \| Closure $condition = true)` | Whether a failed cell can be clicked to retry. Defaults to `true`. |
| `cacheFor(int \| CarbonInterface \| Closure \| null $ttl)` | Opt-in caching of the resolved value. `null` (the default) disables caching. **Read the warning below before using this.** |
| `cacheVersion(mixed $version)` | A value folded into the cache key, for busting the cache (deploys, viewer scoping — see below). Accepts a scalar, a `DateTimeInterface`, or a `Closure`. |

## Caching

> [!WARNING]
> **`->cacheFor()` keys are shared across users.** The key is built from column + record +
> version, deliberately, so a warm cache benefits everyone. If your resolver returns data
> specific to the *viewer* rather than the *record*, you must scope it yourself:
>
> ```php
> AsyncColumn::make('my_price')
>     ->cacheFor(300)
>     ->cacheVersion(fn () => auth()->id())
> ```

Caching is off by default (`cacheFor(null)`, the default). When enabled, the cache key is
`{cache_prefix}:{livewire_component_class}:{column_name}:{record_key}:{cache_version}` — there is
no user identity in it unless `cacheVersion()` puts one there.

## Guardrails

Two Filament `TextColumn` methods are deliberately blocked on `AsyncColumn`, because they only
make sense for a value that exists at render time — and this column's entire purpose is to *not*
need one:

- **`getStateUsing()` throws.** It evaluates during the initial (synchronous) table render, which
  would reintroduce exactly the page-blocking slowness this package exists to remove. Use
  `resolveUsing()` instead.
- **`sortable()` / `searchable()` throw**, unless you pass a `query:` closure. The value doesn't
  exist in SQL — it's produced by your resolver, after the table's query has already run — so
  there is nothing for Filament to `ORDER BY` or `WHERE ... LIKE` by default. Supply the query
  yourself if the underlying data is sortable/searchable some other way:

  ```php
  AsyncColumn::make('open_support_tickets')
      ->resolveUsing(fn ($record) => $this->ticketService->count($record))
      ->sortable(query: fn (Builder $query, string $direction) => $query
          ->withCount('supportTickets')
          ->orderBy('support_tickets_count', $direction))
  ```

## Limitations

These are real, deliberate trade-offs rather than bugs — documented here instead of left for you
to discover:

- **`cacheFor(0)` never persists anything.** Laravel's cache `put()` treats a TTL of `0` (or less)
  as "forget", not "store forever" or "store briefly" — so `cacheFor(0)` silently behaves like
  caching being off, just with an extra round-trip to the cache store on every resolution. Use
  `cacheFor(null)` (or simply omit `cacheFor()`) to disable caching. A resolver that legitimately
  returns `null` *is* cached correctly when a real TTL is set — that case is handled explicitly.
- **Array/data-source-backed tables aren't supported.** `BatchResolver` hydrates records through
  the table's own Eloquent query (`$table->getQuery()`); if that returns `null` — a table backed
  by a plain array or another non-Eloquent data source — the placeholder is rendered but never
  resolves.
- **A `TrashedFilter` doesn't hide soft-deleted records from resolution the way it hides them from
  the listing.** A forged token can resolve a cell for a soft-deleted record even when the
  table's `TrashedFilter` is set to exclude trashed rows. This isn't a gap specific to this
  package: it matches Filament's own single-record resolution (`getTableRecord()`) exactly, and
  is the accepted cost of matching that behavior rather than diverging from it.
- **`BelongsToMany` tables using `allowsDuplicates()` aren't supported.** When a pivot relation
  allows the same related record to appear more than once (keyed by pivot row rather than by the
  related model's primary key), the record keys `BatchResolver` relies on no longer map cleanly
  onto a single `whereKey()` lookup.
- **The client-side cache is per page-load, unbounded, and unrelated to `cacheFor()`.** Once a
  cell resolves, its HTML is kept in memory on the client for the lifetime of that page/Livewire
  component (so sorting or paginating back to an already-seen row repaints instantly without a
  server round-trip). It is not persisted across page loads and has nothing to do with the
  server-side `cacheFor()` TTL.

## Security

Records are hydrated exclusively through the table's **own** Eloquent query, scoped exactly the
way Filament scopes its own single-record resolution — tenancy, global scopes, and active table
filters all apply. (The active *search* term is the one deliberate exception: it is not applied
when resolving, so a cell that was already in flight doesn't vanish out from under the batch
request just because someone typed into the search box in the meantime — search narrows what's
*displayed*, it doesn't gate what's *authorized*.) A hand-crafted token for a row outside that
scope simply never comes back from the query; no cell is produced for it. Only columns genuinely
defined on the table — and only `AsyncColumn` instances among them, currently visible and not
toggled off — are resolvable; an unknown, mistyped, hidden, or foreign column name is silently
dropped rather than surfaced as an error. In short: a forged token cannot reach data, or even the
existence of a column, that the requesting user could not already see through the table itself.

## Configuration reference

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

The cap limits how many resolvers one request can invoke. It does **not** limit how often
those requests can be made — anyone holding a valid Livewire snapshot for a page can keep
issuing them, each costing up to `max_batch_size` resolver invocations.

If your resolvers call a paid, rate-limited, or otherwise expensive upstream service, treat
that as you would any other authenticated endpoint and apply your own throttling — Laravel's
`throttle` middleware on the Livewire update route, a per-user rate limiter inside the
resolver, or `->cacheFor()` so repeat work is served from cache rather than the upstream.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
