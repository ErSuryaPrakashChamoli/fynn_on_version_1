<?php

namespace App\Filament\Resources\CustomerEligibilityRequests;

use App\Enums\EligibilityRequestStatus;
use App\Filament\Resources\CustomerEligibilityRequests\Pages\CreateCustomerEligibilityRequest;
use App\Filament\Resources\CustomerEligibilityRequests\Pages\ListCustomerEligibilityRequests;
use App\Filament\Resources\CustomerEligibilityRequests\Schemas\CustomerEligibilityRequestForm;
use App\Filament\Resources\CustomerEligibilityRequests\Tables\CustomerEligibilityRequestsTable;
use App\Models\CustomerEligibilityRequest;
use App\Models\User;
use App\Services\CustomerEligibilityService;
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
 * The queue of "make this Not Eligible file eligible" requests. Raised from
 * the customer page (CustomerEligibilitySection) or this resource's create
 * page; only the Admin approves or rejects. The owner's chain sees the
 * requests on their branch's files.
 */
class CustomerEligibilityRequestResource extends Resource
{
    protected static ?string $model = CustomerEligibilityRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Request';

    protected static ?string $navigationLabel = 'Eligibility Requests';

    protected static ?string $modelLabel = 'eligibility request';

    protected static ?string $pluralModelLabel = 'eligibility requests';

    public static function form(Schema $schema): Schema
    {
        return CustomerEligibilityRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerEligibilityRequestsTable::configure($table);
    }

    /**
     * Admin sees every request; anyone else only requests on files owned
     * inside their own branch of the reporting tree.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Admin')) {
            return $query;
        }

        $employee = $user->employee;

        if (! $employee || OtherBankSupportService::isSupportUser($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('customer', fn (Builder $customer): Builder => $customer
            ->whereIn('assign_to', HierarchyHelper::visibleSubordinateIds($employee)));
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && ($user->hasRole('Admin') || ($user->employee !== null && ! OtherBankSupportService::isScopedSupportUser($user)));
    }

    /** Anyone in the reporting tree raises; the Admin only reviews. */
    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return static::canAccess()
            && $user instanceof User
            && ! CustomerEligibilityService::isReviewer($user)
            && $user->employee !== null;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Pending count for the Admin, who is the one who has to act. */
    public static function getNavigationBadge(): ?string
    {
        if (! CustomerEligibilityService::isReviewer(Filament::auth()->user())) {
            return null;
        }

        $pending = CustomerEligibilityRequest::query()
            ->where('status', EligibilityRequestStatus::Pending->value)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerEligibilityRequests::route('/'),
            'create' => CreateCustomerEligibilityRequest::route('/create'),
        ];
    }
}
