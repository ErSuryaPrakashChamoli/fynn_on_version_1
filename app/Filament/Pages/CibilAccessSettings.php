<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\CibilAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Where the Admin decides who may fetch and see CIBIL reports: whole roles,
 * individual logins, or both (see App\Support\CibilAccess). Admins always
 * have access and are not listed as a choice. Every change is written to
 * the activity log.
 */
class CibilAccessSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockOpen;

    protected static string|UnitEnum|null $navigationGroup = 'People & Access';

    protected static ?string $navigationLabel = 'CIBIL Access';

    protected static ?string $title = 'CIBIL Access';

    protected static ?string $slug = 'cibil-access';

    protected string $view = 'filament.pages.cibil-access-settings';

    /** @var array{roles?: list<string>, users?: list<int|string>}|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'roles' => CibilAccess::grantedRoleNames(),
            'users' => CibilAccess::grantedUserIds(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who can fetch CIBIL reports?')
                    ->description('Give access to whole roles, to individual users, or both. Admins always have access and see every report; everyone else sees only the reports they fetched. Taking access away also hides their earlier reports from them.')
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->multiple()
                            ->options(fn (): array => self::roleOptions())
                            ->helperText('Everyone holding one of these roles gets access.'),

                        Select::make('users')
                            ->label('Individual users')
                            ->multiple()
                            ->options(fn (): array => self::userOptions())
                            ->helperText('Access for these users whatever their role.'),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $roles = array_values($data['roles'] ?? []);
        $userIds = array_map('intval', array_values($data['users'] ?? []));

        CibilAccess::sync($roles, $userIds);

        activity()
            ->causedBy(Filament::auth()->user())
            ->event('updated')
            ->withProperties(['roles' => $roles, 'user_ids' => $userIds])
            ->log('CIBIL access updated');

        Notification::make()
            ->title('CIBIL access saved')
            ->success()
            ->send();
    }

    /**
     * Everyone who can open the module right now, and why — shown under the
     * form so the Admin sees the effect of a role grant before saving more.
     *
     * @return Collection<int, array{name: string, email: string, emp_id: string|null, via: string}>
     */
    public function usersWithAccess(): Collection
    {
        $grantedRoles = CibilAccess::grantedRoleNames();

        return CibilAccess::usersWithAccess()
            ->with(['roles', 'permissions', 'employee'])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($grantedRoles): array {
                $roles = $user->roles->pluck('name');

                $via = match (true) {
                    $roles->contains(CibilAccess::ADMIN_ROLE) => 'Admin',
                    $user->permissions->contains('name', CibilAccess::PERMISSION) => 'Individually',
                    default => 'Role: '.$roles->intersect($grantedRoles)->implode(', '),
                };

                return [
                    'name' => $user->name,
                    'email' => $user->email,
                    'emp_id' => $user->employee?->emp_id,
                    'via' => $via,
                ];
            });
    }

    /**
     * @return array<string, string>
     */
    protected static function roleOptions(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where('is_active', true)
            ->where('name', '!=', CibilAccess::ADMIN_ROLE)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function userOptions(): array
    {
        return User::query()
            ->with('employee')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => $user->employee?->emp_id
                    ? "{$user->name} ({$user->employee->emp_id})"
                    : "{$user->name} · {$user->email}",
            ])
            ->all();
    }

    public static function canAccess(): bool
    {
        return CibilAccess::canManage(Filament::auth()->user());
    }
}
