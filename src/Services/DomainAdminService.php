<?php

namespace VEximweb\Core\Domain\Services;

use VEximweb\Core\Data\Models\Domain;
use VEximweb\Core\Data\Models\User;
use VEximweb\Core\Data\Repositories\DomainRepository;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class DomainAdminService
{
    protected DomainRepository $domainRepository;

    public function __construct(DomainRepository $domainRepository)
    {
        $this->domainRepository = $domainRepository;
    }

    /**
     * Get all domain administrators for a domain
     * Uses the many-to-many relationship from DomainRepository
     *
     * @param Domain $domain
     * @return Collection<User>
     */
    public function getDomainAdmins(Domain $domain): Collection
    {
        return $this->domainRepository->getDomainAdministrators($domain->domain_id);
    }

    /**
     * Get the primary domain administrator (first one found)
     *
     * @param Domain $domain
     * @return User|null
     */
    public function getPrimaryDomainAdmin(Domain $domain): ?User
    {
        $admins = $this->getDomainAdmins($domain);
        
        if ($admins->isEmpty()) {
            // Fallback: get any user with admin role for this domain
            return User::where('domain_id', $domain->domain_id)
                ->where('admin', true)
                ->first();
        }
        
        return $admins->first();
    }

    /**
     * Get all domain administrators' email addresses
     *
     * @param Domain $domain
     * @return array
     */
    public function getDomainAdminEmails(Domain $domain): array
    {
        return $this->getDomainAdmins($domain)
            ->pluck('username')
            ->toArray();
    }

    /**
     * Check if a user is a domain administrator
     *
     * @param Domain $domain
     * @param User $user
     * @return bool
     */
    public function isDomainAdmin(Domain $domain, User $user): bool
    {
        return $this->domainRepository->hasUser($domain->domain_id, $user->getKey());
    }

    /**
     * Get domains for a specific administrator
     *
     * @param User $user
     * @return Collection
     */
    public function getAdminDomains(User $user): Collection
    {
        return $user->domains()->get();
    }

    /**
     * Assign a user as domain administrator
     *
     * @param Domain $domain
     * @param User $user
     * @param string $role
     * @return void
     */
    public function assignDomainAdmin(Domain $domain, User $user, string $role = 'domain_admin'): void
    {
        $this->domainRepository->assignUser($domain->domain_id, $user->getKey(), $role);
    }

    /**
     * Remove a domain administrator
     *
     * @param Domain $domain
     * @param User $user
     * @return void
     */
    public function removeDomainAdmin(Domain $domain, User $user): void
    {
        $this->domainRepository->removeUser($domain->domain_id, $user->getKey());
    }
}