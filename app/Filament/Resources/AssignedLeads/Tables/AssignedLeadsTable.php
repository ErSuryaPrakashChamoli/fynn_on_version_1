<?php

namespace App\Filament\Resources\AssignedLeads\Tables;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Services\HierarchyService;
use App\Support\SelectedMonth;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AssignedLeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Prospect Name'),

                TextColumn::make('source_label')
                    ->label('Source')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Customer' ? 'success' : 'gray'),

                TextColumn::make('employee.emp_name')
                    ->label('Assigned To')
                    ->searchable()
                    ->visible(fn () => Filament::auth()->user()?->hasRole('Admin')
                        || Filament::auth()->user()?->employee?->designation !== Employee::DESIGNATION_CALLER),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Opened' ? 'success' : 'gray'),

                TextColumn::make('opens_count')
                    ->label('Opens'),

                TextColumn::make('follow_up_status')
                    ->label('Follow-Up Status')
                    ->badge()
                    ->state(fn (CustomerAssignment $record) => $record->latestFollowUpStatus() ?? 'Pending')
                    ->color(fn (string $state): string => match ($state) {
                        'Interested' => 'success',
                        'Not Interested', 'Not Eligible' => 'danger',
                        'Busy', 'No Response' => 'warning',
                        'Eligible for Other Bank' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('next_follow_up')
                    ->label('Next Follow Up')
                    ->state(fn (CustomerAssignment $record) => $record->latestFollowUp()?->next_follow_up_date)
                    ->dateTime('d M Y h:i A')
                    ->placeholder('-'),

                TextColumn::make('customer.journey_status')
                    ->label('Journey')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'sanctioned' => 'Disbursed',
                        'sfl' => 'SFL',
                        'underwriting' => 'Underwriting',
                        'approved' => 'Approved',
                        default => $state ? ucfirst(str_replace('_', ' ', $state)) : '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'sfl' => 'gray',
                        'underwriting' => 'warning',
                        'approved' => 'info',
                        'sanctioned' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Assigned On')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('last_opened_at')
                    ->label('Last Opened')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('Never')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Assigned To')
                    ->options(
                        Employee::query()
                            ->whereIn('id', HierarchyService::visibleEmployeeIds(Filament::auth()->user()))
                            ->orderBy('emp_name')
                            ->pluck('emp_name', 'id')
                    )
                    ->searchable()
                    ->preload()
                    ->visible(fn () => Filament::auth()->user()?->hasRole('Admin')
                        || Filament::auth()->user()?->employee?->designation !== Employee::DESIGNATION_CALLER),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('convertToCustomer')
                    ->label('Convert')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CustomerAssignment $record) => $record->isEligibleForConversion())
                    ->url(fn (CustomerAssignment $record) => CustomerResource::getUrl('create', [
                        'ai_customer_record' => $record->ai_customer_record_id,
                    ])),

                ViewAction::make(),
            ])
            ->modifyQueryUsing(
                fn (Builder $query) => $query->whereBetween('created_at', SelectedMonth::range())
            );
    }
}
