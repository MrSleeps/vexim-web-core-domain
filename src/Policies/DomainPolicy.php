<?php

declare(strict_types=1);

namespace VEximweb\Core\Domain\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use VEximweb\Core\Data\Models\Domain;
use VEximweb\Core\Data\Models\User;

class DomainPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Domain');
    }

    public function view(AuthUser $authUser, Domain $domain): bool
    {
        return $authUser->can('View:Domain')
            && $this->canAccessDomain($authUser, $domain);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Domain');
    }

    public function update(AuthUser $authUser, Domain $domain): bool
    {
        return $authUser->can('Update:Domain')
            && $this->canAccessDomain($authUser, $domain);
    }

    public function delete(AuthUser $authUser, Domain $domain): bool
    {
        return $authUser->can('Delete:Domain')
            && $this->canAccessDomain($authUser, $domain);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Domain');
    }

    public function restore(AuthUser $authUser, Domain $domain): bool
    {
        return $authUser->can('Restore:Domain')
            && $this->canAccessDomain($authUser, $domain);
    }

    public function forceDelete(AuthUser $authUser, Domain $domain): bool
    {
        return $authUser->can('ForceDelete:Domain')
            && $this->canAccessDomain($authUser, $domain);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Domain');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Domain');
    }

    public function replicate(AuthUser $authUser, Domain $domain): bool
    {
        return $authUser->can('Replicate:Domain')
            && $this->canAccessDomain($authUser, $domain);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Domain');
    }

    private function canAccessDomain(AuthUser $authUser, Domain $domain): bool
    {
        if (! $authUser instanceof User) {
            return false;
        }

        if ($authUser->isSystemAdmin()) {
            return true;
        }

        return $domain->administrators()
            ->whereKey($authUser->getKey())
            ->exists();
    }
}
