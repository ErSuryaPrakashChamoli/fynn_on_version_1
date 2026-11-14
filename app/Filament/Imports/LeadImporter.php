<?php

namespace App\Filament\Imports;

use App\Models\Lead;
use App\Rules\PanIsAvailable;
use App\Services\PanDuplicateService;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\Auth;

class LeadImporter extends Importer
{
    protected static ?string $model = Lead::class;

    public static function getColumns(): array
    {
        return [

            ImportColumn::make('customer_name')
                ->requiredMapping()
                ->rules(['required']),

            ImportColumn::make('mobile_no')
                ->requiredMapping()
                ->rules([
                    'required',
                    'digits:10',
                ]),

            // Rows whose PAN another customer file or open lead already
            // carries are rejected — an import cannot ask for Admin approval.
            ImportColumn::make('pan_number')
                ->castStateUsing(fn (?string $state): ?string => PanDuplicateService::normalize($state))
                ->rules([
                    'nullable',
                    'size:10',
                    new PanIsAvailable(mentionApproval: false),
                ]),

            ImportColumn::make('current_location'),

            ImportColumn::make('job_location'),

            ImportColumn::make('salary')
                ->numeric(),

            // Retained only so existing lead CSV templates keep importing —
            // nothing reads follow_up_date any more; a follow-up is dated by
            // its creation time.
            ImportColumn::make('follow_up_date'),

            ImportColumn::make('follow_up_type')
                ->requiredMapping(),

            ImportColumn::make('status'),

            ImportColumn::make('next_follow_up_date'),

            ImportColumn::make('remarks'),
        ];
    }

    public function resolveRecord(): Lead
    {
        return new Lead;
        //    return Lead::firstOrNew([
        //     'mobile_no' => $this->data['mobile_no'],
        //     ]);
    }

    public function beforeSave(): void
    {
        $this->record->employee_id = Auth::user()->employee?->id;
        if (blank($this->record->status)) {
            $this->record->status = 'Pending';
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return "{$import->successful_rows} leads imported successfully.";
    }
}
