<?php

namespace App\Models;

use App\Support\AiRecordProspectFields;
use Database\Factories\CustomerDataFieldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A saved field offered as a tick box when customer data is uploaded from
 * Excel or CSV. Its key is the key the value is stored under in an AI
 * record's data JSON; its label and aliases are the column names it is
 * matched to automatically.
 */
class CustomerDataField extends Model
{
    /** @use HasFactory<CustomerDataFieldFactory> */
    use HasFactory;

    /**
     * Data types a field can hold — the same list the Document Schema form
     * offers, so a template built from these fields edits there unchanged.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'text' => 'Text',
        'number' => 'Number',
        'decimal' => 'Decimal',
        'date' => 'Date',
        'mobile' => 'Mobile',
        'pan' => 'PAN',
        'email' => 'Email',
        'long_text' => 'Long text',
    ];

    protected $fillable = [
        'key',
        'label',
        'type',
        'aliases',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<CustomerDataField>  $query
     * @return Builder<CustomerDataField>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    /**
     * Every name this field answers to, compacted for comparison: its key,
     * label, remembered column names and — for a canonical prospect field —
     * the synonyms AiRecordProspectFields already knows.
     *
     * @return Collection<int, string>
     */
    public function matchNames(): Collection
    {
        return collect([
            $this->key,
            $this->label,
            ...($this->aliases ?? []),
            ...(AiRecordProspectFields::FIELDS[$this->key]['synonyms'] ?? []),
        ])
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => self::compact($name))
            ->filter()
            ->unique()
            ->values();
    }

    public function matchesColumn(string $columnName): bool
    {
        $columnName = self::compact($columnName);

        return $columnName !== '' && $this->matchNames()->contains($columnName);
    }

    /**
     * Remembers a column name this field was uploaded from, so the next file
     * using that heading is matched without the Admin picking it again.
     */
    public function rememberColumn(string $columnName): void
    {
        $columnName = trim($columnName);

        if ($columnName === '' || $this->matchesColumn($columnName)) {
            return;
        }

        $this->aliases = [...($this->aliases ?? []), $columnName];
        $this->save();
    }

    /**
     * The saved field a newly typed field name stands for — one whose key or
     * names already match it, so "Contact No" reuses Mobile Number instead
     * of creating a near-duplicate — or a new saved field for it.
     */
    public static function findOrCreateForLabel(string $label, string $type, ?int $createdBy = null): self
    {
        $label = Str::limit(trim($label), 150, '');

        if ($existing = self::otherFieldNamed($label)) {
            return $existing;
        }

        return self::create([
            'key' => self::uniqueKeyForLabel($label),
            'label' => $label,
            'type' => array_key_exists($type, self::TYPES) ? $type : 'text',
            'aliases' => [],
            'sort_order' => (int) self::query()->max('sort_order') + 1,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * keyForLabel(), numbered when that key is already taken.
     */
    public static function uniqueKeyForLabel(string $label): string
    {
        $key = self::keyForLabel($label);
        $uniqueKey = $key;
        $counter = 2;

        while (self::query()->where('key', $uniqueKey)->exists()) {
            $uniqueKey = "{$key}_{$counter}";
            $counter++;
        }

        return $uniqueKey;
    }

    /**
     * Another saved field that already answers to $name, if any.
     */
    public static function otherFieldNamed(string $name, ?self $except = null): ?self
    {
        return self::query()
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->ordered()
            ->get()
            ->first(fn (self $field): bool => $field->key === self::keyForLabel($name) || $field->matchesColumn($name));
    }

    /**
     * This field as a Document Schema column definition.
     *
     * @return array{key: string, label: string, aliases: list<string>, type: string, required: bool}
     */
    public function toTemplateField(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'aliases' => array_values($this->aliases ?? []),
            'type' => $this->type,
            'required' => false,
        ];
    }

    /**
     * "Contact No.", "contact_no" and "ContactNo" all compact to "contactno".
     */
    public static function compact(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', Str::lower($value));
    }

    /**
     * A data key for a new field label: "Loan Amount" becomes "loan_amount".
     * Keys must start with a letter (Document Schema rule), so "2nd Mobile"
     * becomes "field_2nd_mobile".
     */
    public static function keyForLabel(string $label): string
    {
        $key = Str::limit(Str::snake(Str::lower(trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $label)))), 90, '');
        $key = trim((string) preg_replace('/_+/', '_', $key), '_');

        if ($key === '') {
            return 'field';
        }

        return preg_match('/^[a-z]/', $key) ? $key : "field_{$key}";
    }
}
