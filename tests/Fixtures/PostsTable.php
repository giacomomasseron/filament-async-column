<?php

namespace GiacomoMasseroni\AsyncColumn\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\Column;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class PostsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** @var array<int, Column> */
    public static array $columns = [];

    /** @var (\Closure(Builder): Builder)|null */
    public static $scope = null;

    /** @var array<int, BaseFilter> */
    public static array $filters = [];

    public function table(Table $table): Table
    {
        $query = Post::query();

        if (static::$scope !== null) {
            $query = (static::$scope)($query);
        }

        return $table
            ->query($query)
            ->columns(static::$columns)
            ->filters(static::$filters);
    }

    public function render(): View
    {
        return view('async-column-tests::posts-table');
    }
}
