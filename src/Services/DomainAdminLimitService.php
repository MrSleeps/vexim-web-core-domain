<?php

namespace VEximweb\Core\Domain\Services;

use VEximweb\Core\Data\Models\User;
use VEximweb\Core\Data\Models\Domain;
use VEximweb\Core\Data\Models\EximUser;
use VEximweb\Core\Data\Models\DomainAlias;
use VEximweb\Core\Data\Repositories\Interfaces\SettingRepositoryInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Service to manage and enforce domain administrator limits.
 * 
 * This service handles limit checking for:
 * - Number of domains a domain admin can manage
 * - Number of alias domains
 * - Number of email accounts (local type)
 * - Number of alias accounts (alias type)
 * - Total quota usage across all mailboxes
 * - Domain-specific max_accounts limits
 */
class DomainAdminLimitService
{
    /**
     * Cache duration in seconds (5 minutes).
     */
    protected const CACHE_DURATION = 300;
    
    /**
     * @var SettingRepositoryInterface
     */
    protected SettingRepositoryInterface $settingRepository;
    
    /**
     * DomainAdminLimitService constructor.
     *
     * @param SettingRepositoryInterface $settingRepository
     */
    public function __construct(SettingRepositoryInterface $settingRepository)
    {
        $this->settingRepository = $settingRepository;
    }
    
    /**
     * Check if a domain admin can create a new domain.
     *
     * @param User $user
     * @return array ['allowed' => bool, 'message' => string|null, 'remaining' => int|null]
     */
    public function canCreateDomain(User $user): array
    {
        // System admins bypass all checks
        if ($user->isSystemAdmin()) {
            return ['allowed' => true, 'message' => null, 'remaining' => null];
        }
        
        // Only check for domain admins
        if (!$user->isDomainAdmin()) {
            return ['allowed' => true, 'message' => null, 'remaining' => null];
        }
        
        $maxDomains = $this->getUserLimit($user, 'max_domains');
        
        // Unlimited (0 means unlimited)
        if ($maxDomains == 0) {
            return ['allowed' => true, 'message' => null, 'remaining' => null];
        }
        
        $currentDomains = $this->getCurrentDomainCount($user);
        
        if ($currentDomains >= $maxDomains) {
            return [
                'allowed' => false,
                'message' => "You have reached your domain limit of {$maxDomains} domains. Please contact a system administrator to increase your limit.",
                'remaining' => 0
            ];
        }
        
        $remaining = $maxDomains - $currentDomains;
        return [
            'allowed' => true,
            'message' => null,
            'remaining' => $remaining
        ];
    }
    
    /**
     * Check if a domain admin can create an alias domain.
     *
     * @param User $user
     * @return array ['allowed' => bool, 'message' => string|null]
     */
    public function canCreateAliasDomain(User $user): array
    {
        if ($user->isSystemAdmin()) {
            return ['allowed' => true, 'message' => null];
        }
        
        if (!$user->isDomainAdmin()) {
            return ['allowed' => true, 'message' => null];
        }
        
        $maxAliasDomains = $this->getUserLimit($user, 'max_alias_domains');
        
        if ($maxAliasDomains == 0) {
            return ['allowed' => true, 'message' => null];
        }
        
        $currentAliasDomains = $this->getCurrentAliasDomainCount($user);
        
        if ($currentAliasDomains >= $maxAliasDomains) {
            return [
                'allowed' => false,
                'message' => "You have reached your alias domain limit of {$maxAliasDomains}."
            ];
        }
        
        return ['allowed' => true, 'message' => null];
    }
    
    /**
     * Check if a domain admin can create a new email account (local).
     * Checks BOTH user global limit AND domain-specific max_accounts limit.
     *
     * @param User $user
     * @param int|null $domainId Optional specific domain to check against
     * @return array ['allowed' => bool, 'message' => string|null, 'remaining' => int|null]
     */
    public function canCreateEmailAccount(User $user, ?int $domainId = null): array
    {
        if ($user->isSystemAdmin()) {
            return ['allowed' => true, 'message' => null, 'remaining' => null];
        }
        
        if (!$user->isDomainAdmin()) {
            return ['allowed' => true, 'message' => null, 'remaining' => null];
        }
        
        // Check 1: User's global limit
        $maxAccounts = $this->getUserLimit($user, 'max_accounts');
        
        if ($maxAccounts > 0) {
            $currentAccounts = $this->getCurrentEmailAccountCount($user, $domainId);
            
            if ($currentAccounts >= $maxAccounts) {
                return [
                    'allowed' => false,
                    'message' => "You have reached your global email account limit of {$maxAccounts} email accounts.",
                    'remaining' => 0
                ];
            }
        }
        
        // Check 2: Domain-specific max_accounts limit (if domain is specified)
        if ($domainId) {
            $domain = Domain::find($domainId);
            if ($domain && $domain->max_accounts > 0) {
                $currentDomainAccounts = EximUser::where('domain_id', $domainId)
                    ->where('type', 'local')
                    ->count();
                
                if ($currentDomainAccounts >= $domain->max_accounts) {
                    return [
                        'allowed' => false,
                        'message' => "This domain has reached its account limit of {$domain->max_accounts} email accounts.",
                        'remaining' => 0
                    ];
                }
            }
        }
        
        // Calculate remaining if both limits are set
        $maxAccounts = $this->getUserLimit($user, 'max_accounts');
        $currentAccounts = $this->getCurrentEmailAccountCount($user, $domainId);
        
        $remaining = $maxAccounts > 0 ? $maxAccounts - $currentAccounts : null;
        
        return [
            'allowed' => true,
            'message' => null,
            'remaining' => $remaining
        ];
    }
    
    /**
     * Check if a domain admin can create an alias account.
     *
     * @param User $user
     * @param int|null $domainId Optional specific domain to check against
     * @return array ['allowed' => bool, 'message' => string|null]
     */
    public function canCreateAliasAccount(User $user, ?int $domainId = null): array
    {
        if ($user->isSystemAdmin()) {
            return ['allowed' => true, 'message' => null];
        }
        
        if (!$user->isDomainAdmin()) {
            return ['allowed' => true, 'message' => null];
        }
        
        $maxAliasAccounts = $this->getUserLimit($user, 'max_alias_accounts');
        
        if ($maxAliasAccounts == 0) {
            return ['allowed' => true, 'message' => null];
        }
        
        $currentAliasAccounts = $this->getCurrentAliasAccountCount($user, $domainId);
        
        if ($currentAliasAccounts >= $maxAliasAccounts) {
            return [
                'allowed' => false,
                'message' => "You have reached your alias account limit of {$maxAliasAccounts}."
            ];
        }
        
        return ['allowed' => true, 'message' => null];
    }
    
    /**
     * Check if adding more quota would exceed the user's total quota limit.
     *
     * @param User $user
     * @param int $additionalQuota The additional quota in MB to add
     * @return array ['allowed' => bool, 'message' => string|null]
     */
    public function canAddQuota(User $user, int $additionalQuota = 0): array
    {
        if ($user->isSystemAdmin()) {
            return ['allowed' => true, 'message' => null];
        }
        
        if (!$user->isDomainAdmin()) {
            return ['allowed' => true, 'message' => null];
        }
        
        $maxQuota = $this->getUserLimit($user, 'max_quota');
        
        if ($maxQuota == 0) {
            return ['allowed' => true, 'message' => null];
        }
        
        $currentQuota = $this->getCurrentTotalQuota($user);
        $newQuotaTotal = $currentQuota + $additionalQuota;
        
        if ($newQuotaTotal > $maxQuota) {
            $maxQuotaMB = $maxQuota;
            $currentQuotaMB = $currentQuota;
            return [
                'allowed' => false,
                'message' => "Adding {$additionalQuota}MB would exceed your total quota limit of {$maxQuotaMB}MB. Currently using {$currentQuotaMB}MB."
            ];
        }
        
        return ['allowed' => true, 'message' => null];
    }
    
    /**
     * Check if a specific domain has reached its account limit.
     *
     * @param int $domainId
     * @return array ['allowed' => bool, 'message' => string|null, 'current' => int, 'max' => int]
     */
    public function checkDomainAccountLimit(int $domainId): array
    {
        $domain = Domain::find($domainId);
        
        if (!$domain) {
            return [
                'allowed' => true,
                'message' => null,
                'current' => 0,
                'max' => 0
            ];
        }
        
        if ($domain->max_accounts == 0) {
            return [
                'allowed' => true,
                'message' => null,
                'current' => 0,
                'max' => 0
            ];
        }
        
        $currentAccounts = EximUser::where('domain_id', $domainId)
            ->where('type', 'local')
            ->count();
        
        if ($currentAccounts >= $domain->max_accounts) {
            return [
                'allowed' => false,
                'message' => "This domain has reached its account limit of {$domain->max_accounts} email accounts.",
                'current' => $currentAccounts,
                'max' => $domain->max_accounts
            ];
        }
        
        return [
            'allowed' => true,
            'message' => null,
            'current' => $currentAccounts,
            'max' => $domain->max_accounts
        ];
    }
    
    /**
     * Get domain account usage statistics.
     *
     * @param int $domainId
     * @return array
     */
    public function getDomainAccountStats(int $domainId): array
    {
        $domain = Domain::find($domainId);
        
        if (!$domain) {
            return [
                'domain_id' => $domainId,
                'domain_name' => null,
                'current_accounts' => 0,
                'max_accounts' => 0,
                'available' => 0,
                'percentage' => 0
            ];
        }
        
        $currentAccounts = EximUser::where('domain_id', $domainId)
            ->where('type', 'local')
            ->count();
        
        $maxAccounts = $domain->max_accounts;
        $available = $maxAccounts > 0 ? max(0, $maxAccounts - $currentAccounts) : null;
        $percentage = $maxAccounts > 0 ? round(($currentAccounts / $maxAccounts) * 100, 1) : 0;
        
        return [
            'domain_id' => $domainId,
            'domain_name' => $domain->domain,
            'current_accounts' => $currentAccounts,
            'max_accounts' => $maxAccounts == 0 ? 'Unlimited' : $maxAccounts,
            'available' => $available,
            'percentage' => $percentage,
            'is_full' => $maxAccounts > 0 && $currentAccounts >= $maxAccounts
        ];
    }
    
    /**
     * Get usage statistics for a domain admin.
     *
     * @param User $user
     * @return array
     */
    public function getUsageStats(User $user): array
    {
        // Return empty array for system admins or non-domain admins
        if ($user->isSystemAdmin() || !$user->isDomainAdmin()) {
            return [];
        }
        
        $stats = [
            'domains' => [
                'current' => $this->getCurrentDomainCount($user),
                'max' => $this->getUserLimit($user, 'max_domains'),
                'percentage' => 0
            ],
            'alias_domains' => [
                'current' => $this->getCurrentAliasDomainCount($user),
                'max' => $this->getUserLimit($user, 'max_alias_domains'),
                'percentage' => 0
            ],
            'email_accounts' => [
                'current' => $this->getCurrentEmailAccountCount($user),
                'max' => $this->getUserLimit($user, 'max_accounts'),
                'percentage' => 0
            ],
            'alias_accounts' => [
                'current' => $this->getCurrentAliasAccountCount($user),
                'max' => $this->getUserLimit($user, 'max_alias_accounts'),
                'percentage' => 0
            ],
            'quota' => [
                'current' => $this->getCurrentTotalQuota($user),
                'max' => $this->getUserLimit($user, 'max_quota'),
                'percentage' => 0,
                'current_formatted' => $this->formatQuota($this->getCurrentTotalQuota($user)),
                'max_formatted' => $this->formatQuota($this->getUserLimit($user, 'max_quota'))
            ]
        ];

        foreach ($stats as $key => &$stat) {
            if ($stat['max'] > 0) {
                $stat['percentage'] = round(($stat['current'] / $stat['max']) * 100, 1);
            } elseif ($stat['max'] == 0) {
                $stat['percentage'] = 0;
                $stat['max'] = 'Unlimited';
            }
        }
        
        return $stats;
    }
    
    /**
     * Get a user's limit for a specific field, falling back to system defaults.
     *
     * @param User $user
     * @param string $field (max_domains, max_alias_domains, max_accounts, max_alias_accounts, max_quota)
     * @return int
     */
    public function getUserLimit(User $user, string $field): int
    {
        if (isset($user->$field) && $user->$field !== null) {
            return (int) $user->$field;
        }
        
        $defaultKey = "default_{$field}";
        $defaultValue = $this->settingRepository->get($defaultKey, 0);
        
        return (int) $defaultValue;
    }
    
    /**
     * Clear cached usage data for a user.
     *
     * @param User $user
     * @return void
     */
    public function clearCache(User $user): void
    {
        $cacheKeys = [
            $this->getCacheKey($user, 'domain_count'),
            $this->getCacheKey($user, 'alias_domain_count'),
            $this->getCacheKey($user, 'email_account_count'),
            $this->getCacheKey($user, 'alias_account_count'),
            $this->getCacheKey($user, 'total_quota'),
        ];
        
        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
        
        $domainIds = $user->domains()->pluck('domains.domain_id');
        foreach ($domainIds as $domainId) {
            Cache::forget($this->getCacheKey($user, "email_account_count.domain.{$domainId}"));
            Cache::forget($this->getCacheKey($user, "alias_account_count.domain.{$domainId}"));
        }
    }
    
    /**
     * Get total number of domains managed by this domain admin.
     *
     * @param User $user
     * @return int
     */
    protected function getCurrentDomainCount(User $user): int
    {
        $cacheKey = $this->getCacheKey($user, 'domain_count');
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($user) {
            return $user->domains()->count();
        });
    }
    
    /**
     * Get total number of alias domains across all domains managed by this admin.
     *
     * @param User $user
     * @return int
     */
    protected function getCurrentAliasDomainCount(User $user): int
    {
        $cacheKey = $this->getCacheKey($user, 'alias_domain_count');
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($user) {
            $domainIds = $user->domains()->pluck('domains.domain_id');
            return DomainAlias::whereIn('domain_id', $domainIds)->count();
        });
    }
    
    /**
     * Get total number of email accounts (type = 'local') across all domains managed by this admin.
     *
     * @param User $user
     * @param int|null $domainId Optional specific domain
     * @return int
     */
    protected function getCurrentEmailAccountCount(User $user, ?int $domainId = null): int
    {
        $cacheKey = $this->getCacheKey($user, 'email_account_count');
        if ($domainId) {
            $cacheKey .= ".domain.{$domainId}";
        }
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($user, $domainId) {
            $query = EximUser::where('type', 'local');
            
            if ($domainId) {
                // Check specific domain
                $query->where('domain_id', $domainId);
            } else {
                // Check across all managed domains
                $domainIds = $user->domains()->pluck('domains.domain_id');
                if ($domainIds->isEmpty()) {
                    return 0;
                }
                $query->whereIn('domain_id', $domainIds);
            }
            
            return $query->count();
        });
    }
    
    /**
     * Get total number of alias accounts (type = 'alias') across all domains managed by this admin.
     *
     * @param User $user
     * @param int|null $domainId Optional specific domain
     * @return int
     */
    protected function getCurrentAliasAccountCount(User $user, ?int $domainId = null): int
    {
        $cacheKey = $this->getCacheKey($user, 'alias_account_count');
        if ($domainId) {
            $cacheKey .= ".domain.{$domainId}";
        }
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($user, $domainId) {
            $query = EximUser::where('type', 'alias');
            
            if ($domainId) {
                $query->where('domain_id', $domainId);
            } else {
                $domainIds = $user->domains()->pluck('domains.domain_id');
                if ($domainIds->isEmpty()) {
                    return 0;
                }
                $query->whereIn('domain_id', $domainIds);
            }
            
            return $query->count();
        });
    }
    
    /**
     * Get total quota usage across all email accounts managed by this admin.
     *
     * @param User $user
     * @return int Total quota in MB
     */
    protected function getCurrentTotalQuota(User $user): int
    {
        $cacheKey = $this->getCacheKey($user, 'total_quota');
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($user) {
            $domainIds = $user->domains()->pluck('domains.domain_id');
            
            if ($domainIds->isEmpty()) {
                return 0;
            }
            
            return (int) EximUser::whereIn('domain_id', $domainIds)
                ->where('type', 'local') // Only count quota for local accounts
                ->sum('quota');
        });
    }
    
    /**
     * Format quota for display.
     *
     * @param int $quotaInMB
     * @return string
     */
    protected function formatQuota(int $quotaInMB): string
    {
        if ($quotaInMB == 0) {
            return 'Unlimited';
        }
        
        if ($quotaInMB >= 1024) {
            $gb = round($quotaInMB / 1024, 1);
            return "{$gb} GB";
        }
        
        return "{$quotaInMB} MB";
    }
    
    /**
     * Generate a cache key for a user.
     *
     * @param User $user
     * @param string $suffix
     * @return string
     */
    protected function getCacheKey(User $user, string $suffix): string
    {
        return "user.{$user->id}.domain_admin.{$suffix}";
    }
}