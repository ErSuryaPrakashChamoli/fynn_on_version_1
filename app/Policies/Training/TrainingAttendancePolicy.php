<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingAttendance;
use App\Models\User;

class TrainingAttendancePolicy
{
    use InteractsWithPortal;

    public function viewAny(User $user): bool
    {
        return $this->inAcademy($user);
    }

    public function view(User $user, TrainingAttendance $attendance): bool
    {
        if ($this->isTrainee($user)) {
            return $attendance->trainee_id === $user->getKey();
        }

        return $this->isTrainer($user)
            && $this->sharesTenant($user, $attendance->batch?->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->isTrainer($user);
    }

    public function update(User $user, TrainingAttendance $attendance): bool
    {
        return $this->isTrainer($user)
            && $this->sharesTenant($user, $attendance->batch?->tenant_id);
    }

    public function delete(User $user, TrainingAttendance $attendance): bool
    {
        return $this->update($user, $attendance);
    }
}
