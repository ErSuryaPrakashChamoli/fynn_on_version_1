---
paths:
  - 'app/Filament/Demo/**'
  - 'app/Models/Demo/**'
  - 'app/Services/Demo/**'
  - 'database/migrations/demo/**'
  - 'database/seeders/Demo/**'
  - 'config/demo.php'
---

# Demo

## /demo runs on its own database and its own guard — never point Demo code at the main connection
Since 2026-09-24 the sandbox is isolated at the database level: every App\Models\Demo\* model (DemoModel subclasses and DemoUser) returns DemoDatabase::connectionName() ('demo', config/database.php `demo` / DEMO_DB_*). The panel authenticates on the `demo` guard (provider demo_users → DemoUser), so a main LMS session is a guest on /demo and a DemoUser is a guest on /admin.

- Every App\Filament\Demo\Resources\** resource must bind to an App\Models\Demo\* model and use the SandboxResource trait (canAccess = Filament::auth()->user() instanceof DemoUser). Never name a main model (Customer, TrainingCourse, Tenant, ...) from Demo code — the old Training page was removed for exactly that reason.
- There is no tenant_id on demo tables any more; the demo database itself is the boundary.
- Demo migrations live in database/migrations/demo and override getConnection(); run them only via `php artisan demo:migrate [--fresh] [--seed] [--rollback]`. The plain `migrate` does not scan that subdirectory.
- DemoDatabase::assertIsolated() (EnsureDemoIsAvailable on every /demo request, demo:migrate, demo:reset, DemoDatabaseSeeder) refuses a demo connection with no database or one resolving to the main database. It compares against config('demo.main_connection'), NOT database.default — `db:seed --database=demo` switches the runtime default.
- Deletes are refused panel-wide (DemoRecordPolicy::delete returns false; before() denies any non-DemoUser). DemoResetService::TABLES is the only list demo:reset clears, on the demo connection only; demo_users is kept.
- The Login/Logout listeners skip non-User logins so a demo login never writes user_login_sessions; the panel emits <meta name="login-session-heartbeat" content="off"> so the global heartbeat script stays dormant.

Tests: PortalBoundaryTestCase uses Tests\RefreshDemoDatabase (demo = separate SQLite :memory: via phpunit.xml). actingAsDemoUser() resets the default guard afterwards — actingAs()/Livewire::actingAs() with 'demo' otherwise leaves `demo` as the default guard for the rest of the test. Covered by tests/Feature/Portal/DemoDatabaseIsolationTest.php and DemoSandboxIsolationTest.php.
