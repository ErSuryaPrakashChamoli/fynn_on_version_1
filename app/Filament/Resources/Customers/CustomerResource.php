<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use App\Models\Employee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;


use Filament\Tables;
use App\Filament\Resources\FollowUps\FollowUpResource;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use App\Filament\Imports\CustomerImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Filament\Exports\CustomerExporter;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use App\Support\HierarchyHelper;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Filters\SelectFilter;


class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;
    protected static ?string $recordTitleAttribute = 'customer_name';
    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')

            ->columns([
                Tables\Columns\TextColumn::make('application_no')
                    ->label('Application No')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lan_no')
                    ->label('LAN No')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Customer Name')
                    // ->searchable()
                    ->searchable(isIndividual: true , isGlobal: false)
                    ->sortable(),

                Tables\Columns\TextColumn::make('sanctioned_loan_amount')
                    ->label('Loan Amount')
                    ->formatStateUsing(fn($state) => filled($state) ? '₹' . number_format((float) $state, 0) : '-')
                    ->alignRight()
                    ->sortable(),

                Tables\Columns\TextColumn::make('mobile_no')
                    ->label('Mobile No')
                    ->formatStateUsing(function (?string $state): string {
                        if (blank($state) || strlen($state) < 4) {
                            return $state ?? '-';
                        }

                        return substr($state, 0, 4) . 'XXXXXX';
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('loan_applied')
                    ->label('Loan Applied')
                    ->searchable(),

                Tables\Columns\TextColumn::make('salary')
                    ->label('Salary')
                    ->formatStateUsing(fn($state) => filled($state) ? '₹' . number_format((float) $state, 0) : '-')
                    ->alignRight()
                    ->sortable(),

                Tables\Columns\TextColumn::make('eligibility_status')
                    ->label('Eligibility')
                    ->badge(),

                Tables\Columns\TextColumn::make('bank_eligible_for')
                    ->label('Bank Eligible For')
                    ->formatStateUsing(function ($state, $record) {
                        return strtolower((string) $state) === 'other'
                            ? ($record->other_bank_eligible_for ?: '-')
                            : $state;
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('journey_status')
                    ->label('Journey')
                    ->badge(),

                Tables\Columns\TextColumn::make('sanctioned_bank')
                    ->label('Bank')
                    ->searchable(),

                Tables\Columns\TextColumn::make('channel')
                    ->label('Channel')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25, 50, 100, 'all'])
            ->recordActions([
                ViewAction::make(),
                // EditAction::make(),
                EditAction::make()
                    ->visible(
                        fn($record) =>
                        // ! $record->documents_submitted &&
                            auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
                    ),
                // DeleteAction::make()
                //     ->visible(
                //         fn($record) =>
                //         ! $record->documents_submitted &&
                //             auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
                //     ),
                Action::make('followup')
                    ->label('Follow Up')
                    ->icon('heroicon-o-phone')
                    ->color('warning')
                    ->url(fn($record) => FollowUpResource::getUrl('create', [
                        'customer' => $record->id,
                    ])),
            ])
            ->headerActions([

                ExportAction::make()
                    ->exporter(CustomerExporter::class)
                    ->label('Export Customers')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn() => auth()->user()->hasRole('Admin')),

                ImportAction::make()
                    ->label('Import Customers')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->importer(CustomerImporter::class)
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
                ExportBulkAction::make()
                    ->exporter(CustomerExporter::class)
                    ->label('Export Selected'),

            ])
            ->filters([
                    SelectFilter::make('journey_status')
                        ->label('Journey Status')
                        ->options([
                            'sfl' => 'SFL',
                            'underwriting' => 'Underwriting',
                            'approved' => 'Approved',
                            'sanctioned' => 'Sanctioned',
                            'disbursal_documents' => 'Disbursal Documents',
                            'completed' => 'Completed',
                            'carry_forward' => 'Carry Forward',
                            'dropped' => 'Dropped',
                            'not_approved' => 'Not Approved',
                        ])
                        ->searchable()
                        ->preload(),
                ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $employee = auth()->user()->employee;

        if (! $employee) {
            return $query;
        }

        if (auth()->user()->hasRole('Admin')) {
            return $query;
        }

        return $query->whereIn(
            'assign_to',
            HierarchyHelper::callerIds($employee)
        );
    }

    public static function canEdit(Model $record): bool
    {
        // return auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
        //     && ! $record->documents_submitted;

        return auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->employee?->designation !== Employee::DESIGNATION_CALLER
            && ! $record->documents_submitted;
    }



}
