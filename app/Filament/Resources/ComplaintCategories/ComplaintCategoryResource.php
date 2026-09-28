<?php

namespace App\Filament\Resources\ComplaintCategories;

use App\Filament\Resources\ComplaintCategories\Pages\CreateComplaintCategory;
use App\Filament\Resources\ComplaintCategories\Pages\EditComplaintCategory;
use App\Filament\Resources\ComplaintCategories\Pages\ListComplaintCategories;
use App\Filament\Resources\ComplaintCategories\Schemas\ComplaintCategoryForm;
use App\Filament\Resources\ComplaintCategories\Tables\ComplaintCategoriesTable;
use App\Models\ComplaintCategory;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin control of the Help Desk: which categories exist, the reasons
 * under each, and where each one's tickets go (a team by role, or a
 * supervisor the user picks).
 */
class ComplaintCategoryResource extends Resource
{
    protected static ?string $model = ComplaintCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Help Desk';

    protected static ?string $navigationLabel = 'Complaint Categories';

    protected static ?string $modelLabel = 'complaint category';

    protected static ?string $pluralModelLabel = 'complaint categories';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ComplaintCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComplaintCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplaintCategories::route('/'),
            'create' => CreateComplaintCategory::route('/create'),
            'edit' => EditComplaintCategory::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->hasRole('Admin');
    }

    /** A category with tickets under it is switched off, not deleted. */
    public static function canDelete(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof ComplaintCategory
            && ! $record->complaints()->exists();
    }
}
