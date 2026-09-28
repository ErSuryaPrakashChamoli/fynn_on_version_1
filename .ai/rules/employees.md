---
paths:
  - 'app/Filament/Resources/Employees/**'
---

# Employees

## Reporting columns are written only by ReportingLineService
Since 2026-09-15 nothing writes superviser_id/manager_id/cluster_id/business_head_id directly. The Employee form has ONE "Reports To" select (not a column): CreateEmployee::mutateFormDataBeforeCreate() derives the four columns via columnsUnder(); EditEmployee unsets it and calls ReportingLineService::reportTo() in afterSave() (page runs in a DB transaction). The Transfer Employee action, HierarchyReassignmentService::transfer()/reassign() and EmployeeImporter (reports_to emp_id, falling back to legacy superviser_id then manager_id) all go through the same service.

reportTo() validates the boss (active, strictly senior; Business Head/Admin report to nobody; everyone else needs a boss, a Cluster Manager only once a Business Head exists), re-fills every descendant from the tree, never touches reporting_date, and writes history only for employees on the rolls. It always runs on edit save, so saving an employee also tidies drifted columns under them. A designation change re-slots direct reports; a demotion below any direct report is refused by the designation field rule (designationProblem()). Covered by tests/Feature/ReportingLineChangeTest.php.

## Employee dropdowns come from the Employee Setup lists, never hardcoded arrays
Since 2026-09-28 Designation, Position, Target Category, Cost Center, Unit and Role are admin-managed (sidebar "Employee Setup", Admin only). Models use App\Models\Concerns\IsEmployeeOption: options()/optionsIncluding()/labelFor(), delete blocked while an employee holds the value. Labels are swapped against columns: Designation model -> employees.position (stored by NAME, rename cascades to employees); Position model -> employees.designation (id = DESIGNATION_* code; is_system rows can be renamed, never deleted; admin-added positions have designationRank 0, i.e. outside the reporting tree). TargetCategory/CostCenter/Unit store an immutable generated `code`; the target comes from TargetCategory::targetAmountFor() (target_amount, else numeric code). RoleResource::SYSTEM_ROLES are role names checked in code — never renamable/deletable; add to it when code starts checking a new role name. Covered by tests/Feature/EmployeeSetupListsTest.php.

## Employee Setup lists: Active toggle and dependency-aware delete
Since 2026-09-28 every Employee Setup list (and roles) has is_active. Forms offer only active options via optionsIncluding()/activeOptions() (the value already on the record stays selectable); options()/labelFor() still cover inactive ones for display. Delete goes through App\Filament\Actions\DeleteWithDependenciesAction (action name 'delete'): if employees/users still hold the option, the pop-up lists them with Edit links and a required "Move them to" select, then moves them (IsEmployeeOption::moveEmployeesTo / RoleResource::moveUsers) and deletes in one transaction. Move targets: other active options; Position only to out-of-tree positions (rank 0); Role never to hierarchy roles. Test trap: Role's guardable-column list is cached before the is_active migration, so Role::create(['is_active' => false]) drops the flag in tests — use forceFill.
