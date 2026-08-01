<?php

use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use GiacomoMasseroni\AsyncColumn\Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function (): void {
        PostsTable::$columns = [];
        PostsTable::$scope = null;
    })
    ->in(__DIR__);
