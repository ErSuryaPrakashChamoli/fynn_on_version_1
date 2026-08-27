<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Services\Journey\CustomerJourneyAccessService;
use Carbon\Carbon;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                // Only rendered when access is not the normal hierarchy path,
                // so the normal Manager workflow stays visually unchanged
                // when no delegation/takeover exists — see spec item 13.
                Section::make('Journey Access')
                    ->columnSpanFull()
                    ->visible(function (Customer $record): bool {
                        $user = auth()->user();

                        if (! $user || $user->hasRole('Admin')) {
                            return false;
                        }

                        $decision = app(CustomerJourneyAccessService::class)
                            ->decide($user, $record, JourneyModule::forCustomer($record));

                        return $decision->accessType !== JourneyAccessType::Normal;
                    })
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('assignedTo.emp_name')
                                    ->label('Owner'),

                                TextEntry::make('acting_manager_display')
                                    ->label('Acting Manager')
                                    ->state(fn (): string => auth()->user()->employee?->emp_name ?? '—'),

                                TextEntry::make('access_type_display')
                                    ->label('Access')
                                    ->badge()
                                    ->state(function (Customer $record): string {
                                        $user = auth()->user();
                                        $decision = app(CustomerJourneyAccessService::class)
                                            ->decide($user, $record, JourneyModule::forCustomer($record));

                                        return $decision->accessType->label();
                                    }),
                            ]),
                    ]),

                Section::make('👤 Customer Overview')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)
                            ->schema([

                                TextEntry::make('customer_name')
                                    ->label('Customer Name'),

                                TextEntry::make('mobile_no')
                                    ->label('Mobile'),

                                TextEntry::make('email')
                                    ->label('Email'),

                                TextEntry::make('pan_number')
                                    ->label('PAN')
                                    ->badge(),

                                TextEntry::make('assignedTo.emp_name')
                                    ->label('Assigned To'),

                                // TextEntry::make('journey_status')
                                //     ->label('Current Stage')
                                //     ->badge()
                                //     ->color(fn($state) => match ($state) {
                                //         'New Lead' => 'gray',
                                //         'Eligibility Check' => 'info',
                                //         'Underwriting' => 'warning',
                                //         'Credit Approval' => 'success',
                                //         'Disbursal' => 'primary',
                                //         'Completed' => 'success',
                                //         'Rejected' => 'danger',
                                //         default => 'gray',
                                //     }),

                                TextEntry::make('journey_status')
                                    ->label('Current Stage')
                                    ->badge()
                                    ->formatStateUsing(function ($state, $record) {

                                        if ($record->documents_submitted && $state === 'sanctioned') {
                                            return 'Completed';
                                        }

                                        return match ($state) {
                                            'sfl' => 'SFL',
                                            'underwriting' => 'Underwriting',
                                            'approved' => 'Approved',
                                            'sanctioned' => 'Disbursed',
                                            'carry_forward' => 'Carry Forward',
                                            'dropped' => 'Dropped',
                                            'not_approved' => 'Rejected',
                                            default => ucfirst(str_replace('_', ' ', $state)),
                                        };
                                    })
                                    ->color(function ($state, $record) {

                                        if ($record->documents_submitted && $state === 'sanctioned') {
                                            return 'success';
                                        }

                                        return match ($state) {
                                            'sfl' => 'gray',
                                            'underwriting' => 'warning',
                                            'approved' => 'info',
                                            'sanctioned' => 'primary',       // Disbursed
                                            'carry_forward' => 'warning',
                                            'dropped' => 'danger',
                                            'not_approved' => 'danger',
                                            default => 'gray',
                                        };
                                    }),

                                TextEntry::make('created_at')
                                    ->dateTime(),

                                TextEntry::make('updated_at')
                                    ->dateTime(),

                            ]),
                    ]),

                Section::make('📍 Personal Details')
                    ->columnSpanFull()
                    ->schema([

                        Grid::make(3)
                            ->schema([

                                TextEntry::make('current_location'),

                                TextEntry::make('residence_location'),

                                TextEntry::make('job_location'),

                                // TextEntry::make('company_category'),

                                TextEntry::make('salary')
                                    ->money('INR'),

                                TextEntry::make('eligibility_status')
                                    ->badge(),

                            ]),
                    ]),

                Section::make('🏦 Loan Application Details')
                    ->columnSpanFull()
                    ->schema([

                        Grid::make(3)
                            ->schema([

                                TextEntry::make('loan_applied'),

                                TextEntry::make('other_bank_eligible_for'),

                                TextEntry::make('bank_eligible_for'),

                                TextEntry::make('other_bank_eligible_for'),

                                TextEntry::make('application_no'),

                                TextEntry::make('lan_no'),

                                TextEntry::make('journey_status')
                                    ->badge(),

                                TextEntry::make('underwriting_status')
                                    ->badge(),

                            ]),
                    ]),

                Section::make('📑 Step 1 - Source File Logging')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)
                            ->schema([

                                TextEntry::make('eligible_loan_amount')
                                    ->money('INR'),

                                TextEntry::make('documentation_status')
                                    ->badge(),

                                TextEntry::make('pending_documents'),

                                TextEntry::make('sfl_remarks')
                                    ->columnSpan(2),

                                // TextEntry::make('sflCompletedBy.name')
                                //     ->label('Completed By'),

                                // TextEntry::make('sfl_completed_date')
                                //     ->dateTime(),

                            ]),

                    ]),

                Section::make('🔍 Step 2 - Underwriting')
                    ->columnSpanFull()
                    ->schema([

                        Grid::make(3)
                            ->schema([

                                TextEntry::make('underwriting_status')
                                    ->badge(),

                                TextEntry::make('underwriting_remarks')
                                    ->columnSpan(2),
                                TextEntry::make('approval_date')
                                    ->dateTime(),

                            ]),
                    ]),

                Section::make('✅ Step 3 - Credit Approval')
                    ->columnSpanFull()
                    ->schema([

                        Grid::make(3)
                            ->schema([

                                TextEntry::make('approved_loan_amount')
                                    ->money('INR'),

                                TextEntry::make('bank_eligible_for'),

                                TextEntry::make('approval_remarks')
                                    ->columnSpan(2),

                            ]),
                    ]),

                Section::make('💰 Step 4 - Disbursal')
                    ->columnSpanFull()
                    ->schema([

                        Grid::make(3)
                            ->schema([

                                // TextEntry::make('disbursal_status'),

                                TextEntry::make('disbursal_status')
                                    ->label('Disbursal Status')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'disbursed' => 'success',
                                        'dropped' => 'danger',
                                        'carry_forward' => 'warning',
                                        'on_hold' => 'gray',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn (?string $state) => match ($state) {
                                        'disbursed' => 'Disbursed',
                                        'dropped' => 'Dropped',
                                        'carry_forward' => 'Carry Forward',
                                        'on_hold' => 'On Hold',
                                        default => '-',
                                    }),
                                TextEntry::make('channel'),

                                TextEntry::make('sanctioned_bank'),

                                TextEntry::make('sanctioned_loan_amount')
                                    ->money('INR'),

                                TextEntry::make('cashback')
                                    ->money('INR'),

                                TextEntry::make('subvention')
                                    ->money('INR'),

                                TextEntry::make('docking')
                                    ->money('INR'),

                                TextEntry::make('payout_rate'),

                            ]),
                    ]),

                Section::make('Customer Documents')
                    ->columnSpanFull()
                    ->schema([

                        RepeatableEntry::make('documents')
                            ->label('')
                            ->contained(true)
                            ->schema([

                                TextEntry::make('document_type')
                                    ->label('Document Type')
                                    ->badge(),

                                TextEntry::make('document_name')
                                    ->label('File Name'),

                                TextEntry::make('created_at')
                                    ->label('Uploaded On')
                                    ->dateTime('d M Y, h:i A'),

                                TextEntry::make('uploader.name')
                                    ->label('Uploaded By')
                                    ->default('System'),

                                // TextEntry::make('document_path')
                                //     ->label('Document')
                                //     ->formatStateUsing(fn() => '📄 View PDF')
                                //     ->url(fn($state) => Storage::disk('public')->url($state))
                                //     ->openUrlInNewTab(),
                                TextEntry::make('document_path')
                                    ->label('Document')
                                    // ->state('📄 View PDF')
                                    ->state('📄 View File')
                                    ->url(fn ($record) => Storage::disk('public')->url($record->document_path))
                                    ->openUrlInNewTab(),

                            ])
                            ->columns(5),

                    ])
                    ->visible(fn ($record) => $record?->documents()->exists())
                    ->columnSpanFull(),

                Section::make('❌ Rejection Details')
                    ->columnSpanFull()
                    ->visible(
                        fn ($record) => $record->journey_status === 'rejected'
                    )
                    ->schema([

                        TextEntry::make('rejection_reason'),

                        TextEntry::make('rejection_remarks'),

                    ]),

                Section::make('📝 Complete Activity Timeline')
                    ->columnSpanFull()
                    ->schema([

                        RepeatableEntry::make('activities')
                            ->label('')
                            ->schema([

                                TextEntry::make('description')
                                    ->label('Activity')
                                    ->columnSpanFull(),

                                TextEntry::make('causer.name')
                                    ->label('Changed By'),

                                TextEntry::make('created_at')
                                    ->label('Date & Time')
                                    ->dateTime(),

                                TextEntry::make('changes')
                                    ->label('Field Changes')
                                    ->html()
                                    ->columnSpanFull()
                                    ->formatStateUsing(function ($state, $record) {

                                        $changes = $record->changes ?? [];

                                        $old = $changes['old'] ?? [];
                                        $new = $changes['new'] ?? [];

                                        if (empty($old) && empty($new)) {
                                            return '<span class="text-gray-500">No field changes</span>';
                                        }

                                        $html = '';

                                        foreach ($old as $field => $oldValue) {

                                            $newValue = $new[$field] ?? null;

                                            if (is_array($oldValue)) {
                                                $oldValue = json_encode($oldValue, JSON_PRETTY_PRINT);
                                            }

                                            if (is_array($newValue)) {
                                                $newValue = json_encode($newValue, JSON_PRETTY_PRINT);
                                            }

                                            // Convert null values

                                            $formatValue = function ($value) {

                                                if ($value === null || $value === '') {
                                                    return '-';
                                                }

                                                if (is_bool($value)) {
                                                    return $value ? 'Yes' : 'No';
                                                }

                                                if (is_array($value)) {
                                                    return implode(', ', $value);
                                                }

                                                // Format timestamps
                                                if (is_string($value)) {
                                                    try {
                                                        return Carbon::parse($value)
                                                            ->timezone(config('app.timezone')) // or 'Asia/Kolkata'
                                                            ->format('d M Y, h:i:s A');
                                                    } catch (\Exception $e) {
                                                        // Not a date, continue
                                                    }
                                                }

                                                return e((string) $value);
                                            };

                                            // $oldValue = $oldValue === null ? '-' : e($oldValue);
                                            // $newValue = $newValue === null ? '-' : e($newValue);

                                            $oldValue = $formatValue($oldValue);
                                            $newValue = $formatValue($newValue);

                                            // Make field names readable
                                            $label = Str::of($field)
                                                ->replace('_', ' ')
                                                ->title();

                                            $html .= "
                                            <div class='mb-4 p-3 rounded border'>
                                                <div><strong>{$label}</strong></div>
                                                <div class='text-danger'>Old: {$oldValue}</div>
                                                <div class='text-success'>New: {$newValue}</div>
                                            </div>
                                        ";
                                        }

                                        return $html;
                                    })
                                    ->html()
                                    ->columnSpanFull(),

                            ])
                            ->columns(3),

                    ]),

                Section::make('Customer Settlement')
                    ->schema([
                        TextEntry::make('latestSettlement.settlement_no')
                            ->label('Settlement No.')
                            ->placeholder('Not created'),

                        TextEntry::make('latestSettlement.status')
                            ->label('Settlement Status')
                            ->badge(),

                        TextEntry::make('latestSettlement.sales_disbursal_amount')
                            ->label('Sales Disbursal')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.mis_disbursal_amount')
                            ->label('Bank MIS Disbursal')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.variance_amount')
                            ->label('Variance')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.sales_cashback')
                            ->label('Sales Cashback')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.mis_cashback')
                            ->label('MIS Cashback')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.sales_subvention')
                            ->label('Sales Subvention')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.mis_subvention')
                            ->label('MIS Subvention')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.sales_docking')
                            ->label('Sales Docking')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.mis_docking')
                            ->label('MIS Docking')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.achievement_difference')
                            ->label('Achievement Impact')
                            ->numeric(),

                        TextEntry::make('latestSettlement.incentive_difference')
                            ->label('Incentive Impact')
                            ->money('INR'),

                        TextEntry::make('latestSettlement.impact_calculated_at')
                            ->label('Impact Calculated At')
                            ->dateTime(),
                    ])
                    ->columns(2),

            ]);
    }
}
