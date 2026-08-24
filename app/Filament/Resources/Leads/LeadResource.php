<?php

namespace App\Filament\Resources\Leads;

use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Filament\Resources\Leads\Tables\LeadsTable;
use App\Models\Lead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;


use Illuminate\Database\Eloquent\Builder;
use App\Services\HierarchyService;
use Illuminate\Support\Facades\Auth;

use App\Filament\Imports\LeadImporter;
use Filament\Actions\ImportAction;
use App\Filament\Resources\Leads\Schemas\LeadInfolist;
use UnitEnum;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $recordTitleAttribute = 'customer_name';

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'My Leads';




    public static function form(Schema $schema): Schema
    {
        return LeadForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
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
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }


    // public static function getEloquentQuery(): Builder
    // {
    //     $query = parent::getEloquentQuery();

    //     $user = Auth::user();

    //     if (! $user) {
    //         return $query->whereRaw('1 = 0');
    //     }


    //     dd("called");

    //     return $query->whereIn(
    //         'employee_id',
    //         HierarchyService::visibleEmployeeIds($user)
    //     );


    // }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Auth::user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('is_converted', false)
            ->whereIn(
                'employee_id',
                HierarchyService::visibleEmployeeIds($user)
            );
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeadInfolist::configure($schema);
    }
}
