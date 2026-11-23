<?php

namespace App\Filament\Resources\CustomerDataFields\Schemas;

use App\Models\CustomerDataField;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustomerDataFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->label('Field name')
                    ->placeholder('e.g. Loan Amount')
                    ->required()
                    ->maxLength(150)
                    ->rule(fn (?CustomerDataField $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $clash = CustomerDataField::otherFieldNamed((string) $value, $record);

                        if ($clash) {
                            $fail("\"{$clash->label}\" already covers this name.");
                        }
                    }),

                Select::make('type')
                    ->label('Type')
                    ->options(CustomerDataField::TYPES)
                    ->default('text')
                    ->selectablePlaceholder(false)
                    ->searchable(false)
                    ->required(),

                TagsInput::make('aliases')
                    ->label('Also known as')
                    ->placeholder('Add a column name')
                    ->helperText('Column names that mean this field, e.g. "Contact No" for Mobile Number. Uploads tick the field automatically when a file uses one of these.')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }
}
