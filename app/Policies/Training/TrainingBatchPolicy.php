<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingBatch;
use App\Models\User;

class TrainingBatchPolicy
{
    use InteractsWithPortal;

    public function viewAny(User $user): bool
    {
        return $this->inAcademy($user);
    }

    public function view(User $user, TrainingBatch $batch): bool
    {
        if (! $this->sharesTenant($user, $batch->tenant_id)) {
            return false;
        }

        if ($this->isTrainer($user)) {
            return true;
        }

        return $this->isTrainee($user)
            && $batch->trainees()->whereKey($user->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $this->isTrainer($user);
    }

    public function update(User $user, TrainingBatch $batch): bool
    {
        return $this->isTrainer($user) && $this->sharesTenant($user, $batch->tenant_id);
    }

    public function delete(User $user, TrainingBatch $batch): bool
    {
        return $this->update($user, $batch);
    }
}
