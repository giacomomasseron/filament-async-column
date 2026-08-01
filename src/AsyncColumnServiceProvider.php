<?php

namespace GiacomoMasseroni\AsyncColumn;

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

    public function packageBooted(): void
    {
        // Not ->hasTranslations(): spatie/laravel-package-tools registers translations
        // under Package::shortName(), which is Str::after($name, 'laravel-'). Since our
        // package name is "filament-async-column" (no "laravel-" prefix), shortName()
        // is a no-op and returns "filament-async-column" unchanged — a different
        // namespace than the "async-column" one __('async-column::async-column.error')
        // and ->hasViews('async-column') already use. Registering explicitly here keeps
        // the translation namespace aligned with the view namespace.
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'async-column');
    }
}
