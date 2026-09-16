---
paths:
  - 'app/Filament/Resources/Employees/**'
---

# Employees

## Reporting columns are written only by ReportingLineService
Since 2026-09-15 nothing writes superviser_id/manager_id/cluster_id/business_head_id directly. The Employee form has ONE "Reports To" select (not a column): CreateEmployee::mutateFormDataBeforeCreate() derives the four columns via columnsUnder(); EditEmployee unsets it and calls ReportingLineService::reportTo() in afterSave() (page runs in a DB transaction). The Transfer Employee action, HierarchyReassignmentService::transfer()/reassign() and EmployeeImporter (reports_to emp_id, falling back to legacy superviser_id then manager_id) all go through the same service.

reportTo() validates the boss (active, strictly senior; Business Head/Admin report to nobody; everyone else needs a boss, a Cluster Manager only once a Business Head exists), re-fills every descendant from the tree, never touches reporting_date, and writes history only for employees on the rolls. It always runs on edit save, so saving an employee also tidies drifted columns under them. A designation change re-slots direct reports; a demotion below any direct report is refused by the designation field rule (designationProblem()). Covered by tests/Feature/ReportingLineChangeTest.php.
