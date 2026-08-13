<?php

namespace App\Filament\Exports;

use App\Models\UserLoginSession;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class UserLoginSessionExporter extends Exporter
{
    protected static ?string $model = UserLoginSession::class;

    public static function getColumns(): array
    {
        return [

            /*
             * Employee
             */
            ExportColumn::make('employee.emp_name')
                ->label('Employee'),

            /*
             * Login
             */
            ExportColumn::make('login_at')
                ->label('Login')
                ->formatStateUsing(
                    fn($state): string =>
                    $state
                        ? Carbon::parse($state)
                        ->format('d M Y, h:i:s A')
                        : '-'
                ),

            /*
             * Logout
             */
            ExportColumn::make('logout_at')
                ->label('Logout')
                ->formatStateUsing(
                    fn($state): string =>
                    $state
                        ? Carbon::parse($state)
                        ->format('d M Y, h:i:s A')
                        : 'Still Logged In'
                ),

            /*
             * Session Duration
             */
            ExportColumn::make('session_duration')
                ->label('Session Duration')
                ->state(function (
                    UserLoginSession $record
                ): string {

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
             * Screen Time
             */
            ExportColumn::make('screen_time_seconds')
                ->label('Screen Time')
                ->formatStateUsing(
                    fn($state): string =>
                    self::formatDuration(
                        (int) $state
                    )
                ),

            /*
             * Inactive Time
             */
            ExportColumn::make('inactive_time')
                ->label('Inactive Time')
                ->state(function (
                    UserLoginSession $record
                ): string {

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

                    return self::formatDuration(
                        $inactiveSeconds
                    );
                }),

            /*
             * Activity Status
             */
            ExportColumn::make('activity_status')
                ->label('Activity Status')
                ->state(function (
                    UserLoginSession $record
                ): string {

                    if ($record->logout_at) {
                        return 'Logged Out';
                    }

                    return $record->is_active
                        ? 'Active'
                        : 'Inactive';
                }),

            /*
             * Last Activity
             */
            ExportColumn::make('last_activity_at')
                ->label('Last Activity')
                ->formatStateUsing(
                    fn($state): string =>
                    $state
                        ? Carbon::parse($state)
                        ->format('d M Y, h:i:s A')
                        : '-'
                ),

            /*
             * Logout Reason
             */
            ExportColumn::make('logout_reason')
                ->label('Logout Reason')
                ->formatStateUsing(
                    fn($state): string =>
                    $state
                        ? ucfirst(
                            str_replace(
                                '_',
                                ' ',
                                $state
                            )
                        )
                        : '-'
                ),

            /*
             * IP Address
             */
            ExportColumn::make('ip_address')
                ->label('IP Address'),

            /*
             * Browser
             */
            ExportColumn::make('user_agent')
                ->label('Browser')
                ->formatStateUsing(
                    function ($state): string {

                        $agent = strtolower(
                            (string) $state
                        );

                        return match (true) {

                            str_contains(
                                $agent,
                                'edg'
                            ) => 'Microsoft Edge',

                            str_contains(
                                $agent,
                                'chrome'
                            ) => 'Google Chrome',

                            str_contains(
                                $agent,
                                'firefox'
                            ) => 'Mozilla Firefox',

                            str_contains(
                                $agent,
                                'safari'
                            ) => 'Safari',

                            default => 'Other',
                        };
                    }
                ),
        ];
    }

    /**
     * Only allow Admin to export.
     */
    public static function modifyQuery(
        Builder $query
    ): Builder {

        $user = auth()->user();

        /*
         * Extra security:
         * Non-admin gets zero records.
         */
        if (! $user || ! $user->hasRole('Admin')) {
            return $query->whereRaw('1 = 0');
        }

        /*
         * Admin gets the complete 90-day dataset.
         */
        return $query->where(
            'login_at',
            '>=',
            now()->subDays(90)->startOfDay()
        );
    }

    /**
     * Export filename.
     */
    public function getFileName(
        Export $export
    ): string {

        return 'user-login-sessions-' .
            now()->format('d-m-Y-H-i-s');
    }

    public static function getCompletedNotificationBody(
        Export $export
    ): string {
        $body = 'Your user login sessions export has completed and '
            . number_format($export->successful_rows)
            . ' '
            . str('row')->plural($export->successful_rows)
            . ' were exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '
                . number_format($failedRowsCount)
                . ' '
                . str('row')->plural($failedRowsCount)
                . ' failed to export.';
        }

        return $body;
    }

    /**
     * Format seconds.
     */
    protected static function formatDuration(
        int $seconds
    ): string {

        $seconds = max(0, $seconds);

        $hours = intdiv(
            $seconds,
            3600
        );

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
