<?php

namespace App\Filament\Pages;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\TargetCategory;
use App\Models\Unit;
use App\Support\HierarchyHelper;
use Carbon\Carbon;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use NumberFormatter;

/**
 * A self-service "My Profile" page, registered via Panel::profile() so it
 * takes over the user menu's built-in "Profile" entry rather than adding a
 * new one. Extending Filament's own EditProfile — instead of a plain Page —
 * is required for that wiring (the profile route/menu item specifically
 * expects an EditProfile subclass) and gives the save flow, rate limiting,
 * and notification handling for free.
 *
 * Every field here except the photo is a Placeholder (read-only, never part
 * of the submitted form state), so saving this page can only ever update
 * avatar_path on the User — Admin-managed Employee data is untouched no
 * matter what the browser sends.
 */
class MyProfile extends BaseEditProfile
{
    protected static ?string $title = 'My Profile';

    public function form(Schema $schema): Schema
    {
        $employee = $this->getUser()->employee;

        return $schema->components([
            Section::make()
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])
                        ->schema([
                            FileUpload::make('avatar_path')
                                ->label('Profile Photo')
                                ->avatar()
                                ->image()
                                ->imageEditor()
                                ->disk('public')
                                ->directory('avatars')
                                ->visibility('public')
                                ->maxSize(2048)
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                // The avatars/ directory holds every user's
                                // photo, so without this a tampered request
                                // could set avatar_path to any other file's
                                // path on the same disk.
                                ->preventFilePathTampering()
                                ->columnSpan(1),

                            Grid::make(1)
                                ->schema([
                                    Placeholder::make('profile_name')
                                        ->label('Employee Name')
                                        ->content($employee?->emp_name ?? $this->getUser()->name),
                                    Placeholder::make('profile_emp_id')
                                        ->label('Employee ID')
                                        ->content($employee?->emp_id ?? '—'),
                                    Placeholder::make('profile_designation')
                                        ->label('Designation')
                                        ->content($this->designationLabel($employee)),
                                ])
                                ->columnSpan(2),
                        ]),
                ]),

            Section::make('Employee Information')
                ->schema($this->employeeInfoFields($employee))
                ->columns(2),

            Section::make('Organization')
                ->schema($this->organizationFields($employee))
                ->columns(2)
                ->visible((bool) $employee),

            Section::make('Account')
                ->schema($this->accountFields())
                ->columns(2),
        ]);
    }

    /**
     * @return array<Placeholder>
     */
    protected function employeeInfoFields(?Employee $employee): array
    {
        if (! $employee) {
            return [
                Placeholder::make('no_employee')
                    ->label('')
                    ->content('No employee profile is linked to your account. Contact an Admin if this looks wrong.')
                    ->columnSpanFull(),
            ];
        }

        return [
            Placeholder::make('info_name')->label('Name')->content($employee->emp_name),
            Placeholder::make('info_emp_id')->label('Employee ID')->content($employee->emp_id),
            Placeholder::make('info_email')->label('Email')->content($employee->email ?? $this->getUser()->email),
            Placeholder::make('info_designation')->label('Designation')->content($this->designationLabel($employee)),
            Placeholder::make('info_category')->label('Category')->content($this->categoryLabel($employee->category)),
            Placeholder::make('info_target')->label('Target')->content(EmployeeHierarchy::targetLabel($employee)),
            Placeholder::make('info_doj')->label('Date of Joining')->content(
                $employee->doj ? Carbon::parse($employee->doj)->format('d M Y') : '—'
            ),
        ];
    }

    /**
     * @return array<Placeholder>
     */
    protected function organizationFields(?Employee $employee): array
    {
        if (! $employee) {
            return [];
        }

        $bossId = HierarchyHelper::directBossId($employee);
        $reportsTo = $bossId !== null ? Employee::query()->find($bossId) : null;

        return [
            Placeholder::make('org_reports_to')->label('Reporting To')->content($reportsTo?->emp_name ?? '—'),
            Placeholder::make('org_cluster')->label('Cluster')->content($employee->clusterManager?->emp_name ?? '—'),
            Placeholder::make('org_unit')->label('Unit')->content(Unit::labelFor($employee->unit_name) ?? '—'),
            Placeholder::make('org_cost_center')->label('Cost Center')->content(CostCenter::labelFor($employee->cost_center) ?? '—'),
        ];
    }

    /**
     * @return array<Placeholder>
     */
    protected function accountFields(): array
    {
        $user = $this->getUser();

        return [
            Placeholder::make('account_role')->label('Role')->content($user->getRoleNames()->implode(', ') ?: '—'),
            Placeholder::make('account_status')->label('Account Status')->content($user->is_active ? 'Active' : 'Inactive'),
        ];
    }

    protected function designationLabel(?Employee $employee): string
    {
        if (! $employee) {
            return '—';
        }

        return Employee::designationOptions()[$employee->designation] ?? '—';
    }

    /**
     * The category's name as set on the Target Categories screen, with its
     * monthly target when it has one — "Gold (₹30,00,000)".
     */
    protected function categoryLabel(?string $category): string
    {
        if (blank($category)) {
            return '—';
        }

        $name = TargetCategory::labelFor($category);
        $amount = TargetCategory::targetAmountFor($category);

        if ($amount === null) {
            return $name;
        }

        $formatter = new NumberFormatter('en_IN', NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $formattedAmount = $formatter->formatCurrency($amount, 'INR');

        return $name === $category ? $formattedAmount : "{$name} ({$formattedAmount})";
    }
}
