---
paths:
  - app/Support/HierarchyHelper.php
---

# Support

## Two hierarchy walks: visibleSubordinateIds() sees, subordinateIds() counts
These are deliberately different questions — do not merge them.

- visibleSubordinateIds() (added 2026-09-10) walks the tree WITHOUT dropping an exited Team Leader / Manager. Marking somebody inactive only sets employees.exit_status; it never reassigns their customers, leads or follow-ups. The old walk skipped an exited level, which took the still-working callers under them out of their Manager's and Cluster Manager's sight and left those live cases visible to nobody but the Admin. Use it for record visibility only: CustomerResource::getEloquentQuery(), FollowUpResource, CustomerJourneyAccessService::hasNormalAccess().
- subordinateIds()/callerIds() keep the `exit_status != 'yes'` filters on the intermediate levels and stay the walk for target, incentive and target-setting maths (AchievementCalculatorService, MonthlyTargetGate) — an exited Team Leader's understaffed team must not top up their Manager's target.

tests/Feature/ExitedEmployeeRecordVisibilityTest.php asserts both halves, including that the two walks disagree.

Leads/Assigned Leads use HierarchyService::visibleEmployeeIds(), which never filtered on exit status and needs no change. Note it also gives a Manager their DIRECT callers (manager_id), which neither HierarchyHelper walk does — a caller reporting straight to a Manager is visible in Leads but not in Customers.
