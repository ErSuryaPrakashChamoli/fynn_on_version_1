---
paths:
  - app/Enums/CommitmentStage.php
  - app/Enums/CommitmentResult.php
---

# Enums

## Approval/Disbursal is a label-only rename, and Disbursal is the default stage
The Daily Commitment module calls the top two rungs "Approval" and "Disbursal". This lives only in CommitmentStage::label() — the enum cases and stored values stay Approved/'approved' and Disbursed/'disbursed', as do the LMS journey statuses and stage-history strings. Do not rename the cases or the column values.

CommitmentStage::default() is Disbursed: every target and commitment form in the module (Monthly Target resource, MonthlyTargetPrompt rows and its "same for everyone" row, My Commitment, the Admin correction and set-monthly-target modals) starts there unless a record already says otherwise.

## A commitment can be fulfilled in parts — achievement is split at the committed stage
₹10L promised at Approval may come back as ₹7L Approval + ₹3L SFL. That is PARTIALLY MET (CommitmentResult::Partial), not a pass and not a failure.

achievementFromEntries() splits declared rows at the committed stage: `amount` is business at or above it (the only figure that earns a clean pass, and what every rollup in the module has always meant by "achievement" — its meaning did not change), `below_amount` is the rest. Both are snapshotted on daily_commitments as achievement_amount / below_stage_amount.

CommitmentResult::decide($target, $achieved, $dayClosed, $totalAchieved) grades on both: over target at stage = Overachieved, at target at stage = Met, short at stage but total >= target = Partial, otherwise Failed. Partial only ever appears once the day is closed — while it is running anything short stays In Progress. Pass $totalAchieved as null/equal where there is no ladder to fall short on (OTP count commitments).

`pending` stays the gap AT the committed stage; below-stage money softens the result but never closes the gap. 'partial' is a headcount in summarise() and may be summed across levels — money still may not.

## The ladder is OTP → SFL → Underwriting → Approval → Disbursal
Supersedes the earlier note that Docs Received is rung 1 and OTP sits off the ladder. Both changed on 2026-09-08 at the business owner's instruction.

Docs Received is GONE — enum case removed, and highestStageFor() no longer maps documentation_status = 'complete' to anything, so docs-complete-but-not-eligible cases resolve to no stage and cannot be claimed as achievement or partial credit. SFL (eligibility_status = 'eligible') is the lowest rung the LMS can prove. A migration lifted every stored 'docs_received' to 'sfl' across daily_commitments, daily_commitment_entries, monthly_commitment_targets and daily_commitment_logs; do not reintroduce the value.

OTP is now rank 1, the bottom rung, and commitable() is just ladder(). Consequence: an OTP commitment is settled by the cases the employee DECLARES (1 per case, every ladder rung counts toward it), not by otpCounts() any more. otpCounts()/expectedOtps() survive only as the separate `actual_otp` / `expected_otp` reporting columns — never wire them back into a commitment's achievement.

isCount() is about the UNIT, not the ladder: OTP is still a headcount and must never be summed into a rupee total. It has no rung below it, so an OTP commitment can never be Partial.

Approval/Disbursal remain label-only renames of Approved/Disbursed — the cases and stored values stay, as do the LMS journey statuses and stage-history strings. CommitmentStage::default() is still Disbursed.
