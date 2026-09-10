<?php

namespace App\Policies\Training;

use App\Models\Training\TrainingCertificate;
use App\Models\User;

class TrainingCertificatePolicy
{
    use InteractsWithPortal;

    public function viewAny(User $user): bool
    {
        return $this->inAcademy($user);
    }

    public function view(User $user, TrainingCertificate $certificate): bool
    {
        if (! $this->sharesTenant($user, $certificate->tenant_id)) {
            return false;
        }

        if ($this->isTrainee($user)) {
            return $certificate->trainee_id === $user->getKey();
        }

        return $this->isTrainer($user);
    }

    public function create(User $user): bool
    {
        return $this->isTrainer($user);
    }

    public function update(User $user, TrainingCertificate $certificate): bool
    {
        return $this->isTrainer($user) && $this->sharesTenant($user, $certificate->tenant_id);
    }

    public function delete(User $user, TrainingCertificate $certificate): bool
    {
        return $this->update($user, $certificate);
    }
}
