<?php

namespace GiacomoMasseroni\AsyncColumn\Columns;

use Carbon\CarbonInterface;
use Closure;
use Filament\Tables\Columns\TextColumn;
use GiacomoMasseroni\AsyncColumn\Enums\Loader;
use GiacomoMasseroni\AsyncColumn\Resolution\CellCache;
use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use LogicException;
use Throwable;

class AsyncColumn extends TextColumn
{
    protected ?Closure $resolveUsing = null;

    protected string|Htmlable|Closure|null $loadingState = null;

    protected Loader|string|Closure|null $loader = null;

    protected string|Closure|null $loaderColor = null;

    protected string|Htmlable|Closure|null $errorState = null;

    protected bool|Closure $loadsWhenVisible = false;

    protected bool|Closure $isRetryable = true;

    protected int|CarbonInterface|Closure|null $cacheTtl = null;

    protected mixed $cacheVersion = null;

    public function resolveUsing(?Closure $callback): static
    {
        $this->resolveUsing = $callback;

        return $this;
    }

    public function getResolveUsing(): ?Closure
    {
        return $this->resolveUsing;
    }

    public function loadingState(string|Htmlable|Closure|null $state): static
    {
        $this->loadingState = $state;

        return $this;
    }

    public function loadingStateUsing(?Closure $callback): static
    {
        return $this->loadingState($callback);
    }

    public function getLoadingState(): string|Htmlable|null
    {
        return $this->evaluate($this->loadingState) ?? $this->getLoaderHtml();
    }

    public function loader(Loader|string|Closure|null $loader): static
    {
        $this->loader = $loader;

        return $this;
    }

    public function getLoader(): Loader
    {
        $loader = $this->evaluate($this->loader);

        if ($loader === null || $loader instanceof Loader) {
            return $loader ?? Loader::Skeleton;
        }

        if (is_string($loader) && ($preset = Loader::tryFrom($loader)) instanceof Loader) {
            return $preset;
        }

        throw new InvalidArgumentException(
            'AsyncColumn ['.$this->getName().'] has no loader named ['.(is_string($loader) ? $loader : get_debug_type($loader)).']. '
            .'Use one of: '.implode(', ', array_column(Loader::cases(), 'value')).'.'
        );
    }

    /**
     * Deliberately not inherited from TextColumn's color(): that is usually a
     * closure over the resolved state, and evaluating it here - while the cell
     * is still loading - would run the very resolution this column defers.
     */
    public function loaderColor(string|Closure|null $color): static
    {
        $this->loaderColor = $color;

        return $this;
    }

    /**
     * The color name reduced to a class-safe string (the same sanitisation as
     * Filament's ColorManager::getComponentClasses()), because it's
     * concatenated straight into the loader's markup.
     */
    public function getLoaderColor(): ?string
    {
        $color = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $this->evaluate($this->loaderColor));

        return blank($color) ? null : $color;
    }

    /**
     * fi-color-{name} is Filament's own utility class: it gives the element the
     * --color-50 ... --color-950 variables for that palette color (custom
     * colors registered via FilamentColor included), which the CSS draws with.
     */
    protected function getLoaderHtml(): HtmlString
    {
        $loader = $this->getLoader();
        $color = $this->getLoaderColor();

        $classes = 'fi-async-column-'.$loader->value.($color === null ? '' : ' fi-color fi-color-'.$color);
        $dots = $loader === Loader::Dots ? '<span></span><span></span><span></span>' : '';

        return new HtmlString('<span class="'.$classes.'" aria-hidden="true">'.$dots.'</span>');
    }

    public function errorState(string|Htmlable|Closure|null $state): static
    {
        $this->errorState = $state;

        return $this;
    }

    public function errorStateUsing(?Closure $callback): static
    {
        return $this->errorState($callback);
    }

    public function getErrorState(?Throwable $exception = null): string|Htmlable|null
    {
        // The exception is only ever exposed to an explicit developer callback.
        // Defaulting to $exception->getMessage() would leak API credentials and SQL.
        $state = $this->evaluate($this->errorState, ['exception' => $exception]);

        return $state ?? __('async-column::async-column.error');
    }

    public function whenVisible(bool|Closure $condition = true): static
    {
        $this->loadsWhenVisible = $condition;

        return $this;
    }

    public function loadsWhenVisible(): bool
    {
        return (bool) $this->evaluate($this->loadsWhenVisible);
    }

    public function retryable(bool|Closure $condition = true): static
    {
        $this->isRetryable = $condition;

        return $this;
    }

    public function isRetryable(): bool
    {
        return (bool) $this->evaluate($this->isRetryable);
    }

    public function cacheFor(int|CarbonInterface|Closure|null $ttl): static
    {
        $this->cacheTtl = $ttl;

        return $this;
    }

    public function getCacheTtl(): int|CarbonInterface|null
    {
        return $this->evaluate($this->cacheTtl);
    }

    public function cacheVersion(mixed $version): static
    {
        $this->cacheVersion = $version;

        return $this;
    }

    public function getCacheVersion(): string
    {
        return CellCache::version($this->evaluate($this->cacheVersion));
    }

    /**
     * NOTE: this key is deliberately NOT scoped to the authenticated user, so a
     * warm cache benefits everyone. Resolvers returning viewer-specific data must
     * scope it themselves via ->cacheVersion(fn () => auth()->id()).
     */
    public function getCacheKey(string $recordKey): string
    {
        return CellCache::key(
            $this->getLivewire()::class,
            $this->getName(),
            $recordKey,
            $this->getCacheVersion(),
        );
    }

    /**
     * Remove one or more cached cells written by ->cacheFor().
     *
     * Callable from anywhere - a model observer, a queued job, tinker - so it
     * takes the key's parts rather than a column instance: outside a table
     * render there is no configured column to ask, and $this->getLivewire() (the
     * source of the component-class segment) is only bound during one.
     *
     * $recordKey must be the key the TABLE uses for that row - $table->
     * getRecordKey($record), which is (string) $record->getKey() unless the
     * component overrides getTableRecordKey(). Passing the model's raw primary
     * key when the component customises that identity removes a key nothing
     * wrote.
     *
     * Deliberately no return value: Repository::forget()'s bool means different
     * things per store (the array store reports whether the key existed, Redis
     * reports a DEL count), so surfacing it would be an unreliable signal that
     * reads like a dependable "was it cached?" answer. Forgetting a key that was
     * never written is a no-op, exactly as it is in Laravel's own cache.
     *
     * @param  scalar|array<int, scalar>  $recordKey  One record key, or many.
     * @param  scalar|\DateTimeInterface|Closure|null  $version  The ->cacheVersion() this cell was written under. A Closure is accepted by the signature only so it can be rejected with an explanation - cacheVersion() itself takes mixed, so passing the same value through here is the obvious thing to try.
     */
    public static function forget(
        string $componentClass,
        string $columnName,
        mixed $recordKey,
        mixed $version = null,
    ): void {
        // A Closure would be stringified into something meaningless, removing a
        // key nothing ever wrote - a silent no-op the caller reads as a
        // successful invalidation. There is no column instance and no $record to
        // evaluate it against here, so this is unfixable rather than merely
        // unimplemented, and saying so beats failing quietly.
        if ($version instanceof Closure) {
            throw new LogicException(
                'AsyncColumn::forget() cannot accept a Closure as its $version, because there is no column '
                .'instance or record to evaluate it against outside of a table render. Pass the already-resolved '
                .'value instead. If the version genuinely depends on request state (e.g. cacheVersion(fn () => '
                .'auth()->id())), those keys cannot be targeted from here at all - change the version to bust them.'
            );
        }

        $store = CellCache::store();
        $versionSegment = CellCache::version($version);

        foreach (is_array($recordKey) ? $recordKey : [$recordKey] as $key) {
            $store->forget(CellCache::key($componentClass, $columnName, (string) $key, $versionSegment));
        }
    }

    /**
     * Set the state that came back from an async resolution.
     *
     * HasCellState::state() routes through getStateUsing(), which this class
     * overrides to throw - so assign the underlying property directly.
     *
     * The value is deliberately wrapped in a closure rather than assigned raw.
     * HasCellState::getState() (filament/support) branches on
     * `$this->getStateUsing !== null`: a bare `null` there doesn't mean "the
     * resolved value is null", it means "there is no state callback at all", so
     * Filament would fall through to getStateFromRecord() and silently render
     * the record's own DB attribute (or lazy-load a relationship for a dotted
     * column name) instead of the null the resolver actually returned. A
     * closure is never itself null, so this path is always taken regardless of
     * what $state holds.
     */
    public function resolvedState(mixed $state): static
    {
        $this->getStateUsing = static fn (): mixed => $state;
        $this->clearCachedState();

        return $this;
    }

    /**
     * Render the value that came back from an async resolution.
     *
     * Uses the developer's ->view() when one is set, otherwise delegates to
     * TextColumn's own toEmbeddedHtml() - the same renderer every other TextColumn
     * cell uses - so formatting, escaping, arrays/lists, badges, colors, icons,
     * weight, prefix/suffix etc. all behave exactly as they would on a normal
     * TextColumn, instead of a hand-rolled subset of that behavior.
     *
     * @param  Model|array<string, mixed>  $record
     */
    public function renderResolvedHtml(Model|array $record, mixed $state): string
    {
        $this->record($record);
        $this->resolvedState($state);

        if ($this->hasView()) {
            return $this->render()->render();
        }

        return $this->toEmbeddedHtml();
    }

    public function getCellToken(): ?CellToken
    {
        $recordKey = $this->getRecordKey();

        if (blank($recordKey)) {
            return null;
        }

        return new CellToken($this->getName(), $recordKey);
    }

    /**
     * Always render the placeholder - never the value.
     *
     * ViewComponent::toHtml() would render the developer's ->view() synchronously
     * whenever hasView() is true, and TextColumn (via HasEmbeddedView) would otherwise
     * fall through to toEmbeddedHtml(), which calls getState(). This total override
     * replaces both paths and must never call parent::toHtml() or toEmbeddedHtml().
     */
    public function toHtml(): string
    {
        $token = $this->getCellToken();

        if (! $token instanceof CellToken) {
            return '';
        }

        // Larastan's view-string type calls view()->exists() against the Laravel
        // app IT boots for static analysis, which never runs this package's
        // AsyncColumnServiceProvider::hasViews() registration - so the
        // 'async-column::' namespace can never resolve there even though every
        // RendersPlaceholderTest proves it resolves at runtime.
        // @phpstan-ignore argument.type (namespaced package view, false positive - see comment above)
        return view('async-column::placeholder', [
            'token' => $token->encode(),
            'loadingState' => $this->getLoadingState(),
            'whenVisible' => $this->loadsWhenVisible(),
            'retryable' => $this->isRetryable(),
        ])->render();
    }

    public function getStateUsing(mixed $callback): static
    {
        throw new LogicException(
            'AsyncColumn ['.$this->getName().'] does not support getStateUsing(), because it evaluates during render '
            .'and would block the page load this column exists to avoid. Use resolveUsing() instead.'
        );
    }

    public function sortable(bool|array|Closure $condition = true, ?Closure $query = null): static
    {
        if ($query === null && $condition !== false) {
            throw new LogicException(
                'AsyncColumn ['.$this->getName().'] cannot be sorted, because its value is resolved after the query has run. '
                .'Pass a query closure - sortable(query: fn (Builder $query, string $direction) => ...) - to sort it yourself.'
            );
        }

        return parent::sortable($condition, $query);
    }

    public function searchable(bool|array|string|Closure $condition = true, ?Closure $query = null, bool $isIndividual = false, bool $isGlobal = true): static
    {
        if ($query === null && $condition !== false) {
            throw new LogicException(
                'AsyncColumn ['.$this->getName().'] cannot be searched, because its value is resolved after the query has run. '
                .'Pass a query closure - searchable(query: fn (Builder $query, string $search) => ...) - to search it yourself.'
            );
        }

        return parent::searchable($condition, $query, $isIndividual, $isGlobal);
    }
}
