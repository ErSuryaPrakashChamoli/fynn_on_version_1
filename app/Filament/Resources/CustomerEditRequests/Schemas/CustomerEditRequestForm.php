<?php

namespace App\Filament\Resources\CustomerEditRequests\Schemas;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Support\CustomerEditableFields as Fields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Pick the file, then the journey section, then one row per field to
 * change: the field (only that section's fields), its current value, and
 * the value wanted — as a dropdown, date, amount or text to match the
 * field. The page maps the typed inputs back to one value per row.
 */
class CustomerEditRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Which file and section?')
                    ->description('The Admin reviews every request. Once approved, the new values are written onto the customer file automatically and the change is logged.')
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->required()
                            ->live()
                            ->getSearchResultsUsing(fn (string $search): array => self::customerSearch($search))
                            ->getOptionLabelUsing(fn ($value): ?string => self::customerLabel($value))
                            ->helperText('Search by name, mobile, PAN or application number.')
                            ->afterStateUpdated(fn (Set $set): mixed => $set('changes', [])),

                        Select::make('section')
                            ->label('Section')
                            ->options(Fields::sectionOptions())
                            ->required()
                            ->live()
                            ->searchable(false)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('changes', [['field' => null]])),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Fields to change')
                    ->visible(fn (Get $get): bool => filled($get('customer_id')) && filled($get('section')))
                    ->schema([
                        Repeater::make('changes')
                            ->hiddenLabel()
                            ->schema([
                                Select::make('field')
                                    ->label('Field')
                                    ->options(fn (Get $get): array => Fields::fieldOptions($get('../../section')))
                                    ->required()
                                    ->live()
                                    ->searchable(false)
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->afterStateUpdated(function (Set $set): void {
                                        foreach (['value_text', 'value_textarea', 'value_number', 'value_date', 'value_select'] as $input) {
                                            $set($input, null);
                                        }
                                    }),

                                Placeholder::make('current')
                                    ->label('Current value')
                                    ->content(fn (Get $get): string => self::currentDisplay($get('../../customer_id'), $get('../../section'), $get('field'))),

                                TextInput::make('value_text')
                                    ->label('Requested value')
                                    ->maxLength(255)
                                    ->visible(fn (Get $get): bool => self::typeIs($get, Fields::TYPE_TEXT)),

                                Textarea::make('value_textarea')
                                    ->label('Requested value')
                                    ->rows(3)
                                    ->maxLength(2000)
                                    ->visible(fn (Get $get): bool => self::typeIs($get, Fields::TYPE_TEXTAREA)),

                                // Indian grouping (12,50,000) in the box, read back in words
                                // underneath; saved as plain digits (TextInput::indianAmount()).
                                TextInput::make('value_number')
                                    ->label('Requested value')
                                    ->visible(fn (Get $get): bool => self::typeIs($get, Fields::TYPE_NUMBER))
                                    ->indianAmount(min: 0),

                                DatePicker::make('value_date')
                                    ->label('Requested value')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->visible(fn (Get $get): bool => self::typeIs($get, Fields::TYPE_DATE)),

                                Select::make('value_select')
                                    ->label('Requested value')
                                    ->options(fn (Get $get): array => filled($get('field')) ? Fields::choices((string) $get('field')) : [])
                                    ->visible(fn (Get $get): bool => self::typeIs($get, Fields::TYPE_SELECT)),
                            ])
                            ->columns(3)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Add another field of this section')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Why?')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason for the change')
                            ->required()
                            ->rows(3)
                            ->minLength(5)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The value a row asks for, from whichever typed input the field uses.
     *
     * @param  array<string, mixed>  $row
     */
    public static function requestedValue(?string $section, array $row): ?string
    {
        $input = match (Fields::type($section, $row['field'] ?? null)) {
            Fields::TYPE_TEXTAREA => 'value_textarea',
            Fields::TYPE_NUMBER => 'value_number',
            Fields::TYPE_DATE => 'value_date',
            Fields::TYPE_SELECT => 'value_select',
            default => 'value_text',
        };

        $value = $row[$input] ?? null;

        return $value === null ? null : (string) $value;
    }

    protected static function typeIs(Get $get, string $type): bool
    {
        return filled($get('field')) && Fields::type($get('../../section'), $get('field')) === $type;
    }

    protected static function currentDisplay(mixed $customerId, mixed $section, mixed $field): string
    {
        if (blank($customerId) || blank($section) || blank($field)) {
            return '—';
        }

        $customer = CustomerResource::getEloquentQuery()->find((int) $customerId);

        return $customer
            ? Fields::displayWithWords((string) $section, (string) $field, Fields::currentValue($customer, (string) $field))
            : '—';
    }

    /**
     * Files the user may see (the customer listing's own scope).
     *
     * @return array<int, string>
     */
    protected static function customerSearch(string $search): array
    {
        return CustomerResource::getEloquentQuery()
            ->where(fn ($query) => $query
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('mobile_no', 'like', "%{$search}%")
                ->orWhere('pan_number', 'like', "%{$search}%")
                ->orWhere('application_no', 'like', "%{$search}%"))
            ->orderBy('customer_name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => self::labelFor($customer)])
            ->all();
    }

    protected static function customerLabel(mixed $value): ?string
    {
        $customer = filled($value) ? CustomerResource::getEloquentQuery()->find((int) $value) : null;

        return $customer ? self::labelFor($customer) : null;
    }

    protected static function labelFor(Customer $customer): string
    {
        return trim($customer->customer_name.' · '.($customer->application_no ?: $customer->mobile_no));
    }
}
