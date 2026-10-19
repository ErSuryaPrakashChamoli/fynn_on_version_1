<?php

namespace App\Filament\Pages;

use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use App\Support\CibilDashboardFilters;
use App\Support\HierarchyHelper;
use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * CIBIL Management → Employee Activity: per employee, how many score
 * checks, full reports and PDFs they requested, how many succeeded or
 * failed, how many customers and leads they checked, their success rate,
 * average provider time and last request — for the dashboard's filters.
 *
 * Counted from the requests the viewer can see. The Admin sees everyone;
 * a login with the audit permission sees only their own reporting branch
 * (HierarchyHelper::visibleSubordinateIds), enforced in the query.
 */
class CibilEmployeeActivity extends Page implements HasTable
{
    use HasFiltersForm, InteractsWithTable {
        InteractsWithTable::normalizeTableFilterValuesFromQueryString insteadof HasFiltersForm;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static string|UnitEnum|null $navigationGroup = 'CIBIL Management';

    protected static ?string $navigationLabel = 'Employee Activity';

    protected static ?string $title = 'Bureau Employee Activity';

    protected static ?string $slug = 'cibil-employee-activity';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        return CibilAccess::canViewAudit(Filament::auth()->user());
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components(CibilDashboardFilters::components());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->activityQuery())
            ->defaultSort('total_count', 'desc')
            ->emptyStateHeading('No requests in this period')
            ->columns([
                TextColumn::make('name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $record): ?string => $record->employee?->emp_id),

                TextColumn::make('total_count')->label('Requests')->sortable()->numeric(),
                TextColumn::make('score_count')->label('Score checks')->sortable()->numeric(),
                TextColumn::make('full_report_count')->label('Full reports')->sortable()->numeric(),
                TextColumn::make('pdf_count')->label('PDFs')->sortable()->numeric(),
                TextColumn::make('success_count')->label('Succeeded')->sortable()->numeric()->color('success'),
                TextColumn::make('failed_count')->label('Failed / uncertain')->sortable()->numeric()->color('danger'),

                TextColumn::make('success_rate')
                    ->label('Success rate')
                    ->state(fn (User $record): string => self::rate((int) $record->getAttribute('success_count'), (int) $record->getAttribute('completed_count'))),

                TextColumn::make('customers_count')->label('Customers checked')->sortable()->numeric(),
                TextColumn::make('leads_count')->label('Leads checked')->sortable()->numeric(),

                TextColumn::make('avg_duration_ms')
                    ->label('Avg provider time')
                    ->sortable()
                    ->formatStateUsing(fn (mixed $state): string => round((float) $state / 1000, 1).' s')
                    ->placeholder('—'),

                TextColumn::make('last_activity_at')
                    ->label('Last request')
                    ->sortable()
                    ->dateTime('d M Y, h:i A'),
            ]);
    }

    /**
     * One row per employee who requested something in scope, with their
     * counts as correlated subqueries over the same filtered requests.
     *
     * @return Builder<User>
     */
    protected function activityQuery(): Builder
    {
        $viewer = Filament::auth()->user();

        if (! $viewer instanceof User || ! static::canAccess()) {
            return User::query()->whereRaw('1 = 0');
        }

        $requests = function (?Closure $narrow = null) use ($viewer): Builder {
            $query = CibilDashboardFilters::apply(
                app(CibilReportService::class)->scopeVisible(CibilReport::query(), $viewer),
                $this->filters,
            );

            return $narrow !== null ? $narrow($query) : $query;
        };

        $perUser = fn (string $aggregate, ?Closure $narrow = null): Builder => $requests($narrow)
            ->selectRaw($aggregate)
            ->whereColumn('cibil_reports.requested_by', 'users.id');

        return User::query()
            ->with('employee')
            ->whereIn('users.id', $requests()->whereNotNull('requested_by')->select('requested_by'))
            ->when(! CibilAccess::canManage($viewer), fn (Builder $query): Builder => $this->limitToBranch($query, $viewer))
            ->select('users.*')
            ->addSelect([
                'total_count' => $perUser('COUNT(*)'),
                'score_count' => $perUser('COUNT(*)', fn (Builder $query): Builder => $query->where('request_type', CibilRequestType::Score)),
                'full_report_count' => $perUser('COUNT(*)', fn (Builder $query): Builder => $query->where('request_type', CibilRequestType::FullReport)),
                'pdf_count' => $perUser('COUNT(*)', fn (Builder $query): Builder => $query->where('request_type', CibilRequestType::Pdf)),
                'success_count' => $perUser('COUNT(*)', fn (Builder $query): Builder => $query->where('status', CibilReportStatus::Success)),
                'failed_count' => $perUser('COUNT(*)', fn (Builder $query): Builder => $query->whereIn('status', [CibilReportStatus::Failed, CibilReportStatus::Uncertain])),
                'completed_count' => $perUser('COUNT(*)', fn (Builder $query): Builder => $query->whereIn('status', [CibilReportStatus::Success, CibilReportStatus::Failed, CibilReportStatus::Uncertain])),
                'customers_count' => $perUser('COUNT(DISTINCT customer_id)'),
                'leads_count' => $perUser('COUNT(DISTINCT lead_id) + COUNT(DISTINCT customer_assignment_id)'),
                'avg_duration_ms' => $perUser('AVG(duration_ms)'),
                'last_activity_at' => $perUser('MAX(cibil_reports.created_at)'),
            ]);
    }

    /**
     * Someone other than the Admin sees only the people in their own
     * reporting branch (themselves included).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    protected function limitToBranch(Builder $query, User $viewer): Builder
    {
        $employee = $viewer->employee;

        if ($employee === null) {
            return $query->whereKey($viewer->getKey());
        }

        return $query->whereIn('users.employee_id', HierarchyHelper::visibleSubordinateIds($employee));
    }

    protected static function rate(int $part, int $whole): string
    {
        return $whole === 0 ? '—' : round($part / $whole * 100, 1).'%';
    }
}
