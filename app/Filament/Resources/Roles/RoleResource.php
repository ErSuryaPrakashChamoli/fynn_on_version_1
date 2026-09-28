<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Actions\DeleteWithDependenciesAction;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\HierarchyRoleService;
use App\Services\OtherBankSupportService;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * The login roles offered on the User form. Screens are opened by role
 * NAME in code (hasRole('Admin'), ...), so the built-in roles below can be
 * neither renamed nor deleted; roles added here can be, once their logins
 * are moved to another role (the delete pop-up offers that). An inactive
 * role is no longer offered on the User form.
 */
class RoleResource extends Resource
{
    /**
     * Role names the application checks for. Renaming one would silently
     * shut its holders out of their screens.
     *
     * @var list<string>
     */
    public const SYSTEM_ROLES = [
        'Admin',
        'Accounts',
        'MIS',
        'IT',
        'Employee',
        'Caller',
        'Team Leader',
        'Manager',
        'Cluster Manager',
        HierarchyRoleService::BUSINESS_HEAD_ROLE,
        OtherBankSupportService::ROLE,
    ];

    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Employee Setup';

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'role';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('guard_name', 'web'),
                    )
                    ->helperText('A new role opens only the screens that are not limited to specific roles.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->helperText('An inactive role is no longer offered on the User form; logins that already have it keep it.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_system')
                    ->label('Built-in')
                    ->state(fn (Role $record): bool => self::isSystemRole($record))
                    ->boolean(),

                ToggleColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Role $record): bool => static::canEdit($record)),
                DeleteWithDependenciesAction::make(
                    optionLabel: 'role',
                    dependentNoun: 'user',
                    fieldLabel: 'Role',
                    countDependents: fn (Role $record): int => $record->users()->count(),
                    listDependents: fn (Role $record, int $limit): array => $record->users()
                        ->orderBy('name')
                        ->limit($limit)
                        ->get()
                        ->map(fn (User $user): array => [
                            'label' => "{$user->name} ({$user->email})",
                            'url' => UserResource::getUrl('edit', ['record' => $user]),
                        ])
                        ->all(),
                    replacementOptions: fn (Role $record): array => self::replacementOptions($record),
                    moveDependents: fn (Role $record, int|string $replacement) => self::moveUsers($record, (int) $replacement),
                    canDelete: fn (Role $record): bool => static::canDelete($record),
                ),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('guard_name', 'web');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRoles::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->hasRole('Admin');
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof Role
            && ! self::isSystemRole($record);
    }

    /**
     * Built-in roles stay. A custom role still held by logins can be
     * deleted once they are moved — the delete pop-up offers that.
     */
    public static function canDelete(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof Role
            && ! self::isSystemRole($record);
    }

    /**
     * Where a deleted role's logins may be moved: other active roles, minus
     * the hierarchy roles, which follow the employee's position (see
     * HierarchyRoleService) and are set on the User form instead.
     *
     * @return array<int, string>
     */
    public static function replacementOptions(Role $role): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where('is_active', true)
            ->whereKeyNot($role->getKey())
            ->whereNotIn('name', HierarchyRoleService::ROLES)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function moveUsers(Role $role, int $replacementId): void
    {
        $replacement = Role::query()->findOrFail($replacementId);

        $role->users()->get()->each(function (User $user) use ($role, $replacement): void {
            $user->removeRole($role);

            if (! $user->hasRole($replacement)) {
                $user->assignRole($replacement);
            }
        });
    }

    public static function isSystemRole(Role $role): bool
    {
        return in_array($role->name, self::SYSTEM_ROLES, true);
    }
}
