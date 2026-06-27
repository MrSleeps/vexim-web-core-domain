<?php

namespace VEximweb\Core\Domain\Filament\Resources\Pages;

use VEximweb\Core\Domain\Filament\Resources\DomainResource;
use VEximweb\Core\Data\Models\Setting;
use App\Services\DKIMKeyService;
use VEximweb\Core\Domain\Services\DomainAdminLimitService;
use App\Filament\Notifications\DomainAccountNotification;
use VEximweb\Core\Data\Repositories\Interfaces\SettingRepositoryInterface;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Actions\Action;
use Illuminate\Validation\ValidationException;
use App\Events\DkimKeyGenerated;

class CreateDomain extends CreateRecord
{
    protected static string $resource = DomainResource::class;
    
    protected ?array $generatedDkimData = null;
    protected bool $alreadyProcessed = false;
    
    /**
     * Check limits before the form loads
     */
    public function mount(): void
    {
        $user = auth()->user();
        
        // Check limits for domain admins (not system admins)
        if ($user && $user->isDomainAdmin() && !$user->isSystemAdmin()) {
            $limitService = app(DomainAdminLimitService::class);
            $result = $limitService->canCreateDomain($user);
            
            if (!$result['allowed']) {
                // Show error notification
                Notification::make()
                    ->title('Domain Limit Reached')
                    ->body($result['message'])
                    ->danger()
                    ->seconds(5)
                    ->send();
                
                // Redirect back to the list page
                $this->redirect($this->getResource()::getUrl('index'));
                return;
            }
        }
        
        // Call parent mount method
        parent::mount();
    }
    
    protected function fillForm(): void
    {
        // Get the default values from settings
        $defaults = [
            'type' => 'local',
            'enabled' => true,
            'maildir' => Setting::get('mail_root', ''),
            'uid' => Setting::get('default_uid', ''),
            'gid' => Setting::get('default_gid', ''),
            'max_accounts' => Setting::get('default_max_users', 10),
            'maxmsgsize' => Setting::get('default_max_message_size', 0),
            'quotas' => Setting::get('default_max_storage', 0), 
            'sa_tag' => Setting::get('spam_tag_threshold', 2),
            'sa_refuse' => Setting::get('spam_refuse_threshold', 5),
            'avscan' => Setting::get('default_av_setting', true),
            'spamassassin' => Setting::get('default_spam_setting', false),
            'blocklist' => Setting::get('default_blocklist_setting', false),
            'pipe' => Setting::get('default_pipe_setting', false),
            'generate_dkim' => Setting::get('auto_generate_dkim', false),
        ];
        
        // Fill only the fields that have values from settings
        $data = [];
        foreach ($defaults as $field => $value) {
            if ($value !== '' && $value !== null) {
                $data[$field] = $value;
            }
        }
        
        // Only fill if there's data
        if (!empty($data)) {
            $this->form->fill($data);
        }
    }
    
    /**
     * Check domain limits before creating
     */
    protected function beforeCreate(): void
    {
        $user = auth()->user();
        
        // Only check limits for domain admins (not system admins)
        if ($user && $user->isDomainAdmin() && !$user->isSystemAdmin()) {
            $limitService = app(DomainAdminLimitService::class);
            $result = $limitService->canCreateDomain($user);
            
            if (!$result['allowed']) {
                // Throw validation exception to prevent creation
                throw ValidationException::withMessages([
                    'domain' => $result['message']
                ]);
            }
        }
    }
    
protected function afterCreate(): void
{
    // Prevent double execution
    if ($this->alreadyProcessed) {
        return;
    }
    $this->alreadyProcessed = true;
    
    // Clear the domain admin's cache after successful creation
    $user = auth()->user();
    if ($user) {
        $limitService = app(DomainAdminLimitService::class);
        $limitService->clearCache($user);
    }
    
    // Check if welcome emails are enabled using the interface
    $settingRepository = app(SettingRepositoryInterface::class);
    $sendWelcomeEmail = $settingRepository->get('send_domain_welcome_email', false);
    
    // Convert to boolean (handles string '1', '0', true, false)
    $sendWelcomeEmail = filter_var($sendWelcomeEmail, FILTER_VALIDATE_BOOLEAN);
    
    if ($sendWelcomeEmail) {
        // SEND DOMAIN ACCOUNT NOTIFICATION
        try {
            $domainAccountNotification = app(DomainAccountNotification::class);
            
            // Get the current user (the one who created the domain)
            $creator = auth()->user();
            
            // Determine who to send to based on permissions
            if ($creator->isSystemAdmin()) {
                // If system admin created it, send to all domain admins of this domain
                $result = $domainAccountNotification->sendToAllAdmins($this->record);
                
                if (!empty($result['success'])) {
                    Notification::make()
                        ->title('Domain Created')
                        ->body("Domain created successfully. Welcome email sent to: " . implode(', ', $result['success']))
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Domain Created')
                        ->body("Domain created successfully.")
                        ->success()
                        ->send();
                }
            } else {
                // Domain admin created it - send notification to the creator
                $sent = $domainAccountNotification->sendToUser($this->record, $creator);
                
                if ($sent) {
                    Notification::make()
                        ->title('Domain Created Successfully')
                        ->body("Your domain {$this->record->domain} has been created. A welcome email has been sent to {$creator->email}")
                        ->success()
                        ->duration(8000)
                        ->send();
                } else {
                    Notification::make()
                        ->title('Domain Created')
                        ->body("Domain {$this->record->domain} created but the welcome email could not be sent.")
                        ->warning()
                        ->send();
                }
            }
        } catch (\Exception $e) {
            // Log error but don't fail the domain creation
            \Illuminate\Support\Facades\Log::error('Failed to send domain creation notification', [
                'domain_id' => $this->record->domain_id,
                'error' => $e->getMessage()
            ]);
            
            Notification::make()
                ->title('Domain Created')
                ->body("Domain {$this->record->domain} created successfully, but the welcome email failed to send.")
                ->warning()
                ->send();
        }
    } else {
        // Welcome emails are disabled - just show a simple notification
        Notification::make()
            ->title('Domain Created')
            ->body("Domain {$this->record->domain} has been created successfully.")
            ->success()
            ->send();
    }
    
    // Check if we need to generate DKIM keys
    $generateDkim = $this->data['generate_dkim'] ?? false;
    
    if ($generateDkim) {
        try {
            $dkimService = app(DKIMKeyService::class);
            $dkim = $dkimService->generateKeys($this->record, 'default');
            
            $dnsRecord = $dkim->getDnsRecord();
            
            // Store DKIM data to show in modal
            $this->generatedDkimData = [
                'domain' => $this->record->domain,
                'name' => $dnsRecord['name'],
                'value' => $dnsRecord['value'],
                'selector' => $dkim->selector,
            ];
            
            // FIRE THE DKIM EVENT HERE
            // This will be picked up by dns-core and routed to the appropriate DNS provider
            event(new \App\Events\DkimKeyGenerated(
                zone: $this->record->domain,
                name: $dnsRecord['name'],
                type: 'TXT',
                content: $dnsRecord['value'],
                ttl: 3600,
                operation: 'create'
            ));
            
            \Illuminate\Support\Facades\Log::info('DKIM key generation event fired', [
                'domain' => $this->record->domain,
                'record_name' => $dnsRecord['name']
            ]);
            
            // Show success notification
            Notification::make()
                ->title('DKIM Keys Generated Successfully')
                ->body("DNS record has been automatically added to your DNS provider. Click 'View DNS Records' to see the details.")
                ->success()
                ->duration(5000)
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('Warning: DKIM Generation Failed')
                ->body("Domain created but DKIM generation failed: " . $e->getMessage())
                ->warning()
                ->duration(10000)
                ->send();
        }
    }
    
    \VEximweb\Core\Domain\Filament\Resources\Schemas\DomainForm::runSaveHooks($this->record, $this->data);
}
    
    /**
     * Disable the default success notification since we're showing custom ones
     */
    protected function getCreatedNotification(): ?Notification
    {
        return null; // Prevent default notification to avoid duplicates
    }
    
    protected function getRedirectUrl(): string
    {
        $url = $this->getResource()::getUrl('edit', ['record' => $this->record]);
        
        // Add parameter to show DKIM modal if keys were generated
        if ($this->generatedDkimData) {
            $url .= '?showDkimModal=1';
            session()->flash('dkim_data', $this->generatedDkimData);
        }
        
        return $url;
    }
}