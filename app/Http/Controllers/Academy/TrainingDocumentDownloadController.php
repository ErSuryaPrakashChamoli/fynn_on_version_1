<?php

namespace App\Http\Controllers\Academy;

use App\Models\PortalAccount;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingLessonDocument;
use App\Models\User;
use App\Support\Portal\PortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read a training document.
 *
 * Documents live on the private 'local' disk, so there is no
 * /storage/... URL to guess; this endpoint is registered inside the
 * Academy panel's route group, so a request reaches it only after
 * session auth, canAccessPanel() and EnsureAcademyAccess have all
 * passed. It then adds the checks those cannot make:
 *
 *   - the document is downloadable at all;
 *   - the course it belongs to is in the caller's tenant;
 *   - a trainee is enrolled on that specific course.
 *
 * Failures are 404, not 403 — a trainee probing ids should not be able
 * to tell "exists but forbidden" from "does not exist".
 */
class TrainingDocumentDownloadController
{
    public function __invoke(Request $request, TrainingLessonDocument $document): StreamedResponse
    {
        $user = $request->user();
        $course = $document->lesson?->module?->course;

        abort_if($course === null || ! $document->is_downloadable, 404);

        $context = app(PortalContext::class);
        $account = $context->account();

        abort_unless($this->mayRead($account, $user, $course, $context), 404);

        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        return $disk->download($document->path, $document->original_name ?? $document->title);
    }

    protected function mayRead(
        ?PortalAccount $account,
        ?User $user,
        TrainingCourse $course,
        PortalContext $context,
    ): bool {
        if ($user === null) {
            return false;
        }

        // An internal LMS Admin authoring content.
        if ($account === null) {
            return $user->hasRole('Admin');
        }

        if (! $account->isUsable() || $account->tenant_id !== $course->tenant_id) {
            return false;
        }

        if ($account->isTrainer()) {
            return true;
        }

        return $account->isTrainee()
            && $course->enrollments()->ownedBy($user)->exists();
    }
}
