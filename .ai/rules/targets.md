---
paths:
  - 'app/Support/TargetLedger.php'
  - 'app/Services/AchievementCalculatorService.php'
  - 'app/Support/ReportingTree.php'
  - 'app/Models/TargetCategory.php'
  - 'app/Models/TargetCategoryAmount.php'
  - 'app/Filament/Resources/TargetCategories/**'
  - 'app/Filament/Resources/Employees/Widgets/TargetDataWarnings.php'
  - 'app/Services/Performance/EmployeeFigureBreakdownService.php'
---

# Targets

## Every LMS target comes from TargetLedger — one month, one pass
Consolidated with the user on 2026-11-24 (21 decisions), superseding every earlier target rule (flat ₹15L PARTIAL_MONTH_TARGET, ₹30L understaffed top-up for < 3 callers, full target in every team a mover spent 10 days in, company = callers without top-ups, ₹25L default for a blank category). App\Support\TargetLedger::forMonth() works out a whole calendar month; AchievementCalculatorService::getTarget()/getTargetForPeriod()/getHierarchyCallerTarget(), the TL incentive, Employee Lookup and the Teams screens all read it. Never re-derive a target anywhere else.

- Position = the one held on the month's last day (designationOn). Admin and rank-0 positions carry nothing.
- Caller OWN target (their screen, getTarget without $within) = category amount for any month on the rolls, joining/exit ignored; a category change inside the month splits it by days. TEAM target (what reaches bosses) = that same amount once on the rolls MIN_TARGET_DAYS (10) CALENDAR days, else 0 — a joiner still on the rolls is projected to month-end (OnRollsWindow). The two numbers are deliberately different (user choice).
- Each day on the rolls in the month's position, a person sits under ReportingTree::countBossId() (nearest boss on their OWN record still on the rolls, whose month position is above theirs); none → TargetLedger::COMPANY.
- Split: bosses with 10+ such days share the target by days; bosses under 10 get 0; if none reached 10, all share by days. So levels always add up (contributions + floorContributions = target).
- Team Leader = max(what reached them, TEAM_LEADER_MIN_TARGET ₹80L) when in the seat 10+ days on the rolls (whatever the headcount, even 0 callers); otherwise just what reached them. A leader the tree has as exited that day (incl. exit with no date) gets no days.
- Manager / Cluster / Business Head = exactly what reached them, no floor. Company = everything that reached the top (branches + brokenLineIds()).
- TL incentive (getTeamLeaderIncentiveBreakdown) uses the TL's own ledger target, ₹80L included, for the gate and the 5% over-target bonus; per-caller "met target" uses ledger contributions.
- A caller with no category, or one whose amount is null/≤ ₹1 (NIL placeholder, Alpha/Beta/Delta), counts 0 and is listed by missingCategoryIds(). Admin sees both lists on the Employees page (TargetDataWarnings widget, lazy, current month).
- Applies to every month, past included (user choice) — there is no old-rule path to keep.
- 10 days and ₹80L are code constants on TargetLedger (user choice), not settings.
Covered by tests/Feature/TargetRulesConsolidationTest.php (one test per decision) plus ReportingChangeMonthWiseTest, SkipLevelReportingTest.

## The counting walk climbs past exited bosses; the visibility walk does not
ReportingTree nodes carry boss_id (direct boss on record, exited or not) and count_boss_id (nearest active boss on the employee's own reporting columns that day). childIds/descendantIds with $skipExitedLevels follow count_boss_id and leave exited non-caller levels out — so a caller whose Team Leader left counts with the Manager their record names, for targets, achievement, headcount and target duty alike. Without $skipExitedLevels the direct links are walked (record visibility). An employee with nobody active on their record hangs off no one (company total only). Live data: ~15 such people a month — fix their reporting line, don't special-case them.

## Target category amounts are dated by month
target_category_amounts (target_category_id, effective_from 'Y-m-d' 1st of month — stored as a plain string, never the `date` cast, amount nullable). TargetCategory writes a row on create (FROM_THE_START '2000-01-01') and whenever target_amount changes (from amountEffectiveFrom, else the current month); targetAmountFor($code, $month) reads the amount in force that month (falls back to target_amount, then a numeric code, for categories with no rows). The Target Categories edit pop-up asks "New amount applies from" (current month to +12) — a closed month never changes. ReportingSnapshot::TABLES includes both category tables, so the ledger and the amounts memo retire on any write.

## Only a caller carries a Target Category
Employee form and Change Position / Target show and require the category only for a Caller, offering TargetCategory::callerOptionsIncluding() (categories with a real amount). EmployeePositionChangeService keeps a non-caller's category on record untouched (no dated row), so a TL "category-only" change is refused as "nothing would change". EmployeeImporter has a target_category column (name or code): required for a NEW caller, refused when it would change an existing employee's category (that must be dated via Change Position / Target); the download includes it.
