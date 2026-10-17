<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Cibil\CibilReportService;
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
 * Where the Admin decides who may use the CIBIL module and what else they
 * may do in it (full reports, PDFs, fresh pulls, audit history): whole
 * roles, individual logins, or both, per permission (see
 * App\Support\CibilAccess). Admins always have everything and are not
 * listed as a choice. Every change is written to the CIBIL audit log.
 */
class CibilAccessSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockOpen;

    protected static string|UnitEnum|null $navigationGroup = 'CIBIL Management';

    protected static ?string $navigationLabel = 'CIBIL Access';

    protected static ?string $title = 'CIBIL Access';

    protected static ?string $slug = 'cibil-access';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.cibil-access-settings';

    /** @var array{roles?: list<string>, users?: list<int|string>, grants?: array<string, array{roles?: list<string>, users?: list<int|string>}>}|null */
    public ?array $data = [];

    public function mount(): void
    {
        $grants = [];

        foreach (CibilAccess::EXTRA_PERMISSIONS as $permission) {
            $grants[self::grantKey($permission)] = [
                'roles' => CibilAccess::grantedRoleNames($permission),
                'users' => CibilAccess::grantedUserIds($permission),
            ];
        }

        $this->form->fill([
            'roles' => CibilAccess::grantedRoleNames(),
            'users' => CibilAccess::grantedUserIds(),
            'grants' => $grants,
        ]);
    }

    /** cibil.full_report → cibil_full_report, so the form's state path has no extra dot. */
    protected static function grantKey(string $permission): string
    {
        return str_replace('.', '_', $permission);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who can use the CIBIL module?')
                    ->description('Give access to whole roles, to individual users, or both. With access, a login sees the CIBIL profile on the leads and customer files it can already see, and can run score checks. Admins always have access and see every report. Taking access away also hides earlier reports.')
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

                ...collect(CibilAccess::EXTRA_PERMISSION_LABELS)
                    ->map(fn (array $permission, string $name): Section => Section::make($permission['label'])
                        ->description($permission['description'].' Only counts for logins that also have CIBIL access above.')
                        ->schema([
                            Select::make('grants.'.self::grantKey($name).'.roles')
                                ->label('Roles')
                                ->multiple()
                                ->options(fn (): array => self::roleOptions()),

                            Select::make('grants.'.self::grantKey($name).'.users')
                                ->label('Individual users')
                                ->multiple()
                                ->options(fn (): array => self::userOptions()),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->columnSpanFull())
                    ->values()
                    ->all(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $roles = array_values($data['roles'] ?? []);
        $userIds = array_map('intval', array_values($data['users'] ?? []));

        CibilAccess::sync($roles, $userIds);

        $grants = [];

        foreach (CibilAccess::EXTRA_PERMISSIONS as $permission) {
            $grant = $data['grants'][self::grantKey($permission)] ?? [];
            $grants[$permission] = [
                'roles' => array_values($grant['roles'] ?? []),
                'user_ids' => array_map('intval', array_values($grant['users'] ?? [])),
            ];

            CibilAccess::sync($grants[$permission]['roles'], $grants[$permission]['user_ids'], $permission);
        }

        activity(CibilReportService::LOG_NAME)
            ->causedBy(Filament::auth()->user())
            ->event('updated')
            ->withProperties(['roles' => $roles, 'user_ids' => $userIds, 'grants' => $grants])
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
     * @return Collection<int, array{name: string, email: string, emp_id: string|null, via: string, extras: list<string>}>
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
                    'extras' => collect(CibilAccess::EXTRA_PERMISSION_LABELS)
                        ->filter(fn (array $permission, string $name): bool => CibilAccess::has($user, $name))
                        ->pluck('label')
                        ->values()
                        ->all(),
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
