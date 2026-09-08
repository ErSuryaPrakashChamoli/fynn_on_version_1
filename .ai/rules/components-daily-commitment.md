---
paths:
  - 'resources/views/components/daily-commitment/**'
---

# Components Daily Commitment

## Never show a cross-level total in the Daily Commitment module
A Manager's commitment/target already covers the Team Leaders and Callers under them, so adding the Managers', Team Leaders' and Callers' figures together counts the same business two and three times over. On the live data that produced a headline "monthly target" of ₹81,48,78,009 that nobody was answerable for.

level-summary and monthly-level-summary therefore render one row per designation and have NO grand-total row and no `total` prop. Do not add one back, and do not reintroduce a blended money percentage or progress bar (`summarise()['percentage']` across mixed levels is the same fake number in another shape).

Headcounts are the exception and may still be summed across levels — committed/people, met, failed, present/absent, OTP counts. Money may not.

`summarise()` and `monthlyRollup()` still return combined figures; they are used for people counts and working-day counts only. tests/Feature/DailyCommitmentPagesTest::test_the_dashboard_bifurcates_monthly_targets_and_never_shows_their_sum locks this.

## Commitment history tallies the whole range, filters only narrow the table
x-daily-commitment.history renders one row per day (promise vs fulfilment) plus the headline tally, and is shared by MyDailyCommitment and DailyCommitmentDetail. Both pages declare the same four public properties it binds to — historyRange, historyFrom, historyTo, historyResult — and a getHistoryProperty() returning ['rows', 'filtered', 'tally'].

The tally is ALWAYS computed over the unfiltered rows and only `filtered` feeds the table: narrowing to "Failed" must not make the headline read "0 met". Keep that split if you touch either page.

kept = Met + Overachieved, over CLOSED days only. A day still in progress is neither kept nor missed, so it is excluded from the denominator rather than counted as a miss — otherwise everyone's rate drops every morning.

Periods come from DailyCommitmentService::rangeOptions()/resolveRange() (the module's own list, deliberately not SelectedMonth). Results come from CommitmentResult::options() plus an 'all' sentinel, so Partial appears automatically.

DailyCommitmentDetail keeps its separate "Change log" section — that is the audit trail of ONE commitment (who edited what, admin corrections). The history is a different question and does not replace it.
