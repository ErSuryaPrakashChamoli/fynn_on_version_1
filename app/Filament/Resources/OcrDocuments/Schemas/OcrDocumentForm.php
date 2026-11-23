<?php

namespace App\Filament\Resources\OcrDocuments\Schemas;

use App\Models\CustomerDataField;
use App\Models\OcrDocument;
use App\Services\CustomerDataImportService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Uploading an AI Document needs no Data Template: the Admin ticks the
 * columns the document has from the saved fields (the last document's
 * columns come ticked) and drags them into the document's left-to-right
 * order. CreateOcrDocument builds the template from that list.
 */
class OcrDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Document')
                ->icon(Heroicon::OutlinedPaperClip)
                ->description('A PDF or photo of the lead list.')
                ->schema([
                    FileUpload::make('original_path')
                        ->label('PDF / Image')
                        ->disk('local')
                        ->directory('ocr-documents')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'image/tiff',
                        ])
                        ->maxSize(512000)
                        ->storeFileNamesIn('original_name')
                        ->required()
                        ->downloadable()
                        ->openable()
                        ->live()
                        ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::nameAfterFile($state, $get, $set))
                        ->columnSpanFull(),

                    TextInput::make('title')
                        ->label('Name for this data')
                        ->placeholder('e.g. CIBIL Data 23 Nov – Lot 1')
                        ->helperText('Shown in the Template column on Assigned Leads. Filled from the file name.')
                        ->maxLength(240),

                    Select::make('document_type')
                        ->label('Document Type')
                        ->options([
                            'customer_form' => 'Customer Form',
                            'kyc' => 'KYC',
                            'pan' => 'PAN',
                            'aadhaar' => 'Aadhaar',
                            'salary_slip' => 'Salary Slip',
                            'bank_statement' => 'Bank Statement',
                            'sanction_letter' => 'Sanction Letter',
                            'other' => 'Other',
                        ]),

                    Select::make('customer_id')
                        ->label('Customer (optional)')
                        ->helperText('Only when the document belongs to one existing customer.')
                        ->relationship('customer', 'customer_name')
                        ->preload(),
                ])
                ->columns(2),

            Section::make('Columns in this document')
                ->icon(Heroicon::OutlinedViewColumns)
                ->description('Tick the columns this document has and drag them into the same left-to-right order. Your last selection is ticked for you.')
                ->visibleOn('create')
                ->schema([
                    self::columnsRepeater(),

                    Text::make(fn (Get $get): string => self::readingOrder($get('columns')))
                        ->color('primary'),
                ]),

            Section::make('Columns in this document')
                ->icon(Heroicon::OutlinedViewColumns)
                ->hiddenOn('create')
                ->schema([
                    Text::make(fn (?OcrDocument $record): string => self::readingOrder(
                        collect($record?->schema?->getFieldDefinitions() ?? [])
                            ->map(fn (array $field): array => ['include' => true, 'label' => $field['label'] ?? $field['key']])
                            ->all(),
                    )),
                    Text::make('To read the document with different columns, upload it again.')
                        ->color('gray'),
                ]),
        ]);
    }

    private static function columnsRepeater(): Repeater
    {
        return Repeater::make('columns')
            ->hiddenLabel()
            ->table([
                TableColumn::make('Use')->width('70px')->alignment(Alignment::Center),
                TableColumn::make('Field'),
                TableColumn::make('Type')->width('170px'),
            ])
            ->schema([
                Hidden::make('key'),
                Hidden::make('is_new')->default(true),
                Checkbox::make('include')
                    ->hiddenLabel()
                    ->default(true)
                    ->live(),
                TextInput::make('label')
                    ->hiddenLabel()
                    ->placeholder('New field name')
                    ->readOnly(fn (Get $get): bool => ! self::isNewRow($get))
                    ->required(fn (Get $get): bool => (bool) $get('include'))
                    ->maxLength(150)
                    ->live(onBlur: true),
                Select::make('type')
                    ->hiddenLabel()
                    ->options(CustomerDataField::TYPES)
                    ->default('text')
                    ->selectablePlaceholder(false)
                    ->searchable(false)
                    ->disabled(fn (Get $get): bool => ! self::isNewRow($get))
                    ->dehydrated(),
            ])
            ->default(fn (): array => collect(app(CustomerDataImportService::class)->documentFieldRows())
                ->mapWithKeys(fn (array $row): array => [(string) Str::uuid() => $row])
                ->all())
            ->reorderableWithDragAndDrop()
            ->addActionLabel('Add new field')
            ->deleteAction(fn (Action $action): Action => $action->visible(
                fn (array $arguments, Repeater $component): bool => (bool) ($component->getRawItemState($arguments['item'])['is_new'] ?? false),
            ))
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (! collect(is_array($value) ? $value : [])->contains(fn (mixed $row): bool => is_array($row) && ($row['include'] ?? false))) {
                        $fail('Tick at least one column.');
                    }
                },
            ]);
    }

    /**
     * "Reads the document as: 1. Customer Name → 2. Mobile Number".
     *
     * @param  mixed  $rows  Picker rows; only ticked ones count, in order.
     */
    private static function readingOrder(mixed $rows): string
    {
        $labels = collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row) && ($row['include'] ?? false))
            ->map(fn (array $row): string => trim((string) ($row['label'] ?? '')) ?: 'New field')
            ->values();

        if ($labels->isEmpty()) {
            return 'No columns ticked yet.';
        }

        return 'Reads the document as: '.$labels->map(fn (string $label, int $index): string => ($index + 1).'. '.$label)->implode('  →  ');
    }

    /**
     * Names the data after the uploaded file unless a name was typed.
     */
    private static function nameAfterFile(mixed $state, Get $get, Set $set): void
    {
        $file = is_array($state) ? Arr::first($state) : $state;

        if ($file instanceof TemporaryUploadedFile && blank($get('title'))) {
            $set('title', Str::limit(trim(Str::beforeLast($file->getClientOriginalName(), '.')), 240, ''));
        }
    }

    private static function isNewRow(Get $get): bool
    {
        return filter_var($get('is_new'), FILTER_VALIDATE_BOOLEAN) || blank($get('key'));
    }
}
