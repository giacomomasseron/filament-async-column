<?php

namespace GiacomoMasseroni\AsyncColumn\Livewire;

use Filament\Tables\Contracts\HasTable;
use GiacomoMasseroni\AsyncColumn\Resolution\BatchResolver;
use Livewire\ComponentHook;

/**
 * Intercepts the batch resolution call on any Livewire component hosting a
 * Filament table.
 *
 * Livewire fires the `call` hook (HandleComponents.php:546) BEFORE checking that
 * the method exists (line 576), so the host component needs no trait and no
 * method of its own.
 *
 * This hook is instantiated for and asked about EVERY `call` on EVERY Livewire
 * component in the app (ComponentHookRegistry::proxyCallToHooks() loops every
 * registered hook for every component), so both guards below matter: the method
 * name check keeps a component's own methods from ever being shadowed, and the
 * HasTable check keeps a component with no Filament table from being touched at
 * all, even if it happened to define a same-named method.
 */
class AsyncColumnHook extends ComponentHook
{
    public const METHOD = 'resolveAsyncColumns';

    public function call($method, $params, $returnEarly, $metadata, $componentContext)
    {
        if ($method !== self::METHOD) {
            return;
        }

        if (! $this->component instanceof HasTable) {
            return;
        }

        $tokens = $params[0] ?? [];

        $result = app(BatchResolver::class)->resolve(
            $this->component,
            is_array($tokens) ? $tokens : [],
        );

        // Nothing about the table changed, so skip the re-render entirely: the
        // response carries only the token->HTML map, the table query is never
        // re-run, and the cells we already injected on the client survive
        // untouched instead of being fought by a DOM morph.
        $this->component->skipRender();

        $returnEarly($result);
    }
}
