<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingQuizAttempt;
use App\Models\User;

class TrainingQuizAttemptPolicy
{
    use InteractsWithPortal;

    public function viewAny(User $user): bool
    {
        return $this->inAcademy($user);
    }

    public function view(User $user, TrainingQuizAttempt $attempt): bool
    {
        if ($this->isTrainee($user)) {
            return $attempt->trainee_id === $user->getKey();
        }

        return $this->isTrainer($user)
            && $this->sharesTenant($user, $attempt->enrollment?->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->isTrainee($user);
    }

    /**
     * An in-flight attempt may only be answered by the trainee who owns
     * it — not by a trainer, and not by another trainee.
     */
    public function submit(User $user, TrainingQuizAttempt $attempt): bool
    {
        return $this->isTrainee($user)
            && $attempt->trainee_id === $user->getKey()
            && $attempt->status === 'in_progress';
    }

    public function update(User $user, TrainingQuizAttempt $attempt): bool
    {
        return $this->submit($user, $attempt);
    }

    public function delete(User $user, TrainingQuizAttempt $attempt): bool
    {
        return false;
    }
}
