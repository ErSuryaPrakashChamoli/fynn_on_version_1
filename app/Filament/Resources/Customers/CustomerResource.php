<?php

namespace App\Filament\Resources\Customers;

use App\Enums\JourneyModule;
use App\Filament\Resources\Customers\Pages\ContinuePanRequest;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Support\HierarchyHelper;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'customer_name';

    protected static ?string $navigationLabel = 'My Customers';

    protected static ?string $modelLabel = 'My Customer';

    protected static ?string $pluralModelLabel = 'My Customers';

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
            'continue-pan-request' => ContinuePanRequest::route(
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

        $accessService = app(CustomerJourneyAccessService::class);
        $extraIds = $accessService->visibleCustomerIdsForDelegatee($employee)
            ->merge($accessService->visibleCustomerIdsForTakeover($employee))
            ->unique();

        return $query->where(function (Builder $query) use ($employee, $extraIds) {
            $query->whereIn('assign_to', HierarchyHelper::subordinateIds($employee));

            if ($extraIds->isNotEmpty()) {
                $query->orWhereIn('id', $extraIds);
            }
        });
    }

    // public static function canEdit(Model $record): bool
    // {
    //     // return auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
    //     //     && ! $record->documents_submitted;

    //     return auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER;
    // }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();
        $employee = $user->employee;

        if (
            $employee?->designation === Employee::DESIGNATION_CALLER
        ) {
            return false;
        }

        /*
     * Once documents are submitted / application finalized,
     * Customer data becomes immutable.
     */
        // if ($record->documents_submitted) {
        //     return false;
        // }

        // if ($record->disbursal_finalized) {
        //     return false;
        // }

        if ($user->hasRole('Admin')) {
            return true;
        }

        if ($employee && app(CustomerJourneyAccessService::class)->hasNormalAccess($employee, $record)) {
            return true;
        }

        // Not normally accessible under today's hierarchy rule — allow only
        // when a valid delegation or emergency takeover currently grants
        // access to whichever Manager-stage module this customer is in.
        return Gate::forUser($user)->allows('perform-journey-action', [$record, JourneyModule::forCustomer($record)]);
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
            && ! $record->documents_submitted;
    }
}
