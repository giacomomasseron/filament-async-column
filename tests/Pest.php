<?php

use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use GiacomoMasseroni\AsyncColumn\Tests\TestCase;
use Livewire\Livewire;

uses(TestCase::class)
    ->beforeEach(function (): void {
        PostsTable::$columns = [];
        PostsTable::$scope = null;
        PostsTable::$filters = [];
        PostsTable::$recordKeyUsing = null;
    })
    ->in(__DIR__);

/**
 * Mount the fixture table and return the component itself.
 *
 * Livewire's Testable::instance() is declared as returning the base Component,
 * so every call site that hands the result to something expecting a HasTable
 * (BatchResolver::resolve(), most of this suite) is unprovable to a static
 * analyser. Narrowing once here beats narrowing at two dozen call sites, and the
 * instanceof check is a genuine assertion rather than a suppression: if Livewire
 * ever hands back something else, the tests say so instead of failing obscurely.
 */
function mountPostsTable(?Closure $configure = null): PostsTable
{
    $testable = Livewire::test(PostsTable::class);

    if ($configure !== null) {
        $testable = $configure($testable);
    }

    $instance = $testable->instance();

    if (! $instance instanceof PostsTable) {
        throw new RuntimeException('Expected Livewire to return a PostsTable instance, got '.$instance::class.'.');
    }

    return $instance;
}
