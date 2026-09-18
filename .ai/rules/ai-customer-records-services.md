---
paths:
  - 'app/Filament/Actions/AssignCustomersToUserBulkAction.php, app/Filament/Resources/AiCustomerRecords/**, app/Services/CustomerAssignmentService.php'
---

# Ai Customer Records Services

## Assign customers/AI records only through CustomerAssignmentService
Since 2026-09-18 every hand-over (customer_assignment_batches + customer_assignments rows) goes through App\Services\CustomerAssignmentService::assign(ids, employeeId, targetType, assignedBy). It dedupes ids, skips targets that already have an assignment, wraps the batch + rows in one transaction and returns ['assigned','skipped','batch']. Callers: AssignCustomersToUserBulkAction (Customers + Customer Data bulk "Assign to User") and the Customer Data header action "Assign by S. No." (AiCustomerRecordsTable::assignBySerialNumberAction, Admin-only, whereBetween on ai_customer_records.id — the "#" column). Do not create batches/assignments inline in a new action; extend the service instead. Covered by tests/Feature/AiCustomerRecordAssignBySerialNumberTest.php.
