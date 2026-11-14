---
paths:
  - app/Support/HierarchyHelper.php
  - app/Support/ReportingTree.php
  - app/Support/ReportingTimeline.php
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

## Period figures walk the tree of each day (ReportingTimeline)
User decision 2026-11-14, superseding "reporting changes restate old months": if A reports to B until 25 Oct and to C from 26 Oct, months before stay with B, October is split, November is C's. ReportingTimeline rebuilds the tree for every day of a period from employee_reporting_history (joining row = line from the start of time; other rows = line from effective_date, the line before an employee's first non-joining row is its old_* columns; exit rows never move anyone; the LATEST line is always today's columns, so untracked edits still apply from the last recorded change). ReportingTree::load($date) = the tree that day; with a date, a level counts as exited only after its exit_date.
- Achievement/OTPs/stage counts/attendance: HierarchyHelper::scopeToBranchDuring() — each row counts for the team its owner was in on its date (disbursal_date, created_at, login_at). Don't add another whereBetween on that column.
- Target: AchievementCalculatorService::callerTargetShares() — a caller's month target (category/partial/0) × days in the branch within their on-rolls window ÷ window days; the ₹30L TL top-up likewise pro-rata to the TL's days in the branch.
- Headcount (TL caller count + tier, understaffed < 3, eligible callers/PPP): ReportingTimeline::headcountCallerIds() — every team the caller spent MIN_HEADCOUNT_DAYS (10)+ days in, so a mid-month move can count in both.
- Team listings for a month use childrenDuring()/directReporteesDuring(); rows pass $within (getPerformance/getTarget) so they show only the days spent in that team and add up to its total. PPP and incentive stay the person's own.
- Today-only walks (subordinateIds/children/visibleSubordinateIds, ReportingTree::load()) remain for record visibility and today's duties (target gate, escalations, delegations). Daily Commitment period reports still use today's team (not yet split by date).
With no change in the month every figure equals the old whole-month figure. Covered by tests/Feature/ReportingChangeMonthWiseTest.php.
