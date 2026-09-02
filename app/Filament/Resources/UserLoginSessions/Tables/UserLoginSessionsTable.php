<?php

namespace App\Filament\Resources\UserLoginSessions\Tables;

use App\Filament\Exports\UserLoginSessionExporter;
use App\Models\Employee;
use App\Support\HierarchyHelper;
use App\Support\SelectedMonth;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class UserLoginSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('login_at', 'desc')

            ->columns([

                /*
                 * Date
                 */
                TextColumn::make('login_at')
                    ->label('Date')
                    ->date('d M Y h:i A')
                    ->sortable(),

                /*
                 * Employee
                 */
                TextColumn::make('employee.emp_name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable()
                    ->placeholder('N/A'),

                /*
                 * Logout
                 */
                TextColumn::make('logout_at')
                    ->label('Logout')
                    ->date('d M Y h:i A')
                    ->placeholder('Still Logged In')
                    ->sortable(),

                /*
                 * Total session duration.
                 */
                TextColumn::make('session_duration')
                    ->label('Session Duration')
                    ->state(function ($record): string {
                        if (! $record->login_at) {
                            return '-';
                        }

                        $end = $record->logout_at ?? now();

                        $seconds = max(
                            0,
                            $record->login_at->diffInSeconds($end)
                        );

                        return self::formatDuration($seconds);
                    }),

                /*
                 * Actual screen time.
                 */
                TextColumn::make('screen_time_seconds')
                    ->label('Screen Time')
                    ->numeric()
                    ->formatStateUsing(
                        fn ($state): string => self::formatDuration(
                            (int) $state
                        )
                    )
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label('Total Screen Time')
                            ->formatStateUsing(
                                fn ($state): string => self::formatDuration(
                                    (int) $state
                                )
                            )
                    ),

                TextColumn::make('inactive_time')
                    ->label('Inactive Time')
                    ->state(function ($record): string {
                        if (! $record->login_at) {
                            return '-';
                        }

                        $end = $record->logout_at ?? now();

                        $sessionSeconds = max(
                            0,
                            $record->login_at->diffInSeconds($end)
                        );

                        $screenSeconds = max(
                            0,
                            (int) $record->screen_time_seconds
                        );

                        $inactiveSeconds = max(
                            0,
                            $sessionSeconds - $screenSeconds
                        );

                        return self::formatDuration($inactiveSeconds);
                    }),

                TextColumn::make('last_activity_at')
                    ->label('Last Activity')
                    ->date('d M Y h:i: A')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('browser')
                    ->label('Browser')
                    ->state(function ($record): string {
                        $agent = strtolower($record->user_agent ?? '');

                        return match (true) {
                            str_contains($agent, 'edg') => 'Edge',
                            str_contains($agent, 'chrome') => 'Chrome',
                            str_contains($agent, 'firefox') => 'Firefox',
                            str_contains($agent, 'safari') => 'Safari',
                            default => 'Other',
                        };
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('activity_status')
                    ->label('Activity')
                    ->state(function ($record): string {
                        if ($record->logout_at) {
                            return 'Logged Out';
                        }

                        return $record->is_active
                            ? 'Active'
                            : 'Inactive';
                    })
                    ->badge()
                    ->color(function ($record): string {
                        if ($record->logout_at) {
                            return 'gray';
                        }

                        return $record->is_active
                            ? 'success'
                            : 'warning';
                    }),

                // /*
                //  * Current status.
                //  */
                TextColumn::make('logout_reason')
                    ->label('Logout Reason')
                    ->badge()
                    ->formatStateUsing(
                        fn (?string $state, $record): string => $record->logout_at
                            ? ucfirst(
                                str_replace(
                                    '_',
                                    ' ',
                                    $state ?? 'logout'
                                )
                            )
                            : '-'
                    )
                    ->color(
                        fn (?string $state): string => match ($state) {
                            'logout' => 'gray',
                            'session_timeout' => 'warning',
                            'new_login' => 'info',
                            default => 'gray',
                        }
                    )
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                 * IP
                 */
                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->headerActions([

                ExportAction::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(
                        fn (): bool => auth()->user()?->hasRole('Admin') === true
                    )
                    ->exporter(
                        UserLoginSessionExporter::class
                    )
                    ->formats([
                        ExportFormat::Xlsx,
                    ])
                    ->columnMapping(false)
                    ->chunkSize(500),

            ])

            ->filters([

                /*
                 * Employee filter
                 */
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship(
                        'employee',
                        'emp_name'
                    )
                    ->searchable()
                    ->preload(),

                /*
                 * Date range filter
                 */
                Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->label('From Date'),

                        DatePicker::make('until')
                            ->label('To Date'),
                    ])

                    ->query(
                        function (
                            Builder $query,
                            array $data
                        ): Builder {

                            return $query
                                ->when(
                                    $data['from'] ?? null,
                                    fn (Builder $query, $date) => $query->whereDate(
                                        'login_at',
                                        '>=',
                                        $date
                                    )
                                )
                                ->when(
                                    $data['until'] ?? null,
                                    fn (Builder $query, $date) => $query->whereDate(
                                        'login_at',
                                        '<=',
                                        $date
                                    )
                                );
                        }
                    )

                    ->indicateUsing(
                        function (array $data): array {

                            $indicators = [];

                            if (! empty($data['from'])) {
                                $indicators[] =
                                    'From: '.Carbon::parse(
                                        $data['from']
                                    )->format('d M Y');
                            }

                            if (! empty($data['until'])) {
                                $indicators[] =
                                    'Until: '.Carbon::parse(
                                        $data['until']
                                    )->format('d M Y');
                            }

                            return $indicators;
                        }
                    ),
            ])

            ->recordActions([
                ViewAction::make(),
            ])

            ->toolbarActions([
                BulkActionGroup::make([]),
            ])

            // ->modifyQueryUsing(
            //     fn (Builder $query): Builder =>
            //         $query->where(
            //             'login_at',
            //             '>=',
            //             now()->subDays(90)->startOfDay()
            //         )
            // )

            ->modifyQueryUsing(
                function (Builder $query): Builder {

                    $user = Auth::user();

                    /*
                    |--------------------------------------------------------------------------
                    | NO USER
                    |--------------------------------------------------------------------------
                    */

                    if (! $user) {
                        return $query->whereRaw('1 = 0');
                    }

                    // Scoped to the globally selected month — supersedes
                    // the previous hardcoded rolling-90-day floor.
                    $query->whereBetween('login_at', SelectedMonth::range());

                    /*
                    |--------------------------------------------------------------------------
                    | ADMIN
                    |--------------------------------------------------------------------------
                    |
                    | Admin sees ALL login sessions.
                    */

                    if ($user->hasRole('Admin')) {

                        return $query;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | OTHER ROLES
                    |--------------------------------------------------------------------------
                    */

                    $employeeIds = HierarchyHelper::loginVisibleEmployeeIds(
                        $user
                    );

                    return $query
                        ->whereIn(
                            'employee_id',
                            $employeeIds
                        );
                }
            );
    }

    /**
     * Convert seconds to readable format.
     *
     * Examples:
     *
     * 45 seconds   => 00m 45s
     * 12 minutes   => 12m
     * 2 hours      => 02h 15m
     */
    protected static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $hours = intdiv($seconds, 3600);

        $minutes = intdiv(
            $seconds % 3600,
            60
        );

        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf(
                '%02dh %02dm',
                $hours,
                $minutes
            );
        }

        if ($minutes > 0) {
            return sprintf(
                '%02dm',
                $minutes
            );
        }

        return sprintf(
            '%02ds',
            $remainingSeconds
        );
    }
}
