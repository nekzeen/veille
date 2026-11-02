<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Veille;

class VeillePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Veille $veille): bool
    {
        return $veille->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Veille $veille): bool
    {
        return $veille->user_id === $user->id;
    }

    public function delete(User $user, Veille $veille): bool
    {
        return $veille->user_id === $user->id;
    }
}
