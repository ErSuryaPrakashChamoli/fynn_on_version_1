<?php

namespace App\Filament\Resources\AssignedLeads;

use App\Filament\Resources\AssignedLeads\Pages\EditAssignedLead;
use App\Filament\Resources\AssignedLeads\Pages\ListAssignedLeads;
use App\Filament\Resources\AssignedLeads\Pages\ViewAssignedLead;
use App\Filament\Resources\AssignedLeads\Schemas\AssignedLeadForm;
use App\Filament\Resources\AssignedLeads\Schemas\AssignedLeadInfolist;
use App\Filament\Resources\AssignedLeads\Tables\AssignedLeadsTable;
use App\Models\CustomerAssignment;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class AssignedLeadResource extends Resource
{
    protected static ?string $model = CustomerAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    protected static ?string $navigationLabel = 'Assigned Leads';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return AssignedLeadForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssignedLeadsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AssignedLeadInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['customer', 'aiCustomerRecord.schema', 'batch']);

        $user = Auth::user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Admin')) {
            return $query;
        }

        return $query->whereIn('employee_id', HierarchyService::visibleEmployeeIds($user));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssignedLeads::route('/'),
            'view' => ViewAssignedLead::route('/{record}'),
            'edit' => EditAssignedLead::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return true;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
