---
paths:
  - 'app/Filament/Resources/Employees/**'
  - 'app/Services/EmployeeInactivationService.php'
  - 'app/Services/EmployeeDependencyService.php'
  - 'app/Services/EmployeeTransferService.php'
  - 'app/Filament/Actions/InactivateEmployeeAction.php'
  - 'app/Filament/Actions/TransferEmployeeAction.php'
  - 'app/Filament/Actions/EmployeeDependencyStep.php'
  - 'app/Filament/Actions/EmployeeOptionActivationActions.php'
  - 'app/Models/Concerns/HasEffectiveInactivation.php'
---

# Employees

## Reporting columns are written only by ReportingLineService
Since 2026-09-15 nothing writes superviser_id/manager_id/cluster_id/business_head_id directly. The Employee form has ONE "Reports To" select (not a column): CreateEmployee::mutateFormDataBeforeCreate() derives the four columns via columnsUnder(); EditEmployee unsets it and calls ReportingLineService::reportTo() in afterSave() (page runs in a DB transaction). The Transfer Employee action, HierarchyReassignmentService::transfer()/reassign() and EmployeeImporter (reports_to emp_id, falling back to legacy superviser_id then manager_id) all go through the same service.

reportTo() validates the boss (active, strictly senior; Business Head/Admin report to nobody; everyone else needs a boss, a Cluster Manager only once a Business Head exists), re-fills every descendant from the tree, never touches reporting_date, and writes a history row for every employee whose columns changed — exited ones too since 2026-11-14 (their cases still disburse after they leave, and ReportingTimeline rebuilds past months from these rows; their exit row is left open). The effective date is what every month figure keys on, so set it to the real date of the move. It always runs on edit save, so saving an employee also tidies drifted columns under them. A designation change re-slots direct reports; a demotion below any direct report is refused by the designation field rule (designationProblem()). Covered by tests/Feature/ReportingLineChangeTest.php.

## Employee dropdowns come from the Employee Setup lists, never hardcoded arrays
Since 2026-09-28 Designation, Position, Target Category, Cost Center, Unit and Role are admin-managed (sidebar "Employee Setup", Admin only). Models use App\Models\Concerns\IsEmployeeOption: options()/optionsIncluding()/labelFor(), delete blocked while an employee holds the value. Labels are swapped against columns: Designation model -> employees.position (stored by NAME, rename cascades to employees); Position model -> employees.designation (id = DESIGNATION_* code; is_system rows can be renamed, never deleted; admin-added positions have designationRank 0, i.e. outside the reporting tree). TargetCategory/CostCenter/Unit store an immutable generated `code`; the target comes from TargetCategory::targetAmountFor() (target_amount, else numeric code). RoleResource::SYSTEM_ROLES are role names checked in code — never renamable/deletable; add to it when code starts checking a new role name. Covered by tests/Feature/EmployeeSetupListsTest.php.

## Employee Setup lists: Active toggle and dependency-aware delete
Since 2026-09-28 every Employee Setup list (and roles) has is_active. Forms offer only active options via optionsIncluding()/activeOptions() (the value already on the record stays selectable); options()/labelFor() still cover inactive ones for display. Delete goes through App\Filament\Actions\DeleteWithDependenciesAction (action name 'delete'): if employees/users still hold the option, the pop-up lists them with Edit links and a required "Move them to" select, then moves them (IsEmployeeOption::moveEmployeesTo / RoleResource::moveUsers) and deletes in one transaction. Move targets: other active options; Position only to out-of-tree positions (rank 0); Role never to hierarchy roles. Test trap: Role's guardable-column list is cached before the is_active migration, so Role::create(['is_active' => false]) drops the flag in tests — use forceFill.

## Leaving or moving needs nothing attached; leaving is always dated
Since 2026-11-14 (decided with the user). EmployeeDependencyService defines "attached": active direct reports, open customers they own (JourneySlaService::activeCustomersQuery on assign_to), open assigned leads and leads (not converted, latest status not in CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES), pending/active delegations. Finished work (disbursed/dropped customers, converted/closed leads) is deliberately NOT handed over — achievement and lead reports credit whoever held it. Hand-overs go through the owning services (ReportingLineService, CustomerReassignmentService, CustomerAssignmentService, CustomerJourneyDelegationService).

Leaving: only EmployeeInactivationService::inactivate() (Edit page "Mark Inactive", inactivity-ticket Approve). exit_status on the Employee form is disabled + not dehydrated while active (EditEmployee also unsets it); it can still bring an exited employee back or fix exit_date. exit_date required, never future, never before doj; closes open reporting-history rows and writes the 'exit' row.

Moving: the employee moves ALONE — even their team is handed over first. EmployeeTransferService::transfer() ("Transfer Employee", TransferEmployeeAction) and ::reassign() (Flexible Reassignment on ListEmployees) refuse while anything is attached; reassign() ignores direct reports moved elsewhere in the same batch and runs rows lowest level first. The Edit form's Reports To refuses a straight move (same position, new boss) with work attached (EmployeeResource::moveProblem); a promotion/demotion still re-slots the team. Full Cluster Transfer (HierarchyReassignmentService::transfer) is the deliberate exception — it moves whole teams. HierarchyReassignmentService::reassign() itself stays unchecked (tests call it directly); UI paths must go through EmployeeTransferService. The importer still calls reportTo() directly.

The pop-ups share EmployeeDependencyStep and re-mount via App\Filament\Actions\ReopenAction (Filament keeps a replaced modal open only if name+context differ — hence the 'pass' context counter; assertActionMounted() fails on exact context, assert mountedActions[0]['name'] instead). The Flexible Reassignment hands over through ListEmployees::clearTransferDependenciesAction() (page method action, args employee + batch) and then replaceMountedAction('transferCluster', ['batch' => ...]) refills the form. Covered by tests/Feature/EmployeeInactivationTest.php, EmployeeTransferTest.php and EmployeeSetupInactivationTest.php.

Unit and Cost Center (HasEffectiveInactivation) no longer have an Active toggle: the Inactivate row action moves the holders first, then records `inactive_from` (the first inactive day); a saving hook refuses is_active=false while anyone holds it. Use wasActiveOn()/scopeActiveOn() for date-sensitive figures. Designation/Position/Target Category/Role keep the plain toggle. Date pickers in these pop-ups use maxDate(now()), not today(): the non-native picker fills a default with the current time, which fails a midnight max. Covered by tests/Feature/EmployeeSetupInactivationTest.php.
