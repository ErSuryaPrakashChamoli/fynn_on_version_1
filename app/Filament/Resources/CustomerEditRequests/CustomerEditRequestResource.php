<?php

namespace App\Filament\Resources\CustomerEditRequests;

use App\Filament\Resources\CustomerEditRequests\Pages\CreateCustomerEditRequest;
use App\Filament\Resources\CustomerEditRequests\Pages\ListCustomerEditRequests;
use App\Filament\Resources\CustomerEditRequests\Pages\ViewCustomerEditRequest;
use App\Filament\Resources\CustomerEditRequests\Schemas\CustomerEditRequestForm;
use App\Filament\Resources\CustomerEditRequests\Schemas\CustomerEditRequestInfolist;
use App\Filament\Resources\CustomerEditRequests\Tables\CustomerEditRequestsTable;
use App\Models\CustomerEditRequest;
use App\Models\User;
use App\Services\CustomerEditRequestService;
use App\Services\OtherBankSupportService;
use App\Support\HierarchyHelper;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Request → Customer Edit Requests: asks to change details on a customer
 * file, one journey section at a time, with a reason. Raised by anyone
 * who may work on the file (owner's chain or a continuity stand-in); only
 * the Admin approves, which writes the values onto the file and logs them.
 */
class CustomerEditRequestResource extends Resource
{
    protected static ?string $model = CustomerEditRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static string|UnitEnum|null $navigationGroup = 'Request';

    protected static ?string $navigationLabel = 'Customer Edit Requests';

    protected static ?string $modelLabel = 'customer edit request';

    protected static ?string $pluralModelLabel = 'customer edit requests';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return CustomerEditRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerEditRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerEditRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerEditRequests::route('/'),
            'create' => CreateCustomerEditRequest::route('/create'),
            'view' => ViewCustomerEditRequest::route('/{record}'),
        ];
    }

    /**
     * Admin sees every request; anyone else the requests on files owned
     * inside their own branch, plus the ones they raised themselves.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['customer', 'requester', 'reviewer', 'items']);
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Admin')) {
            return $query;
        }

        $employee = $user->employee;

        if (! $employee) {
            return $query->where('requested_by', $user->getKey());
        }

        return $query->where(fn (Builder $query): Builder => $query
            ->where('requested_by', $user->getKey())
            ->orWhereHas('customer', fn (Builder $customer): Builder => $customer
                ->whereIn('assign_to', HierarchyHelper::visibleSubordinateIds($employee))));
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && ($user->hasRole('Admin') || ($user->employee !== null && ! OtherBankSupportService::isScopedSupportUser($user)));
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess()
            && static::getEloquentQuery()->whereKey($record->getKey())->exists();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function isReviewer(): bool
    {
        return CustomerEditRequestService::isReviewer(Filament::auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::isReviewer()) {
            return null;
        }

        $pending = CustomerEditRequest::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
