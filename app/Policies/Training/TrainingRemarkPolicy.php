<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingRemark;
use App\Models\User;

class TrainingRemarkPolicy
{
    use InteractsWithPortal;

    public function viewAny(User $user): bool
    {
        return $this->inAcademy($user);
    }

    /**
     * A trainee sees a remark only if it is theirs AND the trainer chose
     * to share it — internal notes stay internal.
     */
    public function view(User $user, TrainingRemark $remark): bool
    {
        if ($this->isTrainee($user)) {
            return $remark->is_visible_to_trainee
                && $remark->enrollment?->trainee_id === $user->getKey();
        }

        return $this->isTrainer($user)
            && $this->sharesTenant($user, $remark->enrollment?->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->isTrainer($user);
    }

    public function update(User $user, TrainingRemark $remark): bool
    {
        return $this->isTrainer($user)
            && $this->sharesTenant($user, $remark->enrollment?->tenant_id);
    }

    public function delete(User $user, TrainingRemark $remark): bool
    {
        return $this->update($user, $remark);
    }
}
