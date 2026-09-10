---
paths:
  - app/Models/FollowUp.php
  - app/Models/DailyCommitmentEntry.php
  - app/Models/User.php
  - app/Models/UserLoginSession.php
---

# Models

## Only the newest follow-up per prospect is current — always scope with latestPerSubject()
Every follow-up interaction INSERTS a new follow_ups row (EditAssignedLead::afterSave, Lead::logFollowUp on created+updated, the FollowUp create page). Older rows keep the next_follow_up_date they were saved with, so any query that lists "who is due when" must call ->latestPerSubject()->scheduled() or the same prospect appears once per superseded date. That was the cause of customers showing on two or three calendar days at once.

latestPerSubject() picks MAX(id) grouped by the three SUBJECT_COLUMNS (customer_id, ai_customer_record_id, lead_id); scheduled() drops rows with no next_follow_up_date. The superseded rows are deliberately kept — they are the follow-up log, read via historyForSubject() (one prospect) or historiesFor() (a whole listing, one query).

follow_up_date is dead: it only ever held the row's creation date. Nothing reads it, both columns are nullable and retained only so historically backdated leads.follow_up_date values and existing lead-import CSVs survive. Date a follow-up by created_at.

## A mobile number is a declared case's identity and can only be claimed once
Every row in daily_commitment_entries must carry mobile_no, and the column has a UNIQUE index across the whole table — not per employee and not per day. A customer counted on any commitment can never be counted again, by anyone, on any later commitment. A nil day (declareNothing) declares no cases, so it asks for no number.

Always store and compare via DailyCommitmentEntry::normaliseMobile(): digits only, trimmed to the last 10, so "+91 98765 43210" / "09876543210" / "9876543210" are one customer rather than three. DailyCommitmentEntry::claimFor($mobile, $ignoreCommitmentId) is the single lookup — pass the current commitment id so re-saving your own day is not a clash with itself (persistFulfilment rebuilds rows by delete-then-insert).

Enforced in three places, all needed: the field's ->rule() for live feedback, MyDailyCommitment::mobilesAreUsable() server-side (the field rule cannot see sibling rows, and a crafted Livewire request never runs it), and the DB unique index as the backstop. mobilesAreUsable() runs BEFORE entries()->delete() — validating after the delete would take the day's work with it.

Consequence to know: a case declared at SFL cannot be re-declared later at Approval, so its progression never counts on the later day. That is the intended anti-double-counting rule, not an oversight.

searchCustomers() hides already-claimed customers, filtering in PHP — no portable SQL normalises both sides, and the test suite runs on SQLite (no REGEXP_REPLACE).

## canAccessPanel() is the portal security boundary — do not loosen it
User::canAccessPanel() used to `return true` unconditionally. It is now the load-bearing check that keeps Academy/Demo users out of /admin, and Filament also applies it to every Livewire round-trip via its persistent Authenticate middleware.

Rules:
- A user with NO portal_accounts row is an ordinary LMS user and keeps admin access exactly as before. Never change that branch.
- A user WITH one is pinned to that one panel and must never be granted another, regardless of Spatie roles.
- Portal users are created only via App\Services\Portal\PortalAccountService, which calls syncRoles([]) so they hold no production role.

tests/Feature/Portal/AdminPanelIsolationTest.php asserts all of this.

## last_seen_at is screen time; last_activity_at is idleness — never conflate them
Two different questions, two columns:
- last_seen_at: last time the heartbeat counted screen time. Advances whenever the TAB IS VISIBLE, so it keeps moving for a tab left open on an unattended desk. Never use it to decide idleness.
- last_activity_at (added 2026-09-10): last GENUINE interaction — key, pointer, touch, scroll, or a real panel page request. This is what idle logout measures.

Idle logout after config('session.idle_timeout') minutes (0 disables) is enforced in three places, all needed:
- EnforceIdleTimeout in AdminPanelProvider->authMiddleware() is the authority; it also refreshes last_activity_at, since loading a page is an interaction.
- resources/js/login-session-heartbeat.js is UX only (warning banner + clean POST logout). Edit it and you MUST run `php artisan filament:assets` — see js.md.
- `sessions:close-idle` (scheduled every 5 min) closes rows for browsers that were simply shut, stamping logout_at at lastInteraction + timeout, NOT at sweep time, so session duration is not inflated.

All three write logout_reason = 'session_timeout', the value the login log already filters and colours on.

Traps:
- Livewire's update endpoint does not carry panel authMiddleware, so widget polling never refreshes the idle clock. That is deliberate.
- is_active is a derived accessor, not a column. Ordering a table by it throws "Unknown column" — sort by last_activity_at instead.
