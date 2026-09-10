---
paths:
  - 'app/Filament/Academy/**'
---

# Academy

## Academy: tenant-scope in getEloquentQuery, authorise trainees by row ownership
Trainer resources must use App\Filament\Academy\Resources\Concerns\TrainerResource. It refuses trainees in canAccess() (Filament checks this for the route, not just the nav item) and narrows the base query to the caller's tenant before any filter or sort runs. Models without their own tenant_id override scopeQueryToTenant() to constrain through the parent course.

Trainee pages take a route param, so they authorise by ownership: TrainingEnrollment is the single object everything a trainee can read hangs off. Implicit route-model binding already resolves {enrollment}/{quiz} before mount() — a bad id is a 404, and the policy check turns someone else's row into a 403.

Never expose TrainingQuizQuestion::correct_answer — it is in $hidden because Livewire serializes component state to the browser.
