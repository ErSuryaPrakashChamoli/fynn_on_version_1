<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingEnrollment;
use App\Models\User;

/**
 * The IDOR boundary.
 *
 * Every trainee-facing read in the Academy resolves to an enrollment, so
 * "trainee A opens /academy/... /enrollments/{B's id}" ends here, and
 * ends with false: a trainee is authorised by row ownership, never by
 * role.
 */
class TrainingEnrollmentPolicy
{
    use InteractsWithPortal;

    public function viewAny(User $user): bool
    {
        return $this->inAcademy($user);
    }

    public function view(User $user, TrainingEnrollment $enrollment): bool
    {
        if (! $this->sharesTenant($user, $enrollment->tenant_id)) {
            return false;
        }

        if ($this->isTrainee($user)) {
            return $enrollment->trainee_id === $user->getKey();
        }

        return $this->isTrainer($user);
    }

    public function create(User $user): bool
    {
        return $this->isTrainer($user);
    }

    public function update(User $user, TrainingEnrollment $enrollment): bool
    {
        return $this->isTrainer($user) && $this->sharesTenant($user, $enrollment->tenant_id);
    }

    public function delete(User $user, TrainingEnrollment $enrollment): bool
    {
        return $this->update($user, $enrollment);
    }

    /**
     * Recording progress is the one write a trainee may make, and only
     * against their own enrollment.
     */
    public function recordProgress(User $user, TrainingEnrollment $enrollment): bool
    {
        return $this->isTrainee($user)
            && $enrollment->trainee_id === $user->getKey()
            && $this->sharesTenant($user, $enrollment->tenant_id);
    }

    public function issueCertificate(User $user, TrainingEnrollment $enrollment): bool
    {
        return $this->update($user, $enrollment);
    }
}
