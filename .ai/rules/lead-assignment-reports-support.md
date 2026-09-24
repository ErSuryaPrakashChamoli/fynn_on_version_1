---
paths:
  - 'app/Services/CustomerAssignmentService.php, app/Filament/Resources/AssignedLeads/**, app/Filament/Resources/LeadAssignmentReports/**, app/Support/LeadAssignmentFilters.php'
---

# Lead Assignment Reports Support

## Assigned leads: template snapshot, reassign via service, shared lead scope
Since 2026-09-24 customer_assignments stores ai_document_schema_id (template, set by CustomerAssignmentService::assign), converted_at (set in CreateCustomer::afterCreate) and reassign_count/last_reassigned_at. Converting nulls ai_customer_record_id, so never derive the template or "converted" from that column. Reassign only through CustomerAssignmentService::reassign() — it logs a customer_assignment_transfers row and resets opens_count for the new owner; a lead can move any number of times. The Assigned Leads listing and the Lead Assignment report share App\Support\LeadAssignmentFilters: the "Assigned On" filter replaces the topbar month when set, and the report applies template/assigned-by inside its per-employee withCount (the filters are no-ops on the employee query). Latest-remark filters and counts use CustomerAssignment::latestFollowUpValueQuery()/scopeWhereLatestFollowUpStatus(), where "Pending" also matches leads with no follow-up yet. Covered by tests/Feature/AssignedLeadReassignAndReportTest.php.
