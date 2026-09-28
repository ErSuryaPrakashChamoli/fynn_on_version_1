<?php

namespace App\Filament\Resources\ComplaintCategories\Schemas;

use App\Enums\ComplaintRouting;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class ComplaintCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(80)
                            ->unique(ignoreRecord: true),

                        TextInput::make('sort_order')
                            ->label('Order')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Lower numbers appear first in the dropdown.'),

                        Textarea::make('description')
                            ->label('Shown to the user under the category')
                            ->rows(2)
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Available for new tickets')
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Where do these tickets go?')
                    ->schema([
                        ToggleButtons::make('routing')
                            ->label('Route to')
                            ->options(ComplaintRouting::options())
                            ->helperText(fn (Get $get): string => ComplaintRouting::tryFrom((string) $get('routing'))?->description() ?? '')
                            ->icons([
                                ComplaintRouting::Team->value => 'heroicon-o-user-group',
                                ComplaintRouting::Supervisor->value => 'heroicon-o-arrow-trending-up',
                            ])
                            ->inline()
                            ->default(ComplaintRouting::Team->value)
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        Select::make('handler_roles')
                            ->label('Handling team (roles)')
                            ->multiple()
                            ->preload()
                            ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                            ->required(fn (Get $get): bool => $get('routing') === ComplaintRouting::Team->value)
                            ->visible(fn (Get $get): bool => $get('routing') === ComplaintRouting::Team->value)
                            ->helperText('Everyone holding any of these roles sees the ticket and can take it up. The first role is stamped on the ticket as its team.')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Reasons')
                    ->description('The "reason for complaint" dropdown shown once this category is picked.')
                    ->schema([
                        Repeater::make('reasons')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('name')
                                    ->label('Reason')
                                    ->required()
                                    ->maxLength(120)
                                    ->columnSpan(3),

                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->inline(false),
                            ])
                            ->columns(4)
                            ->orderColumn('sort_order')
                            ->reorderableWithButtons()
                            ->addActionLabel('Add a reason')
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
