---
paths:
  - app/Services/DailyCommitmentService.php
  - app/Services/MonthlyTargetGate.php
---

# Services

## Daily Commitment achievement is declared, never inferred from the LMS
A day's achievement is ONLY the customer-wise fulfilment the employee submits for that day (daily_commitment_entries). Never derive a day's achievement by scanning `customers` for cases that currently sit at a stage — old business must not drift into today. An earlier version dated cases by `customers.updated_at` as a fallback, which credited any old case merely edited today.

A declared row counts at `effectiveStage()` = the better of the stage the employee entered and `lms_highest_stage` resolved server-side from `customer_stage_histories`. So Approved -> Rejected still counts as Approved. Dropped/Rejected are outcomes (rank() === null), never ladder rungs.

`pipeline()` is the separate, undated "standing book" figure (open cases only: not sanctioned/disbursed, not dropped, not rejected) and must never be added into a daily or MTD achievement.

Verified LMS stage mapping — do not guess these again:
- Docs Received = `customers.documentation_status = 'complete'`, the checklist inside CustomerForm's "Step 1: SFL (Source File Logging)". NOT `customer_documents` (only ever holds post-disbursal "Disbursal Letter" rows) and NOT `documents_submitted` (also post-disbursal).
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
