<?php

namespace App\Filament\Resources\UserLoginSessions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserLoginSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Employee')
                    ->schema([
                        TextEntry::make('employee.emp_name')
                            ->label('Employee'),

                        TextEntry::make('user.email')
                            ->label('Email')
                            ->placeholder('-'),
                    ])
                    ->columns(2),

                Section::make('Login Session')
                    ->schema([
                        TextEntry::make('login_at')
                            ->label('Login Date & Time')
                            ->dateTime('d M Y h:i:s A'),

                        TextEntry::make('logout_at')
                            ->label('Logout Date & Time')
                            ->dateTime('d M Y h:i:s A')
                            ->placeholder('Still Logged In'),

                        TextEntry::make('last_seen_at')
                            ->label('Last Active')
                            ->dateTime('d M Y h:i:s A')
                            ->placeholder('-'),

                        TextEntry::make('logout_reason')
                            ->label('Logout Reason')
                            ->formatStateUsing(
                                fn (?string $state): string =>
                                    $state
                                        ? ucfirst(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $state
                                            )
                                        )
                                        : 'Active'
                            ),
                    ])
                    ->columns(2),

                Section::make('Screen Time')
                    ->schema([
                        TextEntry::make('screen_time_seconds')
                            ->label('Actual Screen Time')
                            ->formatStateUsing(
                                function ($state): string {

                                    $seconds = max(
                                        0,
                                        (int) $state
                                    );

                                    $hours = intdiv(
                                        $seconds,
                                        3600
                                    );

                                    $minutes = intdiv(
                                        $seconds % 3600,
                                        60
                                    );

                                    if ($hours > 0) {
                                        return "{$hours}h {$minutes}m";
                                    }

                                    return "{$minutes}m";
                                }
                            )
                            ->size('lg'),
                    ]),

                Section::make('Device Information')
                    ->schema([
                        TextEntry::make('ip_address')
                            ->label('IP Address'),

                        TextEntry::make('user_agent')
                            ->label('Browser / Device')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
