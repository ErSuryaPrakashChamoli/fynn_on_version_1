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

## Every hierarchy walk comes from ReportingTree direct-boss links
Supersedes the designation-by-designation walks (2026-09-15). ReportingTree::load() reads employees once and links each person to their direct boss: the nearest filled Employee::REPORTING_COLUMNS entry that points at THAT column's designation and sits above their own rank. Wrong-level columns are ignored — live data has callers with a Manager or even a Caller in superviser_id, and exited Manager #67's cluster_id holds a Manager. A caller's copied manager_id/cluster_id are never used to walk.

The two walks still differ: descendantIds(skipExitedLevels: true) for targets/incentives/target duty, false for visibility. Never cache the tree across calls (employees change inside one request or test); a load is ~1 ms for 250 rows. Do not add new where('manager_id'|'cluster_id', ...) walks elsewhere — go through HierarchyHelper/ReportingTree. branchRootId() (Team Continuity pool) never climbs past a Cluster Manager to a Business Head. Covered by tests/Feature/SkipLevelReportingTest.php.
