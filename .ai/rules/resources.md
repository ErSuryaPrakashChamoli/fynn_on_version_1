---
paths:
  - 'app/Filament/Resources/**'
---

# Resources

## Journey oversight screens are branch-scoped; take-overs stay cross-branch
Since 2026-09-15: PendingManagerCaseResource, CustomerSlaBreachResource and CustomerReassignmentResource override getEloquentQuery() — Admin sees all; everyone else sees customers owned inside their branch (customers.assign_to in HierarchyHelper::visibleSubordinateIds), plus SLA breaches escalated to them and reassignments where either owner is in the branch. CustomerReassignmentsTable's customer / new-owner / bulk-manager pickers are branch-limited for non-Admins and both actions re-check the branch server-side before calling CustomerReassignmentService (the service itself stays unscoped — tests call it directly).

The Emergency Takeover SCREEN is branch-limited too (2026-09-16): JourneyTakeoversTable's customer picker lists only the viewer's branch and the action re-checks it before calling the service. JourneyTakeoverService itself stays unscoped on purpose — EmergencyTakeoverTest and JourneyConcurrencyTest call it directly and assert a Cluster Manager can take over a customer from an unrelated branch. UserResource::canAccess() is Admin/IT only (the nav check alone left /admin/users open by URL). TeamsTable's "Cluster Manager" filter binds the `cluster` relationship (it was wrongly bound to `superviser`). Covered by tests/Feature/BranchScopedOversightTest.php.
