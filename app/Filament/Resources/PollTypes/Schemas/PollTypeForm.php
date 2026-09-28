<?php

namespace App\Filament\Resources\PollTypes\Schemas;

use App\Models\PollType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PollTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Poll type')
                    ->description('The options become the answer dropdown on every poll of this type. Polls already sent keep the options they were sent with.')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(60)
                            ->unique(ignoreRecord: true),

                        TextInput::make('sort_order')
                            ->label('Order')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),

                        Textarea::make('description')
                            ->label('Shown to whoever raises a poll')
                            ->rows(2)
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TagsInput::make('options')
                            ->label('Options (in the order shown)')
                            ->live()
                            ->placeholder('Type an option and press Enter')
                            ->required()
                            ->reorderable()
                            ->rules(['array', 'min:2'])
                            ->nestedRecursiveRules(['string', 'max:80'])
                            ->validationMessages(['min' => 'Give at least two options.'])
                            ->columnSpanFull(),

                        Toggle::make('allow_comment')
                            ->label('Polls of this type may ask for a written comment')
                            ->default(true),

                        Toggle::make('is_active')
                            ->label('Available for new polls')
                            ->default(true),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Reason dropdown')
                    ->description('Optional. Reasons a voter can pick to explain their answer — tied to one answer (reasons for "Bad" differ from reasons for "Good") or offered for any answer. Whoever raises a poll of this type decides, poll by poll, whether to ask for a reason.')
                    ->schema([
                        Repeater::make('reasons')
                            ->hiddenLabel()
                            ->schema([
                                Select::make('option')
                                    ->label('For the answer')
                                    ->options(fn (Get $get): array => self::applyToOptions($get('../../options')))
                                    ->default(PollType::ANY_OPTION)
                                    ->required()
                                    ->searchable(false),

                                TextInput::make('reason')
                                    ->label('Reason')
                                    ->required()
                                    ->maxLength(80)
                                    ->columnSpan(2),
                            ])
                            ->columns(3)
                            ->reorderableWithButtons()
                            ->addActionLabel('Add a reason')
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * "Any answer" plus the type's current options (read live from the
     * form, so a newly typed option is offered straight away).
     *
     * @return array<string, string>
     */
    protected static function applyToOptions(mixed $options): array
    {
        $choices = [PollType::ANY_OPTION => 'Any answer'];

        foreach ((array) $options as $option) {
            $option = trim((string) $option);

            if ($option !== '') {
                $choices[$option] = $option;
            }
        }

        return $choices;
    }
}
