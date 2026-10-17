<?php

namespace App\Filament\Resources\CibilRequests;

use App\Filament\Resources\CibilRequests\Pages\ListCibilRequests;
use App\Filament\Resources\CibilRequests\Tables\CibilRequestsTable;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

use function Filament\Support\original_request;

/**
 * Every CIBIL request one by one — score, full report and PDF, fetched,
 * failed, uncertain or still pending — for monitoring, where CIBIL Reports
 * shows one row per applicant. Read-only and scoped exactly like CIBIL
 * Reports (CibilReportService::scopeVisible()); it never shows the report
 * itself, only the request's status, timings, the provider's response code
 * and the sanitized error. A row opens the report's own page.
 *
 * Its sidebar entry comes with a second one, Failed Requests: the same
 * listing on its "Failed & uncertain" tab.
 */
class CibilRequestResource extends Resource
{
    protected static ?string $model = CibilReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'CIBIL Management';

    protected static ?string $navigationLabel = 'CIBIL Requests';

    protected static ?string $modelLabel = 'CIBIL request';

    protected static ?string $pluralModelLabel = 'CIBIL requests';

    protected static ?string $slug = 'cibil-requests';

    protected static ?int $navigationSort = 3;

    public const string FAILED_TAB = 'failed';

    public static function table(Table $table): Table
    {
        return CibilRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCibilRequests::route('/'),
        ];
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $onListing = fn (): bool => original_request()->routeIs(static::getNavigationItemActiveRoutePattern());
        $onFailedTab = fn (): bool => $onListing() && original_request()->query('tab') === self::FAILED_TAB;

        return [
            ...array_map(
                fn (NavigationItem $item): NavigationItem => $item->isActiveWhen(fn (): bool => $onListing() && ! $onFailedTab()),
                parent::getNavigationItems(),
            ),

            NavigationItem::make('Failed Requests')
                ->key(static::class.'@failed')
                ->group(static::getNavigationGroup())
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->isActiveWhen($onFailedTab)
                ->sort(static::getNavigationSort() + 1)
                ->url(fn (): string => static::getUrl('index', ['tab' => self::FAILED_TAB])),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['requester.employee', 'customer', 'lead', 'assignedLead']);
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(CibilReportService::class)->scopeVisible($query, $user);
    }

    public static function canAccess(): bool
    {
        return CibilAccess::canUse(Filament::auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
