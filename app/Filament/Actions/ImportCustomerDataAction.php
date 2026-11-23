<?php

namespace App\Filament\Actions;

use App\Models\AiDocumentSchema;
use App\Models\CustomerDataField;
use App\Models\Employee;
use App\Services\CustomerDataImportService;
use App\Support\EmployeeOptions;
use App\Support\SpreadsheetFile;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * "Import Data" (Admin): upload customer data from any Excel or CSV in three
 * steps — pick the file, tick the fields to upload (the ones matching the
 * file's column names are ticked already, any other column can be ticked to
 * save it as a new field), and optionally hand every row to one employee.
 * No template has to be built beforehand; see CustomerDataImportService.
 */
class ImportCustomerDataAction
{
    public static function make(): Action
    {
        return Action::make('importCustomerData')
            ->label('Import Data')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->hasRole('Admin') ?? false)
            ->modalHeading('Import data from Excel / CSV')
            ->modalDescription('Upload your file, tick the fields you want and import. Fields you add are saved and offered again next time.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Import')
            ->steps([
                self::uploadStep(),
                self::chooseFieldsStep(),
                self::importStep(),
            ])
            ->action(function (array $data, Action $action): void {
                self::import($data, $action);
            });
    }

    private static function uploadStep(): Step
    {
        return Step::make('Upload file')
            ->icon(Heroicon::OutlinedDocumentArrowUp)
            ->schema([
                FileUpload::make('file')
                    ->label('Excel or CSV file')
                    ->helperText('.xlsx or .csv, with the column names in the first row. Up to '.number_format(CustomerDataImportService::MAX_ROWS).' rows per file.')
                    ->acceptedFileTypes(SpreadsheetFile::MIME_TYPES)
                    ->maxSize(20480)
                    ->storeFiles(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::readFile($state, $get, $set)),

                TextInput::make('template_name')
                    ->label('Template name')
                    ->helperText('Shown in the Template column. Type an existing template name to add these rows to it.')
                    ->datalist(fn (): array => AiDocumentSchema::query()->latest('id')->limit(50)->pluck('name')->all())
                    ->required()
                    ->maxLength(255),

                Hidden::make('columns'),
            ])
            ->afterValidation(function (Get $get): void {
                if (self::columnOptions($get('columns')) === []) {
                    Notification::make()
                        ->title('No columns found')
                        ->body('The first row of the file must hold the column names.')
                        ->danger()
                        ->send();

                    throw new Halt;
                }
            });
    }

    private static function chooseFieldsStep(): Step
    {
        return Step::make('Choose fields')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->schema([
                Text::make('Ticked fields are uploaded. Fields matching your file\'s column names are ticked for you. Tick any other column to save it as a new field — it will be offered next time.')
                    ->color('gray'),

                Repeater::make('fields')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Upload')->width('80px')->alignment(Alignment::Center),
                        TableColumn::make('Field'),
                        TableColumn::make('Column in your file'),
                        TableColumn::make('Type')->width('160px'),
                    ])
                    ->schema([
                        Hidden::make('key'),
                        Hidden::make('is_new')->default(true),
                        Checkbox::make('include')
                            ->hiddenLabel()
                            ->default(true),
                        TextInput::make('label')
                            ->hiddenLabel()
                            ->placeholder('New field name')
                            ->readOnly(fn (Get $get): bool => ! self::isNewRow($get))
                            ->required(fn (Get $get): bool => (bool) $get('include'))
                            ->maxLength(150),
                        Select::make('column')
                            ->hiddenLabel()
                            ->placeholder('Choose column')
                            ->options(fn (Get $get): array => self::columnOptions($get('../../columns')))
                            ->required(fn (Get $get): bool => (bool) $get('include')),
                        Select::make('type')
                            ->hiddenLabel()
                            ->options(CustomerDataField::TYPES)
                            ->default('text')
                            ->selectablePlaceholder(false)
                            ->disabled(fn (Get $get): bool => ! self::isNewRow($get))
                            ->dehydrated(),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel('Add new field')
                    ->reorderable(false)
                    ->deleteAction(fn (Action $action): Action => $action->visible(
                        fn (array $arguments, Repeater $component): bool => (bool) ($component->getRawItemState($arguments['item'])['is_new'] ?? false),
                    )),
            ])
            ->afterValidation(function (Get $get): void {
                if (! collect($get('fields') ?? [])->contains(fn (mixed $row): bool => is_array($row) && ($row['include'] ?? false))) {
                    Notification::make()
                        ->title('Tick at least one field to upload')
                        ->danger()
                        ->send();

                    throw new Halt;
                }
            });
    }

    private static function importStep(): Step
    {
        return Step::make('Import')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->schema([
                Text::make(fn (Get $get): string => self::summary($get)),

                Select::make('employee_id')
                    ->label('Assign all rows to (optional)')
                    ->helperText('Leave empty to assign later from Customer Data.')
                    ->placeholder('Do not assign now')
                    ->options(fn (): array => EmployeeOptions::forDesignation(Employee::DESIGNATION_CALLER)
                        + EmployeeOptions::forDesignation(Employee::DESIGNATION_TEAM_LEADER)),
            ]);
    }

    /**
     * Reads the uploaded file's column names, ticks the saved fields that
     * match them and names the template after the file (unless named).
     */
    private static function readFile(mixed $state, Get $get, Set $set): void
    {
        $file = self::uploadedFile($state);

        if (! $file) {
            $set('columns', null);
            $set('fields', []);

            return;
        }

        try {
            $columns = SpreadsheetFile::fromUpload($file)->columns();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('This file could not be read')
                ->body($exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Save it as .xlsx or .csv and upload it again.')
                ->danger()
                ->send();

            $columns = [];
        }

        $set('columns', json_encode(CustomerDataImportService::columnOptions($columns)));
        $set('fields', collect(app(CustomerDataImportService::class)->suggestFields($columns))
            ->mapWithKeys(fn (array $row): array => [(string) Str::uuid() => $row])
            ->all());

        if (blank($get('template_name'))) {
            $set('template_name', Str::limit(trim(Str::beforeLast($file->getClientOriginalName(), '.')), 255, ''));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function import(array $data, Action $action): void
    {
        $file = self::uploadedFile($data['file'] ?? null);

        if (! $file) {
            Notification::make()->title('Upload a file first')->danger()->send();

            $action->halt();
        }

        try {
            $result = app(CustomerDataImportService::class)->import(
                SpreadsheetFile::fromUpload($file),
                (string) ($data['template_name'] ?? ''),
                $data['fields'] ?? [],
                filled($data['employee_id'] ?? null) ? (int) $data['employee_id'] : null,
                auth()->user(),
            );
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Nothing was imported')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $action->halt();

            return;
        }

        $body = number_format($result['imported']).' '.Str::plural('row', $result['imported']).' added to "'.$result['template']->name.'".';

        if ($result['assigned'] > 0) {
            $body .= ' '.number_format($result['assigned']).' assigned.';
        }

        if ($result['skipped'] > 0) {
            $body .= ' '.number_format($result['skipped']).' empty '.Str::plural('row', $result['skipped']).' skipped.';
        }

        Notification::make()
            ->title('Data imported')
            ->body($body)
            ->success()
            ->send();
    }

    private static function summary(Get $get): string
    {
        $columns = self::columnOptions($get('columns'));
        $chosen = collect($get('fields') ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && ($row['include'] ?? false))
            ->map(fn (array $row): string => trim((string) ($row['label'] ?? '')).' ← '.($columns[$row['column'] ?? ''] ?? '?'));

        return 'Template "'.trim((string) $get('template_name')).'" — '.$chosen->count().' '.Str::plural('field', $chosen->count()).': '.$chosen->implode(', ').'.';
    }

    /**
     * @return array<string, string>
     */
    private static function columnOptions(mixed $encodedColumns): array
    {
        $columns = is_string($encodedColumns) ? json_decode($encodedColumns, true) : null;

        return is_array($columns) ? $columns : [];
    }

    private static function isNewRow(Get $get): bool
    {
        return filter_var($get('is_new'), FILTER_VALIDATE_BOOLEAN) || blank($get('key'));
    }

    private static function uploadedFile(mixed $state): ?TemporaryUploadedFile
    {
        $file = is_array($state) ? Arr::first($state) : $state;

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }
}
