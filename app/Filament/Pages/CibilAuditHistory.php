<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * CIBIL Management → Audit History: everything the CIBIL module wrote to
 * the activity log — requests, reuse, views, PDF openings, bureau
 * decisions, verifications, settings and access changes. Read-only. The
 * Admin sees all of it; a login with the audit permission sees the entries
 * for the reports they can see.
 */
class CibilAuditHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'CIBIL Management';

    protected static ?string $navigationLabel = 'Audit History';

    protected static ?string $title = 'CIBIL Audit History';

    protected static ?string $slug = 'cibil-audit-history';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.cibil-audit-history';

    /** @var array<string, string> */
    public const array EVENTS = [
        'created' => 'Request sent',
        'reused' => 'Report reused',
        'viewed' => 'Report viewed',
        'pdf_downloaded' => 'PDF opened',
        'pdf_retried' => 'PDF download retried',
        'bureau_decision' => 'Bureau decision',
        'uncertain_resolved' => 'Uncertain request verified',
        'linked' => 'Linked to customer file',
        'updated' => 'Settings / access changed',
        'refused' => 'Request not sent (refused)',
        'invalid' => 'Details / consent rejected',
        'denied' => 'Access denied',
    ];

    public static function canAccess(): bool
    {
        return CibilAccess::canViewAudit(Filament::auth()->user());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->auditQuery())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label('By')
                    ->placeholder('System'),

                TextColumn::make('event')
                    ->label('What')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::EVENTS[$state] ?? (string) $state)
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Details')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('subject_id')
                    ->label('Report')
                    ->formatStateUsing(fn (mixed $state): string => "#{$state}")
                    ->url(fn (Activity $record): ?string => $record->subject_type === (new CibilReport)->getMorphClass() && $record->subject_id
                        ? CibilReportResource::getUrl('view', ['record' => $record->subject_id])
                        : null)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('What')
                    ->options(self::EVENTS),

                Filter::make('between')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ]);
    }

    /**
     * @return Builder<Activity>
     */
    protected function auditQuery(): Builder
    {
        $user = Filament::auth()->user();
        $reportClass = (new CibilReport)->getMorphClass();

        $query = Activity::query()
            ->with('causer')
            ->where(fn (Builder $query): Builder => $query
                ->where('log_name', CibilReportService::LOG_NAME)
                // Entries written before the CIBIL log name existed.
                ->orWhere('subject_type', $reportClass));

        if (CibilAccess::canManage($user)) {
            return $query;
        }

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('subject_type', $reportClass)
            ->whereIn('subject_id', app(CibilReportService::class)->scopeVisible(CibilReport::query(), $user)->select('cibil_reports.id'));
    }
}
