<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingLessonDocument;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingQuiz;
use App\Models\Training\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Modules, lessons, documents, quizzes and sessions all authorise the
 * same way: resolve the course they hang off, then defer to enrollment
 * (trainee) or tenant (trainer). One policy rather than five identical
 * ones keeps that resolution in a single place — resolveCourse() below
 * is the only thing that has to be right.
 */
class TrainingContentPolicy
{
    use InteractsWithPortal;

    public function viewAny(User $user): bool
    {
        return $this->inAcademy($user);
    }

    public function view(User $user, Model $record): bool
    {
        $course = $this->resolveCourse($record);

        if ($course === null || ! $this->sharesTenant($user, $course->tenant_id)) {
            return false;
        }

        if ($this->isTrainer($user)) {
            return true;
        }

        return $this->isTrainee($user)
            && $course->enrollments()->ownedBy($user)->exists();
    }

    public function create(User $user): bool
    {
        return $this->isTrainer($user);
    }

    public function update(User $user, Model $record): bool
    {
        $course = $this->resolveCourse($record);

        return $this->isTrainer($user)
            && $course !== null
            && $this->sharesTenant($user, $course->tenant_id);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    protected function resolveCourse(Model $record): ?TrainingCourse
    {
        return match (true) {
            $record instanceof TrainingModule => $record->course,
            $record instanceof TrainingLesson => $record->module?->course,
            $record instanceof TrainingLessonDocument => $record->lesson?->module?->course,
            $record instanceof TrainingQuiz => $record->resolveCourse(),
            $record instanceof TrainingSession => $record->module?->course,
            default => null,
        };
    }
}
