<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserSource;

class UserSourcePolicy
{
    public function view(User $user, UserSource $userSource): bool
    {
        return $userSource->user_id === $user->id;
    }

    public function delete(User $user, UserSource $userSource): bool
    {
        return $userSource->user_id === $user->id;
    }
}
