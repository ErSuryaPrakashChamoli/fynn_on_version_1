<?php

namespace App\Filament\Academy\Resources\TrainingBatches\Schemas;

use App\Enums\PortalRole;
use App\Models\User;
use App\Support\Portal\PortalContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class TrainingBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(1),

                TextInput::make('code')
                    ->maxLength(50)
                    ->columnSpan(1),

                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),

                /*
                 * Only trainers in this tenant can own a batch — the
                 * option list is built from portal accounts, never from
                 * the whole users table, so no production LMS user is
                 * ever named in an Academy dropdown.
                 */
                Select::make('trainer_id')
                    ->label('Trainer')
                    ->options(fn (): array => static::trainerOptions())
                    ->required()
                    ->preload()
                    ->default(fn (): ?int => auth()->id())
                    ->columnSpanFull(),

                DatePicker::make('starts_on')
                    ->native(false)
                    ->columnSpan(1),

                DatePicker::make('ends_on')
                    ->native(false)
                    ->afterOrEqual('starts_on')
                    ->columnSpan(1),

                Select::make('status')
                    ->options([
                        'upcoming' => 'Upcoming',
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ])
                    ->default('upcoming')
                    ->required()
                    ->columnSpanFull(),
            ]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function trainerOptions(): array
    {
        $tenantId = app(PortalContext::class)->tenantId();

        return User::query()
            ->whereHas('portalAccount', fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('portal_role', PortalRole::Trainer->value)
                ->where('is_active', true))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
