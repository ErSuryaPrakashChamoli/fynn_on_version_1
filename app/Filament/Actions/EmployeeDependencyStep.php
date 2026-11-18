<?php

namespace App\Filament\Actions;

use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\CustomerJourneyDelegations\CustomerJourneyDelegationResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerJourneyDelegation;
use App\Models\Employee;
use App\Models\Lead;
use App\Services\EmployeeDependencyService as Dependencies;
use App\Support\EmployeeOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The "clear what is still attached" step shared by the pop-ups that take
 * an employee away from their work or their team — Mark Inactive, Transfer
 * Employee and the Flexible Reassignment.
 *
 * $scope picks what counts: Dependencies::SCOPE_LEAVE (everything, handed
 * to anybody on the rolls) when they leave, Dependencies::SCOPE_MOVE (only
 * their team) when they move to a new boss — their own work goes with them.
 *
 * It lists each kind still attached (EmployeeDependencyService), with a
 * link per item to fix it one by one, and a field per kind to hand them all
 * over right there. After clear() the calling pop-up opens again (see
 * ReopenAction): on whatever is left, or on its own next step.
 */
final class EmployeeDependencyStep
{
    /**
     * How many items of each kind are listed by name.
     */
    public const LISTED_ITEMS = 10;

    /**
     * What is still attached to $employee, worked out once per request and
     * forgotten (Once::flush()) as soon as something is handed over.
     *
     * @param  list<int>  $exceptReportIds  Direct reports moving elsewhere in the same batch.
     * @return array<string, int>
     */
    public static function dependencies(Employee $employee, array $exceptReportIds = [], string $scope = Dependencies::SCOPE_LEAVE): array
    {
        return once(fn (): array => self::service()->dependencyCounts($employee, $exceptReportIds, $scope));
    }

    public static function heading(Employee $employee, string $scope = Dependencies::SCOPE_LEAVE): string
    {
        return $scope === Dependencies::SCOPE_MOVE
            ? "{$employee->emp_name} has a team that stays behind"
            : "{$employee->emp_name} still has work attached";
    }

    /**
     * @param  list<int>  $exceptReportIds
     */
    public static function description(Employee $employee, string $nextStep, array $exceptReportIds = [], string $scope = Dependencies::SCOPE_LEAVE): string
    {
        return 'Still attached: '.self::service()->describe(self::dependencies($employee, $exceptReportIds, $scope))
            .($scope === Dependencies::SCOPE_MOVE ? '. Choose who they report to below. ' : '. Hand them over or cancel them below. ')
            .$nextStep;
    }

    /**
     * One section per kind still attached: the items, and who to hand them to.
     *
     * @param  list<int>  $exceptReportIds
     * @return list<Component>
     */
    public static function schema(Employee $employee, array $exceptReportIds = [], string $scope = Dependencies::SCOPE_LEAVE): array
    {
        $service = self::service();
        $dependencies = self::dependencies($employee, $exceptReportIds, $scope);
        $sections = [];

        if (isset($dependencies[Dependencies::DIRECT_REPORTS])) {
            $sections[] = self::section(
                Dependencies::DIRECT_REPORTS,
                $dependencies,
                $service->directReports($employee, $exceptReportIds)->orderBy('emp_name')->limit(self::LISTED_ITEMS)->get()
                    ->map(fn (Employee $report): array => [
                        'label' => EmployeeOptions::label($report).' — '.(Employee::designationOptions()[$report->designation] ?? '—'),
                        'url' => EmployeeResource::getUrl('edit', ['record' => $report]),
                    ])
                    ->all(),
                [
                    self::handOverSelect('direct_reports_target', 'Move them to report to', $service->newBossOptions($employee, $exceptReportIds), 'nobody more senior than all of them'),
                    DatePicker::make('handover_date')
                        ->label('Hand-over date')
                        ->helperText('Dates their reporting change. Also offered as the date of the next step.')
                        ->maxDate(now())
                        ->required()
                        ->native(false)
                        ->displayFormat('d F Y'),
                ],
                'They keep their own customers and leads.',
            );
        }

        if (isset($dependencies[Dependencies::CUSTOMERS])) {
            $sections[] = self::section(
                Dependencies::CUSTOMERS,
                $dependencies,
                $service->openCustomers($employee)->orderBy('customer_name')->limit(self::LISTED_ITEMS)->get()
                    ->map(fn (Customer $customer): array => [
                        'label' => "{$customer->customer_name} (#{$customer->id})",
                        'url' => CustomerResource::getUrl('view', ['record' => $customer]),
                    ])
                    ->all(),
                [self::handOverSelect('customers_target', 'Hand them to', $service->newOwnerOptions($employee), 'nobody else on the rolls')],
                'Disbursed and dropped customers stay with them, so past achievement is unchanged.',
            );
        }

        if (isset($dependencies[Dependencies::ASSIGNED_LEADS])) {
            $sections[] = self::section(
                Dependencies::ASSIGNED_LEADS,
                $dependencies,
                $service->openAssignedLeads($employee)->with('customer:id,customer_name')->limit(self::LISTED_ITEMS)->get()
                    ->map(fn (CustomerAssignment $assignment): array => [
                        'label' => ($assignment->customer?->customer_name ?? 'AI customer record')." (lead #{$assignment->id})",
                        'url' => AssignedLeadResource::getUrl('view', ['record' => $assignment]),
                    ])
                    ->all(),
                [self::handOverSelect('assigned_leads_target', 'Hand them to', $service->newOwnerOptions($employee), 'nobody else on the rolls')],
                'Converted and closed leads stay with them, so lead reports are unchanged.',
            );
        }

        if (isset($dependencies[Dependencies::LEADS])) {
            $sections[] = self::section(
                Dependencies::LEADS,
                $dependencies,
                $service->openLeads($employee)->orderBy('customer_name')->limit(self::LISTED_ITEMS)->get()
                    ->map(fn (Lead $lead): array => [
                        'label' => "{$lead->customer_name} (#{$lead->id})",
                        'url' => LeadResource::getUrl('edit', ['record' => $lead]),
                    ])
                    ->all(),
                [self::handOverSelect('leads_target', 'Hand them to', $service->newOwnerOptions($employee), 'nobody else on the rolls')],
            );
        }

        if (isset($dependencies[Dependencies::DELEGATIONS])) {
            $sections[] = self::section(
                Dependencies::DELEGATIONS,
                $dependencies,
                $service->liveDelegations($employee)->with(['delegatingManager:id,emp_name', 'actingManager:id,emp_name'])->limit(self::LISTED_ITEMS)->get()
                    ->map(fn (CustomerJourneyDelegation $delegation): array => [
                        'label' => ($delegation->delegatingManager?->emp_name ?? '—').' → '.($delegation->actingManager?->emp_name ?? '—')
                            .' ('.$delegation->status.', until '.$delegation->end_at->format('d M Y').')',
                        'url' => CustomerJourneyDelegationResource::getUrl('index'),
                    ])
                    ->all(),
                [
                    Checkbox::make('cancel_delegations')
                        ->label('Cancel these delegations'),
                ],
            );
        }

        return $sections;
    }

    /**
     * Hand over every kind the admin chose somebody for, in one transaction.
     * Keeps the pop-up open (halts) when nothing was chosen or a hand-over
     * is refused.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $exceptReportIds
     * @return array<string, int> what is still attached afterwards
     */
    public static function clear(Employee $employee, array $data, Action $action, array $exceptReportIds = [], string $scope = Dependencies::SCOPE_LEAVE): array
    {
        $service = self::service();
        $user = Auth::user();
        $handoverDate = Carbon::parse($data['handover_date'] ?? today());
        $cleared = [];

        try {
            DB::transaction(function () use ($service, $employee, $data, $user, $handoverDate, $exceptReportIds, &$cleared): void {
                if (filled($data['direct_reports_target'] ?? null)) {
                    $cleared[Dependencies::DIRECT_REPORTS] = $service->moveDirectReports($employee, (int) $data['direct_reports_target'], $handoverDate, $user, $exceptReportIds);
                }

                if (filled($data['customers_target'] ?? null)) {
                    $cleared[Dependencies::CUSTOMERS] = $service->moveOpenCustomers($employee, (int) $data['customers_target'], $user);
                }

                if (filled($data['assigned_leads_target'] ?? null)) {
                    $cleared[Dependencies::ASSIGNED_LEADS] = $service->moveOpenAssignedLeads($employee, (int) $data['assigned_leads_target'], $user);
                }

                if (filled($data['leads_target'] ?? null)) {
                    $cleared[Dependencies::LEADS] = $service->moveOpenLeads($employee, (int) $data['leads_target']);
                }

                if ($data['cancel_delegations'] ?? false) {
                    $cleared[Dependencies::DELEGATIONS] = $service->cancelLiveDelegations($employee, $user);
                }
            });
        } catch (ValidationException $exception) {
            self::refuse($exception, $action);
        }

        $cleared = array_filter($cleared);

        if ($cleared === []) {
            Notification::make()
                ->title('Nothing was handed over')
                ->body('Choose who takes at least one of the lists, or fix them one by one with the links.')
                ->warning()
                ->send();

            $action->halt();
        }

        Once::flush();

        $remaining = self::dependencies($employee, $exceptReportIds, $scope);

        Notification::make()
            ->title('Handed over '.$service->describe($cleared))
            ->body($remaining === []
                ? 'Nothing is attached to them any more.'
                : 'Still attached: '.$service->describe($remaining).'.')
            ->success()
            ->send();

        return $remaining;
    }

    /**
     * Report a refused step and keep the pop-up open, undoing its writes.
     */
    public static function refuse(ValidationException $exception, Action $action): never
    {
        Notification::make()
            ->title('Not done')
            ->body(collect($exception->errors())->flatten()->first())
            ->danger()
            ->send();

        $action->halt(shouldRollBackDatabaseTransaction: true);
    }

    /**
     * @param  array<string, int>  $dependencies
     * @param  list<array{label: string, url: string|null}>  $items
     * @param  list<Component>  $fields
     */
    private static function section(string $kind, array $dependencies, array $items, array $fields, ?string $note = null): Section
    {
        $count = $dependencies[$kind];

        return Section::make(Str::ucfirst(Str::plural(Dependencies::NOUNS[$kind], $count))." ({$count})")
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->description($note)
            ->compact()
            ->schema([
                View::make('filament.components.delete-dependencies')
                    ->viewData([
                        'dependents' => $items,
                        'total' => $count,
                        'noun' => Dependencies::NOUNS[$kind],
                        'heading' => 'Fix one by one, or hand them all over below',
                        'linkLabel' => 'Open',
                    ]),
                ...$fields,
            ]);
    }

    /**
     * @param  array<int|string, mixed>  $options
     */
    private static function handOverSelect(string $name, string $label, array $options, string $nobodyReason): Select|Text
    {
        if ($options === []) {
            return Text::make("There is {$nobodyReason} to hand them to. Fix them one by one with the links above.");
        }

        return Select::make($name)
            ->label($label)
            ->options($options)
            ->placeholder('Leave for now')
            ->searchable()
            ->native(false);
    }

    private static function service(): Dependencies
    {
        return app(Dependencies::class);
    }
}
