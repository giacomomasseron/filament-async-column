<?php

namespace GiacomoMasseroni\AsyncColumn\Tests\Fixtures;

use Closure;
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
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class PostsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable {
        getTableRecordKey as baseGetTableRecordKey;
    }

    /** @var array<int, Column> */
    public static array $columns = [];

    /** @var (Closure(Builder): Builder)|null */
    public static $scope = null;

    /** @var array<int, BaseFilter> */
    public static array $filters = [];

    /**
     * Lets a test override how record keys are derived, so BatchResolver's use
     * of $table->getRecordKey() (which delegates to this method) can be proven
     * to diverge from a naive (string) $record->getKey() when a component
     * customizes it — the exact scenario Filament\Tables\Contracts\HasTable
     * ::getTableRecordKey() exists to support.
     *
     * @var (Closure(Model|array): string)|null
     */
    public static ?Closure $recordKeyUsing = null;

    public function getTableRecordKey(Model|array $record): string
    {
        if (static::$recordKeyUsing !== null) {
            return (static::$recordKeyUsing)($record);
        }

        return $this->baseGetTableRecordKey($record);
    }

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
