<?php

namespace GiacomoMasseroni\AsyncColumn;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use GiacomoMasseroni\AsyncColumn\Livewire\AsyncColumnHook;
use Livewire\ComponentHookRegistry;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AsyncColumnServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-async-column')
            ->hasConfigFile('async-column')
            ->hasViews('async-column');
    }

    /**
     * Registers the component hook during Laravel's REGISTER phase, not boot - this
     * is load-bearing, not stylistic.
     *
     * Livewire\ComponentHookRegistry::boot() (called from LivewireServiceProvider::
     * boot(), verified against livewire/livewire 4.3.4) wires each hook's `mount`/
     * `hydrate` listeners once, from whatever is in its static $componentHooks list
     * at that exact moment; a hook added afterwards never gets those listeners and
     * ComponentHookRegistry::proxyCallToHooks() then never attaches it to any
     * component, for the remainder of the process - its call() is silently never
     * invoked again. Laravel runs register() on every provider before boot() on any
     * provider, so registering here - rather than in packageBooted(), the boot
     * phase, which is where Filament\Support\SupportServiceProvider registers its
     * own equivalent (PartialsComponentHook) - is the only placement safe
     * regardless of this provider's position relative to LivewireServiceProvider in
     * the app's provider list. Confirmed by reproducing the failure: with this call
     * in packageBooted() instead, the very first `resolveAsyncColumns` call in a
     * fresh process throws Livewire's MethodNotFoundException whenever
     * LivewireServiceProvider::boot() happens to run first - exactly the order
     * tests/TestCase.php uses.
     *
     * Calling Livewire\ComponentHookRegistry::register() directly - the same method
     * the Livewire::componentHook() facade call forwards to with no extra behaviour
     * (LivewireManager::componentHook() is a one-line passthrough) - rather than
     * going through the 'livewire' container binding, also sidesteps a second,
     * independent ordering hazard: that binding is only registered once
     * LivewireServiceProvider::register() itself has run, which register() phases
     * are NOT guaranteed to be ordered against ours either.
     */
    public function packageRegistered(): void
    {
        ComponentHookRegistry::register(AsyncColumnHook::class);
    }

    public function packageBooted(): void
    {
        // Not ->hasTranslations(): spatie/laravel-package-tools registers translations
        // under Package::shortName(), which is Str::after($name, 'laravel-'). Since our
        // package name is "filament-async-column" (no "laravel-" prefix), shortName()
        // is a no-op and returns "filament-async-column" unchanged - a different
        // namespace than the "async-column" one __('async-column::async-column.error')
        // and ->hasViews('async-column') already use. Registering explicitly here keeps
        // the translation namespace aligned with the view namespace.
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'async-column');

        FilamentAsset::register([
            Js::make('async-column', __DIR__.'/../resources/js/async-column.js'),
            Css::make('async-column', __DIR__.'/../resources/css/async-column.css'),
        ], package: 'giacomomasseron/filament-async-column');
    }
}
