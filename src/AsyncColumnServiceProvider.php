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
}
