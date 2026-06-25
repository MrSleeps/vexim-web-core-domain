<?php

namespace  VEximweb\Core\Domain\Filament\Resources\Pages;

use VEximweb\Core\Domain\Filament\Resources\DomainResource;
use App\Services\DKIMKeyService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use App\Events\DkimKeyGenerated;

class EditDomain extends EditRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // DKIM Management Actions
            Action::make('viewDKIM')
                ->label('View DKIM Details')
                ->icon('heroicon-o-key')
                ->color('info')
                ->modalHeading('DKIM Configuration')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('3xl')  // Make modal wider for better readability
                ->form(function ($record) {
                    if (!$record->dkim) {
                        return [
                            Placeholder::make('status')
                                ->label('Status')
                                ->content('No DKIM keys generated for this domain')
                                ->extraAttributes(['class' => 'text-gray-500']),
                        ];
                    }
                    
                    if (!$record->dkim->enabled) {
                        return [
                            Placeholder::make('status')
                                ->label('Status')
                                ->content('DKIM keys exist but are currently disabled')
                                ->extraAttributes(['class' => 'text-yellow-500']),
                            TextInput::make('selector')
                                ->label('Selector')
                                ->default($record->dkim->selector)
                                ->disabled(),
                            Textarea::make('public_key')
                                ->label('Public Key (for reference)')
                                ->default($record->dkim->public_key)
                                ->disabled()
                                ->rows(2)
                                ->columnSpanFull(),
                        ];
                    }
                    
                    $dnsRecord = $record->dkim->getDnsRecord();
                    
                    return [
                        Grid::make(2)
                            ->schema([
                                Placeholder::make('status')
                                    ->label('Status')
                                    ->content('Active ✓')
                                    ->extraAttributes(['class' => 'text-green-500 font-semibold']),
                                Placeholder::make('selector')
                                    ->label('Selector')
                                    ->content($record->dkim->selector),
                            ]),
                        
                        TextInput::make('dns_name')
                            ->label('DNS Record Name')
                            ->default($dnsRecord['name'])
                            ->disabled()
                            ->helperText('Create a TXT record with this name')
                            ->extraInputAttributes(['class' => 'font-mono text-sm']),
                        
                        TextInput::make('dns_type')
                            ->label('DNS Record Type')
                            ->default('TXT')
                            ->disabled()
                            ->helperText('Record type must be TXT'),
                        
                        Textarea::make('dns_value')
                            ->label('DNS Record Value')
                            ->default($dnsRecord['value'])
                            ->disabled()
                            ->rows(4)
                            ->helperText('Copy this entire value into the TXT record')
                            ->extraInputAttributes(['class' => 'font-mono text-sm break-all']),
                        
                        Textarea::make('public_key')
                            ->label('Public Key (for reference)')
                            ->default($record->dkim->public_key)
                            ->disabled()
                            ->rows(2)
                            ->helperText('This is the raw public key (for informational purposes only)')
                            ->columnSpanFull()
                            ->extraInputAttributes(['class' => 'font-mono text-sm']),
                            
                        Placeholder::make('private_key_note')
                            ->label('Private Key')
                            ->content('The private key is securely stored in the database and is not displayed for security reasons.')
                            ->extraAttributes(['class' => 'text-gray-500 text-sm']),
                    ];
                }),
                
            Action::make('generateDKIM')
                ->label('Generate DKIM Keys')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Generate DKIM Keys')
                ->modalDescription('This will generate a new 2048-bit RSA key pair for DKIM signing. The existing key (if any) will be replaced.')
                ->modalSubmitActionLabel('Generate')
                ->action(function ($record, DKIMKeyService $dkimService) {
                    try {
                        $dkim = $dkimService->generateKeys($record, 'default');
                        $dnsRecord = $dkim->getDnsRecord();
                        
                        // This will be picked up by dns-core and routed to the appropriate DNS provider
                        event(new \App\Events\DkimKeyGenerated(
                            zone: $this->record->domain,
                            name: $dnsRecord['name'],
                            type: 'TXT',
                            content: $dnsRecord['value'],
                            ttl: 3600,
                            operation: 'create'
                        ));                        
                        
                        // Show success notification with a prompt to view details
                        Notification::make()
                            ->title('DKIM Keys Generated Successfully')
                            ->body("Click 'View DKIM Details' to see the DNS record you need to add.")
                            ->success()
                            ->duration(8000)
                            ->send();
                        
                        // Refresh the page to show updated status
                        $this->refresh();
                        
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Error Generating DKIM Keys')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
                
            Action::make('disableDKIM')
                ->label('Disable DKIM')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Disable DKIM Signing')
                ->modalDescription('This will disable DKIM signing for this domain. Emails will still be sent without DKIM signatures.')
                ->modalSubmitActionLabel('Yes, Disable')
                ->visible(function ($record) {
                    return $record->dkim && $record->dkim->enabled;
                })
                ->action(function ($record) {
                    if ($record->dkim) {
                        $record->dkim->update(['enabled' => false]);
                        
                        Notification::make()
                            ->title('DKIM Disabled')
                            ->body('DKIM signing has been disabled for this domain.')
                            ->success()
                            ->send();
                        
                        $this->refresh();
                    }
                }),
                
            Action::make('enableDKIM')
                ->label('Enable DKIM')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Enable DKIM Signing')
                ->modalDescription('This will enable DKIM signing for this domain. Make sure you have added the DNS TXT record first.')
                ->modalSubmitActionLabel('Yes, Enable')
                ->visible(function ($record) {
                    return $record->dkim && !$record->dkim->enabled;
                })
                ->action(function ($record) {
                    if ($record->dkim) {
                        $record->dkim->update(['enabled' => true]);
                        
                        Notification::make()
                            ->title('DKIM Enabled')
                            ->body('DKIM signing has been enabled for this domain.')
                            ->success()
                            ->send();
                        
                        $this->refresh();
                    }
                }),
                
            DeleteAction::make(),
        ];
    }
    
    protected function afterSave(): void
    {
        \VEximweb\Core\Domain\Filament\Resources\Schemas\DomainForm::runSaveHooks($this->record, $this->data);
    }    
}