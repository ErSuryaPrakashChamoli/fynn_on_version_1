<?php

namespace App\Filament\Resources\CibilReports;

use App\Filament\Resources\CibilReports\Pages\CreateCibilReport;
use App\Filament\Resources\CibilReports\Pages\ListCibilReports;
use App\Filament\Resources\CibilReports\Pages\ViewCibilReport;
use App\Filament\Resources\CibilReports\Schemas\CibilReportForm;
use App\Filament\Resources\CibilReports\Schemas\CibilReportInfolist;
use App\Filament\Resources\CibilReports\Tables\CibilReportsTable;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
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
 * CIBIL pulls and their history. Open to the Admin and to whoever the Admin
 * has granted access on the CIBIL Access page (App\Support\CibilAccess).
 * The Admin sees every report; everyone else sees the reports they fetched.
 * The listing shows one row per applicant (PAN) with how often it was
 * pulled; each pull stays its own report, reachable from the applicant's
 * history. Reports are never edited or deleted — fetching again adds a new
 * one; the manager's bureau decision on a lead is the only later write.
 */
class CibilReportResource extends Resource
{
    protected static ?string $model = CibilReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'CIBIL Reports';

    protected static ?string $modelLabel = 'CIBIL report';

    protected static ?string $pluralModelLabel = 'CIBIL reports';

    protected static ?string $recordTitleAttribute = 'customer_name';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return CibilReportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CibilReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CibilReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCibilReports::route('/'),
            'create' => CreateCibilReport::route('/create'),
            'view' => ViewCibilReport::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['requester.employee', 'customer', 'lead', 'assignedLead', 'decider']);
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(CibilReportService::class)->scopeVisible($query, $user);
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && CibilAccess::canUse($user);
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $record instanceof CibilReport
            && app(CibilReportService::class)->canView($record, $user);
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
