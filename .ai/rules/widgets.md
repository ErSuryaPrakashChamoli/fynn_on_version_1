---
paths:
  - 'app/Services/AchievementCalculatorService.php, app/Services/IncentiveCalculator.php, app/Filament/Widgets/IncentiveStats.php'
---

# Widgets

## Deductions are reported in two parts: BFL Prime/Growth/SOL vs every other sanctioning bank
User decision 2026-09-27: cashback, subvention and docking each split by customers.sanctioned_bank into AchievementCalculatorService::IN_HOUSE_SPLIT_BANKS (BFL Prime, BFL Growth, BFL SOL — NOT RSL, which counts as "other", as does a blank bank) and the rest, because the policies differ. achievementQuery() adds cashback_bfl/subvention_bfl/docking_bfl sums (same case/space/hyphen normalisation as the half-deduction check; bindings are ordered split×3 then HALF_DEDUCTION_BANKS — keep that order if the raw SQL changes); computeAchievementTotals()/getPerformance()/IncentiveCalculator carry *_bfl and *_other keys, each pair summing to the total. This is display-only: count_achievement and the half/full deduction formula are untouched. IncentiveStats renders the three cards' values as an HtmlString (.incentive-split in theme.css) with the total in the description — same cards, no new card. Covered by tests/Feature/IncentiveDeductionSplitTest.php.

## Incentive scheme by designation: Cluster Manager is paid like a Manager
User decision 2026-11-16: Caller (and anyone not listed below) → flat slab (getIncentiveSlabs); Team Leader → revenue share (getTeamLeaderIncentiveBreakdown); Manager AND Cluster Manager → PPP multiplier (getManagerIncentiveBreakdown, over the whole branch) — AchievementCalculatorService::MANAGER_SCHEME_DESIGNATIONS. Cluster used to fall through to the caller slab, so any branch over ₹1.1 Cr got the capped ₹70,000 (Employee Lookup ME0045). incentiveSchemeFor() is the ONE designation→scheme map and getIncentiveFor() the one dispatcher. getPerformance(), EmployeeFigureBreakdownService (cards + "How is this calculated?") and ManagerPPPStats all go through them; never re-add a local match on designation. The scheme follows designationFor() (designation held on the month's last day). Business Head is not mapped and still gets the slab: that has not been decided. Covered by tests/Feature/AchievementCalculatorManagerIncentiveTest.php.
