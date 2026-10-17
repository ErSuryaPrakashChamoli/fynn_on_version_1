<?php

namespace App\Filament\Widgets;

use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Models\CibilReport;
use App\Models\User;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ten newest CIBIL requests the viewer can see, on the CIBIL Dashboard.
 * CibilRecentFailures narrows it to the ones that failed or are uncertain.
 */
class CibilRecentRequests extends TableWidget
{
    protected static ?string $heading = 'Recent requests';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return CibilAccess::canUse(Filament::auth()->user());
    }

    /**
     * @param  Builder<CibilReport>  $query
     * @return Builder<CibilReport>
     */
    protected function narrow(Builder $query): Builder
    {
        return $query;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $user = Filament::auth()->user();
                $query = CibilReport::query()->with('requester');

                return $this->narrow($user instanceof User
                    ? app(CibilReportService::class)->scopeVisible($query, $user)
                    : $query->whereRaw('1 = 0'));
            })
            ->defaultSort('id', 'desc')
            ->paginated(false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->limit(10))
            ->recordUrl(fn (CibilReport $record): string => CibilReportResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Nothing here yet')
            ->columns([
                TextColumn::make('customer_name')
                    ->label('Customer / lead')
                    ->description(fn (CibilReport $record): ?string => $record->subjectType()),

                TextColumn::make('request_type')
                    ->label('Request')
                    ->badge()
                    ->formatStateUsing(fn (CibilRequestType $state): string => $state->label())
                    ->color(fn (CibilRequestType $state): string => $state->color()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (CibilReportStatus $state): string => $state->label())
                    ->color(fn (CibilReportStatus $state): string => $state->color()),

                TextColumn::make('score')
                    ->label('Score')
                    ->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => $state < 300 ? 'NH' : (string) $state),

                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(60)
                    ->placeholder('—'),

                TextColumn::make('requester.name')
                    ->label('Requested by')
                    ->placeholder('Former user'),

                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('d M Y, h:i A'),
            ]);
    }
}
