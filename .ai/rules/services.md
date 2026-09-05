---
paths:
  - app/Services/DailyCommitmentService.php
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
