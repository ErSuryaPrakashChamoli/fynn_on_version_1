---
paths:
  - 'app/Filament/Demo/**'
---

# Demo

## Demo isolation is schema-level — never point a Demo resource at a production model
The sandbox has its own demo_* tables with no foreign key or shared key space with leads/customers/employees. That is why "demo cannot read production" is a property of the schema, not of a global scope someone might forget.

- Every App\Filament\Demo\Resources\** resource must bind to an App\Models\Demo\* model and use the SandboxResource trait (demo-only canAccess + tenant-scoped getEloquentQuery).
- Deletes are refused panel-wide (DemoRecordPolicy::delete returns false) so a prospect cannot empty the dataset mid-demonstration.
- DemoResetService::TABLES is the only list of tables the reset may clear; assertDemoOnly() rejects anything without the demo_ prefix.
