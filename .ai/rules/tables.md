---
paths:
  - 'app/Filament/Resources/**/Tables/*.php'
---

# Tables

## Case-owner columns and sortable headers on listings
Case-centric listings (Customers, Leads, Assigned Leads, Follow Ups, Pending Manager Cases, PAN Requests, ...) show who owns the case: an `employee.emp_name` column with the emp_id as its ->description(), plus a toggleable `employee.emp_id` column. Employee dropdown options come from App\Support\EmployeeOptions (forDesignation/visibleTo/label), which formats labels as "Name (EMP-0001)".

Every header that maps to a real column or a single-level relationship attribute is ->sortable(). Columns built from ->state() closures need ->sortable(query: ...) with equivalent SQL, or are left unsortable when the value is computed in PHP (per-row performance metrics, SLA status).

Trap: the test suite runs on SQLite, which treats an unknown double-quoted identifier in ORDER BY as a string literal, so a sortable header pointing at a non-existent column passes there but fails on MySQL. tests/Feature/ListingSortingAndSearchableFiltersTest.php compensates with a Schema::hasColumn check per plain sortable column.

## Row-action ActionGroups open bottom-start
The row actions bar is docked at the row's LEFT edge (theme.css "Table row contextual actions" + table-row-actions.js), but Filament defaults record-action groups to bottom-end, which opens the menu leftwards under the sidebar. Give every `ActionGroup` in ->recordActions() `->dropdownPlacement('bottom-start')` (Cashback Forms, Complaints, Polls). Covered by CustomerCashbackFormTest::test_the_row_actions_menu_opens_to_the_right_of_the_docked_actions_bar.
