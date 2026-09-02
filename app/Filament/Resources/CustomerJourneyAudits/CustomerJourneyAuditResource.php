<?php

namespace App\Filament\Resources\CustomerJourneyAudits;

use App\Filament\Resources\CustomerJourneyAudits\Pages\ListCustomerJourneyAudits;
use App\Filament\Resources\CustomerJourneyAudits\Tables\CustomerJourneyAuditsTable;
use App\Models\CustomerJourneyAudit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Deliberately read-only: only an 'index' page is registered below, and no
 * create/edit/delete action is wired anywhere in the table — this is what
 * makes the Customer Journey audit trail immutable from normal UI
 * operations, not just hidden by a canEdit()/canDelete() check.
 */
class CustomerJourneyAuditResource extends Resource
{
    protected static ?string $model = CustomerJourneyAudit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Audit History';

    protected static ?string $modelLabel = 'Journey Audit Entry';

    protected static ?string $pluralModelLabel = 'Audit History';

    public static function table(Table $table): Table
    {
        return CustomerJourneyAuditsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerJourneyAudits::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('Admin');
    }
}
