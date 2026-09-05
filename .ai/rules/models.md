---
paths:
  - app/Models/FollowUp.php
---

# Models

## Only the newest follow-up per prospect is current — always scope with latestPerSubject()
Every follow-up interaction INSERTS a new follow_ups row (EditAssignedLead::afterSave, Lead::logFollowUp on created+updated, the FollowUp create page). Older rows keep the next_follow_up_date they were saved with, so any query that lists "who is due when" must call ->latestPerSubject()->scheduled() or the same prospect appears once per superseded date. That was the cause of customers showing on two or three calendar days at once.

latestPerSubject() picks MAX(id) grouped by the three SUBJECT_COLUMNS (customer_id, ai_customer_record_id, lead_id); scheduled() drops rows with no next_follow_up_date. The superseded rows are deliberately kept — they are the follow-up log, read via historyForSubject() (one prospect) or historiesFor() (a whole listing, one query).

follow_up_date is dead: it only ever held the row's creation date. Nothing reads it, both columns are nullable and retained only so historically backdated leads.follow_up_date values and existing lead-import CSVs survive. Date a follow-up by created_at.
