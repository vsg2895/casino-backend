<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CmsPage;
use App\Models\User;

class CmsPagePolicy
{
    /**
     * Only platform administrators may manage CMS pages.
     * Admin endpoints are already behind auth:sanctum; this is the authorization layer.
     */
    private function isAdmin(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, CmsPage $page): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, CmsPage $page): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, CmsPage $page): bool
    {
        return $this->isAdmin($user);
    }
}
