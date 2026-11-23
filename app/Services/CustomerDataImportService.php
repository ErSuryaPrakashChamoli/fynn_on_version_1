<?php

namespace App\Services;

use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\CustomerDataField;
use App\Models\User;
use App\Support\SpreadsheetFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Import Data": upload customer data from any Excel or CSV without building
 * a template first. The Admin ticks which saved fields to upload (the ones
 * the file's column names match are ticked already), can add a new field on
 * the spot, and the template is created from the ticked fields.
 *
 * New fields and newly seen column names are saved in customer_data_fields,
 * so the next upload offers and ticks them automatically.
 */
class CustomerDataImportService
{
    /** Larger files are rejected — split them so one upload stays quick. */
    public const MAX_ROWS = 5000;

    /**
     * A file's column list as the form keeps it: "c0" => "Name", ...
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    public static function columnOptions(array $columns): array
    {
        $options = [];

        foreach (array_values($columns) as $index => $name) {
            $options["c{$index}"] = $name;
        }

        return $options;
    }

    /**
     * The tick list for a file: saved fields matched to a column (ticked),
     * then the file's other columns offered as new fields (unticked), then
     * the remaining saved fields (unticked, no column picked).
     *
     * @param  list<string>  $columns
     * @return list<array{key: ?string, is_new: bool, include: bool, label: string, column: ?string, type: string}>
     */
    public function suggestFields(array $columns): array
    {
        $savedFields = CustomerDataField::query()->ordered()->get();
        $columnOptions = self::columnOptions($columns);

        $matchedFieldIds = [];
        $matchedRows = [];
        $newRows = [];

        foreach ($columnOptions as $columnKey => $columnName) {
            $field = $savedFields->first(
                fn (CustomerDataField $field): bool => ! in_array($field->id, $matchedFieldIds, true) && $field->matchesColumn($columnName),
            );

            if ($field) {
                $matchedFieldIds[] = $field->id;
                $matchedRows[] = self::savedFieldRow($field, include: true, column: $columnKey);

                continue;
            }

            $newRows[] = [
                'key' => null,
                'is_new' => true,
                'include' => false,
                'label' => $columnName,
                'column' => $columnKey,
                'type' => self::guessType($columnName),
            ];
        }

        $otherSavedRows = $savedFields
            ->reject(fn (CustomerDataField $field): bool => in_array($field->id, $matchedFieldIds, true))
            ->map(fn (CustomerDataField $field): array => self::savedFieldRow($field, include: false, column: null))
            ->values()
            ->all();

        return [...$matchedRows, ...$newRows, ...$otherSavedRows];
    }

    /**
     * Imports the file's rows as AI customer records under the named
     * template (created, or extended with any field it lacks), saves new
     * fields and column names for next time, and optionally hands every
     * imported row to one employee.
     *
     * @param  array<array-key, array{key?: ?string, is_new?: mixed, include?: mixed, label?: ?string, column?: ?string, type?: ?string}>  $fieldRows
     * @return array{imported: int, skipped: int, assigned: int, template: AiDocumentSchema}
     *
     * @throws InvalidArgumentException When nothing is ticked, a ticked row is incomplete, a field is ticked twice, or the file is empty or too large.
     */
    public function import(SpreadsheetFile $file, string $templateName, array $fieldRows, ?int $assignToEmployeeId = null, ?User $uploadedBy = null): array
    {
        $templateName = trim($templateName);

        if ($templateName === '') {
            throw new InvalidArgumentException('Give the template a name.');
        }

        $columnOptions = self::columnOptions($file->columns());
        $chosenRows = $this->chosenRows($fieldRows, $columnOptions);

        return DB::transaction(function () use ($file, $templateName, $chosenRows, $columnOptions, $assignToEmployeeId, $uploadedBy): array {
            // Fields first: a new field may turn out to be a saved one, whose
            // type then decides how its values are cleaned.
            $fields = $this->saveFields($chosenRows, $columnOptions, $uploadedBy);

            [$rowsToImport, $skipped] = $this->readRows($file, $chosenRows, $fields, $columnOptions);

            $template = $this->templateWith($templateName, $fields, $uploadedBy);

            $recordIds = collect($rowsToImport)
                ->map(function (array $values) use ($fields, $template): int {
                    $data = [];

                    foreach ($values as $rowIndex => $value) {
                        $data[$fields[$rowIndex]->key] = $value;
                    }

                    return AiCustomerRecord::create([
                        'schema_id' => $template->id,
                        'data' => $data,
                        'status' => 'review',
                    ])->id;
                });

            $assigned = 0;

            if ($assignToEmployeeId && $recordIds->isNotEmpty()) {
                $assigned = app(CustomerAssignmentService::class)->assign(
                    $recordIds,
                    $assignToEmployeeId,
                    CustomerAssignmentService::TARGET_AI_RECORD,
                    $uploadedBy?->employee?->id,
                )['assigned'];
            }

            return [
                'imported' => $recordIds->count(),
                'skipped' => $skipped,
                'assigned' => $assigned,
                'template' => $template,
            ];
        });
    }

    /**
     * The ticked rows, checked: each has a name and a column from the file,
     * and no field or new-field name is ticked twice.
     *
     * @param  array<array-key, array<string, mixed>>  $fieldRows
     * @param  array<string, string>  $columnOptions
     * @return list<array{key: ?string, is_new: bool, label: string, column: string, type: string}>
     */
    private function chosenRows(array $fieldRows, array $columnOptions): array
    {
        $chosen = [];
        $seenNames = [];

        foreach ($fieldRows as $row) {
            if (! filter_var($row['include'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $savedKey = filled($row['key'] ?? null) ? (string) $row['key'] : null;
            $isNew = $savedKey === null;
            $label = trim((string) ($row['label'] ?? ''));
            $column = (string) ($row['column'] ?? '');

            if ($label === '') {
                throw new InvalidArgumentException('Give every ticked new field a name.');
            }

            if (! array_key_exists($column, $columnOptions)) {
                throw new InvalidArgumentException("Pick the column in your file for \"{$label}\".");
            }

            $name = $isNew ? CustomerDataField::keyForLabel($label) : $savedKey;

            if (isset($seenNames[$name])) {
                throw new InvalidArgumentException("\"{$label}\" is ticked twice. Untick one of them.");
            }

            $seenNames[$name] = true;

            $chosen[] = [
                'key' => $savedKey,
                'is_new' => $isNew,
                'label' => $label,
                'column' => $column,
                'type' => array_key_exists((string) ($row['type'] ?? ''), CustomerDataField::TYPES) ? (string) $row['type'] : 'text',
            ];
        }

        if ($chosen === []) {
            throw new InvalidArgumentException('Tick at least one field to upload.');
        }

        return $chosen;
    }

    /**
     * The file's non-empty rows as [chosen row index => cleaned value],
     * plus how many rows had nothing in any ticked column.
     *
     * @param  list<array{key: ?string, is_new: bool, label: string, column: string, type: string}>  $chosenRows
     * @param  list<CustomerDataField>  $fields  The saved field behind each chosen row.
     * @param  array<string, string>  $columnOptions
     * @return array{0: list<array<int, string>>, 1: int}
     */
    private function readRows(SpreadsheetFile $file, array $chosenRows, array $fields, array $columnOptions): array
    {
        $columnPositions = array_flip(array_keys($columnOptions));
        $rows = [];
        $skipped = 0;

        foreach ($file->dataRows() as $cells) {
            $values = [];

            foreach ($chosenRows as $index => $chosen) {
                $value = self::cleanValue($cells[$columnPositions[$chosen['column']]] ?? null, $fields[$index]->type);

                if ($value !== null) {
                    $values[$index] = $value;
                }
            }

            if ($values === []) {
                $skipped++;

                continue;
            }

            $rows[] = $values;

            if (count($rows) > self::MAX_ROWS) {
                throw new InvalidArgumentException('The file has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files and upload each one.');
            }
        }

        if ($rows === []) {
            throw new InvalidArgumentException('No rows with data were found under the ticked columns.');
        }

        return [$rows, $skipped];
    }

    /**
     * The saved field behind each chosen row, in chosen-row order. A new
     * field is saved for next time — unless a saved field already answers
     * to its name, which is then used instead of a near-duplicate. Every
     * field remembers the column name it was uploaded from.
     *
     * @param  list<array{key: ?string, is_new: bool, label: string, column: string, type: string}>  $chosenRows
     * @param  array<string, string>  $columnOptions
     * @return list<CustomerDataField>
     */
    private function saveFields(array $chosenRows, array $columnOptions, ?User $uploadedBy): array
    {
        $savedFields = CustomerDataField::query()->ordered()->get();
        $nextSortOrder = (int) $savedFields->max('sort_order') + 1;
        $fields = [];

        foreach ($chosenRows as $chosen) {
            $field = $chosen['is_new']
                ? $savedFields->first(fn (CustomerDataField $field): bool => $field->key === CustomerDataField::keyForLabel($chosen['label']) || $field->matchesColumn($chosen['label']))
                : $savedFields->firstWhere('key', $chosen['key']);

            if (! $field) {
                $field = CustomerDataField::create([
                    'key' => $this->unusedKey(CustomerDataField::keyForLabel($chosen['label'])),
                    'label' => Str::limit($chosen['label'], 150, ''),
                    'type' => $chosen['type'],
                    'aliases' => [],
                    'sort_order' => $nextSortOrder++,
                    'created_by' => $uploadedBy?->id,
                ]);

                $savedFields->push($field);
            }

            if (in_array($field->key, array_map(fn (CustomerDataField $chosenField): string => $chosenField->key, $fields), true)) {
                throw new InvalidArgumentException("\"{$field->label}\" is ticked twice. Untick one of them.");
            }

            $field->rememberColumn($columnOptions[$chosen['column']]);
            $fields[] = $field;
        }

        return $fields;
    }

    private function unusedKey(string $key): string
    {
        $candidate = $key;
        $counter = 2;

        while (CustomerDataField::query()->where('key', $candidate)->exists()) {
            $candidate = "{$key}_{$counter}";
            $counter++;
        }

        return $candidate;
    }

    /**
     * The active template with this name (any case) with every chosen field
     * added to it, or a new template holding exactly the chosen fields.
     *
     * @param  list<CustomerDataField>  $fields
     */
    private function templateWith(string $templateName, array $fields, ?User $uploadedBy): AiDocumentSchema
    {
        $template = AiDocumentSchema::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($templateName)])
            ->first();

        $definitions = collect($template?->getFieldDefinitions() ?? []);
        $existingKeys = $definitions->pluck('key')->map(fn (mixed $key): string => (string) $key)->all();

        foreach ($fields as $field) {
            if (in_array($field->key, $existingKeys, true)) {
                continue;
            }

            $definitions->push([
                'key' => $field->key,
                'label' => $field->label,
                'aliases' => $field->aliases ?? [],
                'type' => $field->type,
                'required' => false,
            ]);
        }

        if ($template) {
            $template->update(['fields' => $definitions->values()->all(), 'is_active' => true]);

            return $template;
        }

        return AiDocumentSchema::create([
            'name' => $templateName,
            'description' => 'Created by Import Data',
            'fields' => $definitions->values()->all(),
            'is_active' => true,
            'created_by' => $uploadedBy?->id,
        ]);
    }

    /**
     * @return array{key: string, is_new: bool, include: bool, label: string, column: ?string, type: string}
     */
    private static function savedFieldRow(CustomerDataField $field, bool $include, ?string $column): array
    {
        return [
            'key' => $field->key,
            'is_new' => false,
            'include' => $include,
            'label' => $field->label,
            'column' => $column,
            'type' => $field->type,
        ];
    }

    /**
     * A sensible type for a new field from its column name.
     */
    private static function guessType(string $columnName): string
    {
        $name = Str::lower($columnName);

        return match (true) {
            Str::contains($name, ['mobile', 'phone', 'contact']) => 'mobile',
            Str::contains($name, ['pan']) => 'pan',
            Str::contains($name, ['email', 'e-mail', 'mail id']) => 'email',
            Str::contains($name, ['date', 'dob', 'd.o.b']) => 'date',
            Str::contains($name, ['amount', 'salary', 'income', 'score', 'cibil', 'emi', 'limit']) => 'number',
            Str::contains($name, ['remark', 'comment', 'note', 'address']) => 'long_text',
            default => 'text',
        };
    }

    /**
     * Mobile numbers keep their last 10 digits and PANs are upper-cased
     * without spaces, the way AiRecordProspectFields reads them back;
     * everything else is stored as typed.
     */
    private static function cleanValue(?string $value, string $type): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        $value = match ($type) {
            'mobile' => self::cleanMobile($value),
            'pan' => Str::upper(str_replace(' ', '', $value)),
            default => $value,
        };

        return $value === '' ? null : $value;
    }

    /**
     * "+91 98765 43210" becomes "9876543210". A cell with no digits at all
     * ("NA") is kept as typed rather than silently emptied.
     */
    private static function cleanMobile(string $value): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return $value;
        }

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }
}
