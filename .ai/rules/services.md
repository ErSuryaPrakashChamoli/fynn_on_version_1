---
paths:
  - app/Services/DailyCommitmentService.php
  - app/Services/MonthlyTargetGate.php
  - app/Services/DailyCommitmentGate.php
---

# Services

## Daily Commitment achievement is declared, never inferred from the LMS
A day's achievement is ONLY the customer-wise fulfilment the employee submits for that day (daily_commitment_entries). Never derive a day's achievement by scanning `customers` for cases that currently sit at a stage — old business must not drift into today. An earlier version dated cases by `customers.updated_at` as a fallback, which credited any old case merely edited today.

A declared row counts at `effectiveStage()` = the better of the stage the employee entered and `lms_highest_stage` resolved server-side from `customer_stage_histories`. So Approved -> Rejected still counts as Approved. Dropped/Rejected are outcomes (rank() === null), never ladder rungs.

`pipeline()` is the separate, undated "standing book" figure (open cases only: not sanctioned/disbursed, not dropped, not rejected) and must never be added into a daily or MTD achievement.

Verified LMS stage mapping — do not guess these again:
- Docs Received is no longer a rung (removed 2026-09-08 — see the ladder rule in enums.md), so `documentation_status = 'complete'` maps to nothing and such a case resolves to no stage. Kept here only so the mapping is not "rediscovered" and wired back in: it was the checklist inside CustomerForm's "Step 1: SFL (Source File Logging)", NOT `customer_documents` (only ever holds post-disbursal "Disbursal Letter" rows) and NOT `documents_submitted` (also post-disbursal).
- SFL = `eligibility_status = 'eligible'`. CreateCustomer::mutateFormDataBeforeCreate() sets journey_status to 'sfl' when eligible and 'not_started' otherwise, and no 'Moved to Sfl' history row is ever written.
- Disbursed must NOT be detected via `disbursal_finalized`: CustomerJourneyService::sanction() sets it true for dropped cases too. Use journey_status='sanctioned' / disbursal_status='disbursed' / the 'Moved to Sanctioned' history row.

This module never reads or writes employees.category / employee_targets; it has its own monthly_commitment_targets table.

## Daily Commitment pipeline and periods are module-only
pipeline() is built ONLY from daily_commitment_entries joined to daily_commitments in the selected period — it never queries `customers`. A declared case leaves the pipeline when its effective stage is Disbursed or it carries a Dropped/Rejected outcome. Do not reintroduce an LMS-book pipeline: the figure must move only when a commitment is made and its fulfilment updated.

Periods come from DailyCommitmentService::rangeOptions()/resolveRange() (today, last 7 days, this month till date, last month, last 3/6/12 months, custom). This is the module's own list on purpose — SelectedMonth and PerformancePeriod belong to the Performance module and are not reused, so changing one cannot move the other.

Attendance: presentDays()/presence() count any UserLoginSession login row on a day. No screen-time threshold and no separate attendance table.

filterEmployeeIds() applies one hierarchy filter (caller/TL/manager/cluster) and then the optional `role` designation filter, both intersected against visibleEmployeeIds() — a filter can never widen what a user may see. With no role chosen, rows() returns employees in hierarchicalOrder(): cluster, its managers, each manager's team leaders, then each leader's callers.

Trap: `daily_commitments.date` uses the `date` cast, which writes "Y-m-d H:i:s". whereBetween with bare "Y-m-d" bounds compares as strings and silently drops matching rows (a single-day range returns nothing). Always use whereDate('date', '>=' / '<=') — see DailyCommitment::scopeForMonth and rows()/pipeline().

## Monthly commitment targets are mandatory and gate the whole panel
From the 1st of every calendar month the Daily Commitment module's monthly_commitment_targets rows do not exist yet, and MonthlyTargetGate closes the panel until they do.

Who owns whose target (responsibleFor()): a Manager owns their callers; the Admin line — role Admin/Business Head, plus a Cluster Manager inside their own branch — owns Managers and Team Leaders. A Team Leader or Caller owns nobody: they wait and are told who to chase. assignableEmployeeIds() is the wider "may set" list used for record-level writes; isTargetSetter() is seat-based (Admin/Business Head role, or designation Cluster/Manager) and is what MonthlyCommitmentTargetResource::canAccess() and the middleware landing page use — a Manager with an empty team must still reach the screen, so never gate access on the assignable list being non-empty.

Only employees who are still on the rolls AND have a user account are waited on (activeEmployees()). Demanding a target for a login-less row would deadlock whoever owns them.

Enforced in two places, both needed: App\Http\Middleware\EnsureMonthlyTargetIsSet (in the panel's authMiddleware) redirects blocked users away from every route except .auth.*, change-password, and either the Monthly Target resource (setters) or the dashboard (everyone else); App\Livewire\MonthlyTargetPrompt, hung on PanelsRenderHook::BODY_END, is the non-dismissible modal and re-authorises every write with canSetTargetFor().

Register the gate as a singleton (AppServiceProvider) — the middleware and the prompt both ask the same question per request and share its memo; call forget() after writing targets.

This is the module's own target only. It never touches employees.category / employee_targets or AchievementCalculatorService.

Trap: monthly_commitment_targets.month uses the `date` cast, so it writes "Y-m-d H:i:s". updateOrCreate(['month' => 'Y-m-d']) misses the existing row on SQLite and trips the (employee_id, month) unique index — look rows up with MonthlyCommitmentTarget::forMonth() (whereDate) instead.

## Commitment rollups are reported level by level, never as one blended total
A Manager's own commitment, their Team Leaders' and their Callers' must stay three separate figures; the same one level up for Admin. summariseByLevel() and monthlyRollupByLevel() group rows/employees by designation in DailyCommitmentService::LEVELS order (Cluster, Manager, Team Leader, Caller) and delegate the arithmetic to summarise()/monthlyRollup(), so a level can never disagree with the combined figure.

The dashboard, reports and team view lead with the by-level table (x-daily-commitment.level-summary / monthly-level-summary) and show the combined figure only as the labelled "All levels combined" footer row. Do not turn a headline back into a single mixed sum.

monthlyRollup() is the one place the MTD rollup lives — the dashboard and reports both call it rather than repeating the loop.

## An inactivity ticket skips the month's target; Admin may set anybody's
Nobody invents a target for somebody who has stopped turning up. A target setter raises an EmployeeInactivityRequest (from the blocking MonthlyTargetPrompt or the Inactivity Tickets resource) and skippedEmployeeIds() drops that employee out of missingTargets() and requiresOwnTarget() for that month.

A ticket counts from the moment it is raised (pending OR approved — see scopeSkipping), not once reviewed: waiting for the Admin would keep the whole team locked out of the panel. Rejecting puts the target back. Approving is what sets employees.exit_status = 'yes'. Tickets are per (employee, month) — look them up with EmployeeInactivityRequest::forMonth(), never a bare "Y-m-d" match, because the `month` cast writes "Y-m-d H:i:s".

assignableEmployeeIds() for Admin/Business Head is now every employee at any level (not just REQUIRES_TARGET, not filtered by exit status) — the Admin may correct anyone's target. responsibleFor() (the duty) is deliberately still narrow. Call forget() after any write.

## The daily gate must stand down while the monthly target gate is closed
Two panel-wide blocks exist in this module and they land users on different pages: EnsureMonthlyTargetIsSet sends blocked users to the Monthly Target resource (setters) or the dashboard, EnsureDailyCommitmentIsDeclared sends them to My Commitment. If both are in force the user bounces between the two landing pages forever, because neither middleware permits the other's target route.

DailyCommitmentGate::resolveStatus() therefore returns "clear" whenever MonthlyTargetGate::isBlocked() is true — the month's targets are the outer gate and always win. Do not remove that short-circuit, and if a third panel-wide block is ever added, decide its place in the same order first.

Deadlines are DailyCommitmentGate::MORNING_DEADLINE (09:50, commitment must exist) and EVENING_DEADLINE (18:30, it must be answered), both in app.timezone (Asia/Kolkata). An unanswered earlier day inside BACKLOG_DAYS blocks today too, and is reported with overdue=true. Register the gate as a singleton (AppServiceProvider) — the middleware, the prompt and the My Commitment banner all ask the same question per request and share its memo; call forget() after any commitment or declaration write.

## The daily prompt is the whole enforcement — never close or redirect routes
Supersedes the earlier note about EnsureDailyCommitmentIsDeclared, which has been DELETED. That middleware redirected every panel route to My Commitment past a deadline, and it was wrong: a commitment module has no business stopping work in the rest of the LMS. Do not reintroduce it or any route-level block for the daily deadlines.

Enforcement is App\Livewire\DailyCommitmentPrompt alone — a non-dismissible modal on PanelsRenderHook::BODY_END that takes BOTH answers in place: the 09:50 stage-and-number, and the 18:30 declaration (either the cases that make up the day, or a zero against every rung). Answering it calls DailyCommitmentGate::forget(); the component's own re-render then finds the question answered and the modal disappears with no redirect and no page reload, leaving the employee exactly where they were. Never make clear() redirect.

The prompt hides itself on My Commitment (route name check) — that page is the fuller version of the same two steps.

The monthly-target gate DOES still close the panel, and DailyCommitmentGate stands down while it is blocking so two prompts never stack.

Three places write fulfilment — My Commitment, the prompt, and the Admin's editAchievement action — and all three go through DailyCommitmentService::reasonMobilesCannotBeSaved() then replaceFulfilment(). Validate before calling replaceFulfilment: it rebuilds by delete-then-insert.

Trap: Filament's ->callAction() in tests MERGES passed data into the fillForm() defaults, so repeater rows APPEND rather than replace. A test that "corrects" an existing row ends up sending both rows and trips the duplicate-mobile guard. Test admin corrections against a commitment with no entries, or assert the guard.
