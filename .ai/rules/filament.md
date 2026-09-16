---
paths:
  - 'app/Filament/**'
---

# Filament

## Dropdowns are searchable panel-wide by default — don't repeat ->searchable()
AdminPanelProvider::configureSearchableDropdowns() registers Select::configureUsing(searchable()) and SelectFilter::configureUsing(searchable()->preload()) (TernaryFilter excluded). Every form Select and table SelectFilter therefore gets a type-to-filter search box without opting in per component; an explicit ->searchable(false) on a component still wins because configureUsing runs at construction.

Consequence to watch: a Select bound to ->relationship() that is searchable but NOT preloaded returns no options until the user types (Select::getOptionsFromRelationship() returns null). Add ->preload() when the list is short and should show immediately.

tests/Feature/AllListingsFiltersAndDropdownsTest.php asserts every resource index renders, every SelectFilter on it produces a runnable query, and every one of them is searchable.

## Business Head role opens screens; the reporting tree scopes the data
Since 2026-09-15 a Business Head gets every screen a Cluster Manager gets — add 'Business Head' / Employee::DESIGNATION_BUSINESS_HEAD wherever a role or designation list grants Cluster Manager. But the role NEVER widens data: never write hasAnyRole(['Admin', 'Business Head']) → unscoped query. Only Admin is company-wide; a Business Head sees their own branch via HierarchyHelper (subordinateIds / visibleSubordinateIds on their employee). Removed bypasses: CustomerJourneyDelegationResource query, CustomerJourneyDelegationsTable::originalEmployeeOptions, CustomerJourneyDelegationService::assertCreatorAuthorized, MonthlyTargetGate::isAdminLine.

HierarchyRoleService keeps the role in step with the designation: Employee::booted() `updated` swaps a login's hierarchy role (Caller/Team Leader/Manager/Cluster Manager/Business Head) when designation changes, leaving Admin/MIS/Accounts/IT/Employee alone; UserForm refuses the Business Head role for a non-Business-Head employee (and requires it, or Admin, for one). PAN requests snapshot business_head_id/name; a Business Head sees snapshotted requests plus older ones raised in their branch. Covered by tests/Feature/BusinessHeadAccessTest.php.
