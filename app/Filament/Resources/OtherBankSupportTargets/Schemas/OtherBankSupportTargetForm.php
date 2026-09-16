<?php

namespace App\Filament\Resources\OtherBankSupportTargets\Schemas;

use App\Models\OtherBankSupportTarget;
use App\Models\User;
use App\Services\OtherBankSupportService;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class OtherBankSupportTargetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Monthly target')
                    ->description('Other-bank count achievement this user is expected to reach in the month. Set it before the month starts. The LMS target and incentive calculation are not affected.')
                    ->schema([
                        Select::make('user_id')
                            ->label('Support user')
                            ->options(fn (?OtherBankSupportTarget $record): array => self::userOptions($record))
                            ->preload()
                            ->required()
                            // One row per user per month is a database
                            // constraint; surface it as a form error.
                            ->rule(fn (Get $get, ?OtherBankSupportTarget $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                                $month = Carbon::parse($get('month') ?: today())->startOfMonth();

                                $exists = OtherBankSupportTarget::query()
                                    ->where('user_id', $value)
                                    ->forMonth($month)
                                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('This user already has a target for '.$month->format('M Y').'.');
                                }
                            }),

                        DatePicker::make('month')
                            ->label('Month')
                            ->native(false)
                            ->displayFormat('M Y')
                            ->default(today()->startOfMonth())
                            ->required()
                            ->dehydrateStateUsing(fn ($state): ?string => $state
                                ? Carbon::parse($state)->startOfMonth()->toDateString()
                                : null),

                        TextInput::make('target_amount')
                            ->label('Target amount (₹)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->helperText(fn ($state): ?string => filled($state)
                                ? indianAmount($state).' — '.indianAmountInWords($state)
                                : null),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Active Other Bank Support users, plus the record's own user so an
     * existing target stays readable after that user is switched off.
     *
     * @return array<int, string>
     */
    protected static function userOptions(?OtherBankSupportTarget $record): array
    {
        return app(OtherBankSupportService::class)
            ->supportUsers()
            ->when($record?->user, fn ($users) => $users->push($record->user))
            ->unique('id')
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
    }
}
