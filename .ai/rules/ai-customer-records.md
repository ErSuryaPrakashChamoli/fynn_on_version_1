---
paths:
  - 'app/Filament/Resources/AiCustomerRecords/**'
---

# Ai Customer Records

## Customer Data listing: "assigned" is a display status, not a stored one
Since 2026-09-18 AiCustomerRecordsTable shows Status = "assigned" (STATUS_ASSIGNED) for any record with a customer_assignments row, via AiCustomerRecord::latestAssignment(). The ai_customer_records.status column keeps its review/approved/rejected value — never write 'assigned' into it. The Status filter mirrors the badge: "Assigned" = has an assignment, every other option = that status AND no assignment. The Assignment ternary and "Assigned To" employee filter split the list the same way.

Trap: the 2026_08_22_140000 migration made customer_assignments.customer_id nullable via a MySQL-only ALTER; the SQLite branch was added on 2026-09-18 so tests can create AI-record assignments (no customer_id). Covered by tests/Feature/AiCustomerRecordAssignmentListingTest.php.
