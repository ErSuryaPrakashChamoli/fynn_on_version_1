---
paths:
  - 'app/Support/ItModuleAccess.php, app/Http/Middleware/RestrictItRoleToAllowedModules.php, app/Providers/Filament/AdminPanelProvider.php, app/Filament/Pages/EmployeeHierarchy.php, app/Support/HierarchyHelper.php, app/Models/User.php'
---

# Support Models

## The IT role sees only the Reporting Hierarchy (company-wide) and the Help Desk
Since 2026-09-26 a login with role IT (and not Admin — User::isRestrictedToItModules()) gets exactly two modules: EmployeeHierarchy, where it sees everyone in the company (HierarchyHelper::ownHierarchyIds treats IT like Admin), and ComplaintResource tickets. Everything else is off. The single source is App\Support\ItModuleAccess (ALLOWED_CLASSES for the sidebar via navigationItemsFor(), ALLOWED_ROUTE_FRAGMENTS for RestrictItRoleToAllowedModules in both panels' authMiddleware, which redirects any other URL to the hierarchy page; the top-performer marquee is also skipped). To open another module to IT, add it to BOTH lists there — never grant IT in a resource's canAccess() alone (UserResource is Admin-only now). Covered by tests/Feature/ItRoleModuleAccessTest.php.
