<?php

namespace GiacomoMasseroni\AsyncColumn\Columns;

use Closure;
use Filament\Tables\Columns\Column;
use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use LogicException;
use Throwable;

class AsyncColumn extends Column
{
    protected ?Closure $resolveUsing = null;

    protected string | Htmlable | Closure | null $loadingState = null;

    protected string | Htmlable | Closure | null $errorState = null;

    protected bool | Closure $loadsWhenVisible = false;

    protected bool | Closure $isRetryable = true;

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
     * whenever hasView() is true, which would defeat the entire package.
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
