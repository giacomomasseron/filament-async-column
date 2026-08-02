<?php

namespace GiacomoMasseroni\AsyncColumn\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\Livewire\Partials\DataStoreOverride;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use GiacomoMasseroni\AsyncColumn\AsyncColumnServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\DataStore;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            AsyncColumnServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['view']->addNamespace('async-column-tests', __DIR__.'/Fixtures/views');

        // Filament\Support\SupportServiceProvider rebinds Livewire's DataStore mechanism
        // via a plain (non-shared) ->bind(), which drops the singleton Livewire's own
        // Mechanism::register() relies on. Every store($component)->get()/set() call
        // then resolves a fresh, empty instance, so component state (e.g. the error
        // bag) never round-trips and Livewire::test() fails on every render with
        // "ViewErrorBag::put(): Argument #2 ($bag) must be of type MessageBag, null
        // given". Re-binding it as a singleton - after Support's provider has already
        // registered, before providers boot - restores one shared instance per test
        // while keeping Filament's DataStoreOverride behaviour.
        //
        // Confirmed against filament/support 5.7.5 + livewire/livewire 4.3.4. Worth
        // retrying without this line next time either package is bumped, in case
        // it's been fixed upstream - it's harmless to leave in either way, since it
        // degrades to a no-op re-declaration of an already-correct binding.
        $app->singleton(DataStore::class, DataStoreOverride::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('author_id')->default(1);
            $table->timestamps();
        });
    }
}
