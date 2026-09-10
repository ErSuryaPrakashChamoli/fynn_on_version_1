<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingCourse;
use App\Models\User;

class TrainingCoursePolicy
{
    use InteractsWithPortal;

    public function viewAny(User $user): bool
    {
        return $this->inAcademy($user);
    }

    /**
     * A trainee may read a course only through an enrollment they own —
     * knowing the id is not enough.
     */
    public function view(User $user, TrainingCourse $course): bool
    {
        if (! $this->sharesTenant($user, $course->tenant_id)) {
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

    public function update(User $user, TrainingCourse $course): bool
    {
        return $this->isTrainer($user) && $this->sharesTenant($user, $course->tenant_id);
    }

    public function delete(User $user, TrainingCourse $course): bool
    {
        return $this->update($user, $course);
    }
}
