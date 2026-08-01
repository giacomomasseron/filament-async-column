<?php

namespace GiacomoMasseroni\AsyncColumn\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PostsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** @var array<int, \Filament\Tables\Columns\Column> */
    public static array $columns = [];

    public function table(Table $table): Table
    {
        return $table
            ->query(Post::query())
            ->columns(static::$columns);
    }

    public function render(): View
    {
        return view('async-column-tests::posts-table');
    }
}
