<?php

namespace GiacomoMasseroni\AsyncColumn\Columns;

use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Filament\Tables\Columns\TextColumn;
use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use LogicException;
use Throwable;

class AsyncColumn extends TextColumn
{
    protected ?Closure $resolveUsing = null;

    protected string | Htmlable | Closure | null $loadingState = null;

    protected string | Htmlable | Closure | null $errorState = null;

    protected bool | Closure $loadsWhenVisible = false;

    protected bool | Closure $isRetryable = true;

    protected int | CarbonInterface | Closure | null $cacheTtl = null;

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

    public function loadingState(string | Htmlable | Closure | null $state): static
    {
        $this->loadingState = $state;

        return $this;
    }

    public function loadingStateUsing(?Closure $callback): static
    {
        return $this->loadingState($callback);
    }

    public function getLoadingState(): string | Htmlable | null
    {
        return $this->evaluate($this->loadingState)
            ?? new HtmlString('<span class="fi-async-column-skeleton" aria-hidden="true"></span>');
    }

    public function errorState(string | Htmlable | Closure | null $state): static
    {
        $this->errorState = $state;

        return $this;
    }

    public function errorStateUsing(?Closure $callback): static
    {
        return $this->errorState($callback);
    }

    public function getErrorState(?Throwable $exception = null): string | Htmlable | null
    {
        // The exception is only ever exposed to an explicit developer callback.
        // Defaulting to $exception->getMessage() would leak API credentials and SQL.
        $state = $this->evaluate($this->errorState, ['exception' => $exception]);

        return $state ?? __('async-column::async-column.error');
    }

    public function whenVisible(bool | Closure $condition = true): static
    {
        $this->loadsWhenVisible = $condition;

        return $this;
    }

    public function loadsWhenVisible(): bool
    {
        return (bool) $this->evaluate($this->loadsWhenVisible);
    }

    public function retryable(bool | Closure $condition = true): static
    {
        $this->isRetryable = $condition;

        return $this;
    }

    public function isRetryable(): bool
    {
        return (bool) $this->evaluate($this->isRetryable);
    }

    public function cacheFor(int | CarbonInterface | Closure | null $ttl): static
    {
        $this->cacheTtl = $ttl;

        return $this;
    }

    public function getCacheTtl(): int | CarbonInterface | null
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
        $version = $this->evaluate($this->cacheVersion);

        if ($version === null) {
            return '';
        }

        return $version instanceof DateTimeInterface
            ? $version->format('U')
            : (string) $version;
    }

    /**
     * NOTE: this key is deliberately NOT scoped to the authenticated user, so a
     * warm cache benefits everyone. Resolvers returning viewer-specific data must
     * scope it themselves via ->cacheVersion(fn () => auth()->id()).
     */
    public function getCacheKey(string $recordKey): string
    {
        return implode(':', [
            config('async-column.cache_prefix', 'async-column'),
            $this->getLivewire()::class,
            $this->getName(),
            $recordKey,
            $this->getCacheVersion(),
        ]);
    }

    /**
     * Set the state that came back from an async resolution.
     *
     * HasCellState::state() routes through getStateUsing(), which this class
     * overrides to throw — so assign the underlying property directly.
     */
    public function resolvedState(mixed $state): static
    {
        $this->getStateUsing = $state;
        $this->clearCachedState();

        return $this;
    }

    /**
     * Render the value that came back from an async resolution.
     *
     * Uses the developer's ->view() when one is set, otherwise delegates to
     * TextColumn's own toEmbeddedHtml() — the same renderer every other TextColumn
     * cell uses — so formatting, escaping, arrays/lists, badges, colors, icons,
     * weight, prefix/suffix etc. all behave exactly as they would on a normal
     * TextColumn, instead of a hand-rolled subset of that behavior.
     */
    public function renderResolvedHtml(Model | array $record, mixed $state): string
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
     * Always render the placeholder — never the value.
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

        return view('async-column::placeholder', [
            'token' => $token->encode(),
            'loadingState' => $this->getLoadingState(),
            'whenVisible' => $this->loadsWhenVisible(),
        ])->render();
    }

    public function getStateUsing(mixed $callback): static
    {
        throw new LogicException(
            'AsyncColumn ['.$this->getName().'] does not support getStateUsing(), because it evaluates during render '
            .'and would block the page load this column exists to avoid. Use resolveUsing() instead.'
        );
    }

    public function sortable(bool | array | Closure $condition = true, ?Closure $query = null): static
    {
        if ($query === null && $condition !== false) {
            throw new LogicException(
                'AsyncColumn ['.$this->getName().'] cannot be sorted, because its value is resolved after the query has run. '
                .'Pass a query closure — sortable(query: fn (Builder $query, string $direction) => ...) — to sort it yourself.'
            );
        }

        return parent::sortable($condition, $query);
    }

    public function searchable(bool | array | string | Closure $condition = true, ?Closure $query = null, bool $isIndividual = false, bool $isGlobal = true): static
    {
        if ($query === null && $condition !== false) {
            throw new LogicException(
                'AsyncColumn ['.$this->getName().'] cannot be searched, because its value is resolved after the query has run. '
                .'Pass a query closure — searchable(query: fn (Builder $query, string $search) => ...) — to search it yourself.'
            );
        }

        return parent::searchable($condition, $query, $isIndividual, $isGlobal);
    }
}
