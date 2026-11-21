<?php

namespace App\Filament\Resources\CustomerCashbackRequests;

use App\Filament\Resources\CustomerCashbackRequests\Pages\CreateCustomerCashbackRequest;
use App\Filament\Resources\CustomerCashbackRequests\Pages\ListCustomerCashbackRequests;
use App\Filament\Resources\CustomerCashbackRequests\Pages\ViewCustomerCashbackRequest;
use App\Filament\Resources\CustomerCashbackRequests\Schemas\CustomerCashbackRequestForm;
use App\Filament\Resources\CustomerCashbackRequests\Schemas\CustomerCashbackRequestInfolist;
use App\Filament\Resources\CustomerCashbackRequests\Tables\CustomerCashbackRequestsTable;
use App\Models\CustomerCashbackRequest;
use App\Models\User;
use App\Services\CustomerCashbackService;
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
 * Customers → Cashback Forms: the digital "Acknowledgment" voucher. Filled
 * by a Team Leader or Manager on a file declared for cashback or expected
 * cashback, approved Manager → Admin, then paid and recorded by the Admin
 * or Accounts. See CustomerCashbackService for the rules.
 */
class CustomerCashbackRequestResource extends Resource
{
    protected static ?string $model = CustomerCashbackRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Request';

    protected static ?string $navigationLabel = 'Cashback Forms';

    protected static ?string $modelLabel = 'cashback form';

    protected static ?string $pluralModelLabel = 'cashback forms';

    protected static ?string $recordTitleAttribute = 'voucher_no';

    public static function form(Schema $schema): Schema
    {
        return CustomerCashbackRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerCashbackRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerCashbackRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerCashbackRequests::route('/'),
            'create' => CreateCustomerCashbackRequest::route('/create'),
            'view' => ViewCustomerCashbackRequest::route('/{record}'),
        ];
    }

    /**
     * Admin and Accounts see every form; anyone else the forms on files
     * owned inside their own branch, plus the ones they filled.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['customer', 'requester', 'aro', 'teamLeader', 'manager', 'clusterManager', 'supervisor']);
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if (CustomerCashbackService::isPaymentUser($user)) {
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
            && (CustomerCashbackService::isPaymentUser($user)
                || ($user->employee !== null && ! OtherBankSupportService::isScopedSupportUser($user)));
    }

    /** Team Leader and above (or the Admin) fill forms. */
    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return static::canAccess()
            && $user instanceof User
            && ($user->hasRole('Admin') || $user->hasAnyRole(CustomerCashbackService::RAISER_ROLES));
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

    /** Forms waiting on the signed-in user: the Admin's queue, or Accounts' unpaid approved forms. */
    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $count = match (true) {
            $user->hasRole('Admin') => CustomerCashbackRequest::query()->pending()->count(),
            $user->hasRole('Accounts') => CustomerCashbackRequest::query()
                ->where('status', 'approved')
                ->where('payment_status', '!=', 'paid')
                ->count(),
            default => 0,
        };

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
