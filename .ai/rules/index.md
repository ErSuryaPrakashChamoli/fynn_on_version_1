# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Filament/Academy/** | .ai/rules/academy.md |
| resources/js/listing-sticky-header.js, resources/css/filament/admin/theme.css, resources/js/sidebar-hover-expand.js, resources/css/filament/admin/theme.css | .ai/rules/admin.md |
| app/Filament/Actions/AssignCustomersToUserBulkAction.php, app/Filament/Resources/AiCustomerRecords/**, app/Services/CustomerAssignmentService.php, app/Support/AiRecordSerialRanges.php, app/Filament/Exports/AiCustomerRecordExporter.php | .ai/rules/ai-customer-records-services.md |
| app/Filament/Resources/AiCustomerRecords/** | .ai/rules/ai-customer-records.md |
| app/Livewire/** | .ai/rules/app-livewire.md |
| app/**/Customer*.php | .ai/rules/app.md |
| app/Services/CustomerCashbackService.php, app/Console/Commands/EscalateOverdueCashbackForms.php, app/Models/CustomerCashbackRequest.php, app/Filament/Resources/CustomerCashbackRequests/**, app/Filament/Resources/Customers/Schemas/CustomerCashbackSection.php, app/Enums/Cashback*.php, app/Services/CustomerJourneyService.php | .ai/rules/cashback.md |
| app/Services/Cibil/**, app/Filament/Resources/CibilReports/**, app/Filament/Resources/CibilRequests/**, app/Filament/Pages/Cibil*.php, app/Support/Cibil*.php, app/Models/CibilReport.php, app/Models/CibilSetting.php, config/cibil.php | .ai/rules/cibil.md |
| app/Filament/**, app/Support/ColumnHeaderFilters/**, app/Enums/ColumnHeaderFilterKind.php, resources/js/column-header-filter.js, resources/css/column-header-filters.css, resources/views/filament/components/column-header-filter*.blade.php | .ai/rules/column-header-filters.md |
| resources/views/components/daily-commitment/** | .ai/rules/components-daily-commitment.md |
| resources/css/filament/admin/theme.css, resources/views/filament/components/table-scroll-to-top.blade.php, resources/js/table-scroll-nav.js | .ai/rules/components-js.md |
| app/Services/CustomerEditRequestService.php, app/Support/CustomerEditableFields.php, app/Filament/Resources/CustomerEditRequests/** | .ai/rules/customer-edit-requests.md |
| app/Filament/Resources/Customers/** | .ai/rules/customers.md |
| resources/views/**/daily-commitment/** | .ai/rules/daily-commitment.md |
| database/seeders/DemoEnvironment/** | .ai/rules/demo-environment.md |
| app/Support/Demo/**, app/Models/Demo/**, app/Http/Middleware/Portal/*Demo*, app/Providers/Filament/DemoPanelProvider.php, app/Providers/Filament/AdminPanelProvider.php, database/seeders/Demo/**, config/demo.php | .ai/rules/demo.md |
| app/Filament/Resources/Employees/**, app/Services/EmployeeInactivationService.php, app/Services/EmployeeDependencyService.php, app/Services/EmployeeTransferService.php, app/Filament/Actions/InactivateEmployeeAction.php, app/Filament/Actions/TransferEmployeeAction.php, app/Filament/Actions/EmployeeDependencyStep.php, app/Filament/Actions/EmployeeOptionActivationActions.php, app/Models/Concerns/HasEffectiveInactivation.php, app/Filament/Actions/ChangeEmployeePositionAction.php, app/Services/EmployeePositionChangeService.php, app/Support/TargetCategoryTimeline.php | .ai/rules/employees.md |
| app/Enums/CommitmentStage.php, app/Enums/CommitmentResult.php | .ai/rules/enums.md |
| app/Providers/Filament/AdminPanelProvider.php, resources/views/livewire/⚡top-performer-marquee.blade.php, resources/css/filament/admin/theme.css | .ai/rules/filament-admin.md |
| app/Providers/Filament/AdminPanelProvider.php, resources/views/vendor/filament-panels/components/sidebar/**, resources/js/sidebar-hover-expand.js, app/Filament/Resources/**/*Resource.php, app/Filament/Pages/*.php | .ai/rules/filament-pages.md |
| resources/views/filament/widgets/dashboard-follow-up-calendar-widget.blade.php | .ai/rules/filament-widgets.md |
| app/Filament/** | .ai/rules/filament.md |
| app/Services/HelpDesk/** | .ai/rules/help-desk.md |
| app/Filament/**, app/Providers/AppServiceProvider.php, app/Helpers/helpers.php | .ai/rules/helpers.md |
| app/Support/SelectedMonth.php, resources/views/filament/components/global-month-selector.blade.php, app/Http/Middleware/EncryptCookies.php | .ai/rules/http-middleware.md |
| resources/js/**, resources/js/login-session-heartbeat.js | .ai/rules/js.md |
| app/Services/CustomerAssignmentService.php, app/Filament/Resources/AssignedLeads/**, app/Filament/Resources/LeadAssignmentReports/**, app/Support/LeadAssignmentFilters.php | .ai/rules/lead-assignment-reports-support.md |
| app/Services/Voting/**, app/Filament/Resources/PollTypes/**, app/Filament/Resources/Polls/**, app/Livewire/PollPrompt.php, app/Filament/Pages/MyVotes.php | .ai/rules/livewire-filament-pages.md |
| resources/views/livewire/** | .ai/rules/livewire.md |
| app/Http/Middleware/EnsureAccountIsActive.php, app/Http/Middleware/EnforceIdleTimeout.php | .ai/rules/middleware.md |
| app/Models/FollowUp.php, app/Models/DailyCommitmentEntry.php, app/Models/User.php, app/Models/UserLoginSession.php, app/Models/Employee.php | .ai/rules/models.md |
| python/ocr/** | .ai/rules/ocr.md |
| app/Services/Voting/**, app/Filament/Resources/Polls/**, app/Filament/Resources/PollTypes/**, app/Filament/Pages/MyVotes.php, app/Livewire/PollPrompt.php | .ai/rules/pages-livewire.md |
| app/Filament/Pages/DailyCommitment*.php | .ai/rules/pages.md |
| app/Providers/Filament/AdminPanelProvider.php | .ai/rules/providers-filament.md |
| app/Filament/Resources/** | .ai/rules/resources.md |
| app/Services/DailyCommitmentService.php, app/Services/MonthlyTargetGate.php, app/Services/DailyCommitmentGate.php, app/Services/AchievementCalculatorService.php, app/Services/OtherBankSupportService.php, app/Services/FollowUpReminderService.php, app/Services/FollowUpMonitorService.php, app/Services/FollowUpEscalationService.php | .ai/rules/services.md |
| app/Support/ItModuleAccess.php, app/Http/Middleware/RestrictItRoleToAllowedModules.php, app/Providers/Filament/AdminPanelProvider.php, app/Filament/Pages/EmployeeHierarchy.php, app/Support/HierarchyHelper.php, app/Models/User.php | .ai/rules/support-models.md |
| app/Support/HierarchyHelper.php, app/Support/ReportingTree.php, app/Support/ReportingTimeline.php | .ai/rules/support.md |
| app/Filament/Resources/**/Tables/*.php | .ai/rules/tables.md |
| app/Services/AchievementCalculatorService.php, app/Services/IncentiveCalculator.php, app/Filament/Widgets/IncentiveStats.php | .ai/rules/widgets.md |
| app/Services/PanDuplicateService.php, app/Rules/PanIsAvailable.php, app/Support/PanMatch.php, app/Models/CustomerPanRequest.php, app/Filament/Resources/Leads/**, app/Filament/Resources/Customers/**, app/Filament/Resources/CustomerPanRequests/**, app/Filament/Resources/AssignedLeads/**, app/Filament/Imports/**, app/Services/CustomerEditRequestService.php | .ai/rules/pan-duplicates.md |
