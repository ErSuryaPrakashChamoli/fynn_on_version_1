<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use App\Models\Employee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;


use Filament\Tables;
use App\Filament\Resources\FollowUps\FollowUpResource;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use App\Filament\Imports\CustomerImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Filament\Exports\CustomerExporter;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use App\Support\HierarchyHelper;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Filters\SelectFilter;
use App\Filament\Resources\Customers\Pages\ContinuePanRequest;



class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;
    protected static ?string $recordTitleAttribute = 'customer_name';
    protected static ?int $navigationSort = 2;
    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {

        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
            'continue-pan-request' => Pages\ContinuePanRequest::route(
                '/continue-pan-request/{request}'
            ),
        ];
    }

    // public static function getEloquentQuery(): Builder
    // {
    //     $query = parent::getEloquentQuery();


    //     $employee = auth()->user()->employee;

    //     if (! $employee) {
    //         return $query;
    //     }

    //     if (auth()->user()->hasRole('Admin')) {
    //         return $query;
    //     }

    //     return $query->whereIn(
    //         'assign_to',
    //         HierarchyHelper::callerIds($employee)
    //     );
    // }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();
        $employee = $user->employee;

        // Admin sees everything
        if ($user->hasRole('Admin')) {
            return $query;
        }

        if (! $employee) {
            return $query->whereRaw('1 = 0');
        }



        return $query->whereIn(
            'assign_to',
            HierarchyHelper::subordinateIds($employee)
        );
    }

    // public static function canEdit(Model $record): bool
    // {
    //     // return auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
    //     //     && ! $record->documents_submitted;

    //     return auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER;
    // }

    public static function canEdit(Model $record): bool
    {
        $employee = auth()->user()->employee;

        if (
            $employee?->designation === Employee::DESIGNATION_CALLER
        ) {
            return false;
        }

        /*
     * Once documents are submitted / application finalized,
     * Customer data becomes immutable.
     */
        if ($record->documents_submitted) {
            return false;
        }

        if ($record->disbursal_finalized) {
            return false;
        }

        return true;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
            && ! $record->documents_submitted;
    }
}
