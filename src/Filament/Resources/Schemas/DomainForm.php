<?php

namespace VEximweb\Core\Domain\Filament\Resources\Schemas;

use VEximweb\Core\Data\Models\Setting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DomainForm
{
    public static function extend(callable $components, callable $onSave): void
    {
        $existing = app()->bound('domainform.extenders')
            ? app('domainform.extenders')
            : ['components' => [], 'hooks' => []];

        $existing['components'][] = $components;
        $existing['hooks'][] = $onSave;

        app()->instance('domainform.extenders', $existing);
    }

    public static function runSaveHooks(mixed $record, array $data): void
    {
        if (! app()->bound('domainform.extenders')) {
            return;
        }

        foreach (app('domainform.extenders')['hooks'] as $hook) {
            $hook($record, $data);
        }
    }

    public static function configure(Schema $schema): Schema
    {
        $extra = [];
        if (app()->bound('domainform.extenders')) {
            foreach (app('domainform.extenders')['components'] as $extender) {
                $extra = array_merge($extra, $extender());
            }
        }

        return $schema
            ->components([
                Section::make('Domain Information')
                    ->schema([
                        TextInput::make('domain')
                            ->required()
                            ->maxLength(255)
                            ->helperText('e.g., example.com')
                            ->live(debounce: 500)
                            ->afterStateUpdated(function ($state, callable $set) {
                                $mailRoot = Setting::get('mail_root', '/var/mail/');
                                if ($state) {
                                    $set('maildir', rtrim($mailRoot, '/') . '/' . $state);
                                }
                            }),
                        TextInput::make('maildir')
                            ->required()
                            ->maxLength(4096)
                            ->helperText('Path to mail storage directory (auto-generated from domain name)')
                            ->disabled()
                            ->dehydrated(true),
                        TextInput::make('uid')
                            ->required()
                            ->numeric()
                            ->default(65534)
                            ->helperText('System UID for mail storage')
                            ->disabled(function () {
                                $allowDomainAdminUidGid = Setting::get('allow_domainadmin_uid_gid', 0);
                                $user = auth()->user();
                                return ($allowDomainAdminUidGid == 0 && $user && $user->isDomainAdmin());
                            })
                            ->dehydrated(true),
                        TextInput::make('gid')
                            ->required()
                            ->numeric()
                            ->default(65534)
                            ->helperText('System GID for mail storage')
                            ->disabled(function () {
                                $allowDomainAdminUidGid = Setting::get('allow_domainadmin_uid_gid', 0);
                                $user = auth()->user();
                                return ($allowDomainAdminUidGid == 0 && $user && $user->isDomainAdmin());
                            })
                            ->dehydrated(true),
                        Toggle::make('enabled')
                            ->required()
                            ->default(true),
                    ])->columns(2),

                Section::make('Limits & Quotas')
                    ->schema([
                        TextInput::make('max_accounts')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->label('Max Email Accounts')
                            ->helperText('0 = unlimited'),
                        TextInput::make('quotas')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->label('Domain Quota (MB)')
                            ->helperText('0 = unlimited'),
                        TextInput::make('maxmsgsize')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->label('Max Message Size (KB)')
                            ->helperText('0 = unlimited'),
                    ])->columns(2),

                Section::make('Security Features')
                    ->schema([
                        Toggle::make('avscan')
                            ->label('Antivirus Scanning')
                            ->default(false),
                        Toggle::make('spamassassin')
                            ->label('SpamAssassin Filtering')
                            ->default(false),
                        Toggle::make('blocklists')
                            ->label('Blocklists Enabled')
                            ->default(false),
                        Toggle::make('whitelists')
                            ->label('Whitelists Enabled')
                            ->default(false),
                    ])->columns(2),

                Section::make('Spamassassin')
                    ->schema([
                        TextInput::make('sa_tag')
                            ->numeric()
                            ->default(0)
                            ->label('Spam Tag Score'),
                        TextInput::make('sa_refuse')
                            ->numeric()
                            ->default(0)
                            ->label('Spam Refuse Score'),
                    ])->columns(2),

                Section::make('Domain Administrators')
                    ->schema([
                        Select::make('administrator_ids')
                            ->label('Domain Administrators')
                            ->options(function () {
                                return \VEximweb\Core\Data\Models\User::role(['domain_admin'])
                                    ->get()
                                    ->mapWithKeys(function ($user) {
                                        $roleName = $user->roles->first()?->name;
                                        $roleBadge = $roleName === 'system_admin' ? 'System Admin' : 'Domain Admin';
                                        return [$user->id => "{$user->name} ({$user->email}) {$roleBadge}"];
                                    });
                            })
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Select which web users can administer this domain')
                            ->afterStateHydrated(function ($component, $record) {
                                if ($record && $record->exists) {
                                    $component->state($record->administrators->pluck('id')->toArray());
                                }
                            })
                            ->saveRelationshipsUsing(function ($record, $state) {
                                \Illuminate\Support\Facades\Log::info('saveRelationshipsUsing called', [
                                    'record_id' => $record?->domain_id,
                                    'state' => $state,
                                    'called_at' => now()->toDateTimeString(),
                                ]);

                                if ($record && $state !== null) {
                                    $pivotData = [];
                                    foreach ($state as $userId) {
                                        $pivotData[$userId] = ['role' => 'domain_admin'];
                                    }
                                    $record->administrators()->sync($pivotData);
                                }
                            }),
                    ]),

                Section::make('DKIM Configuration')
                    ->schema([
                        Toggle::make('generate_dkim')
                            ->label('Generate DKIM Keys')
                            ->helperText('Automatically generate DKIM keys for email signing. You can view and manage DKIM settings after creation.')
                            ->default(Setting::get('auto_generate_dkim', false)),
                    ])
                    ->visible(function ($livewire) {
                        return $livewire instanceof \VEximweb\Core\Domain\Filament\Resources\Pages\CreateDomain;
                    }),

                Section::make('Additional Settings')
                    ->schema([
                        TextInput::make('type')
                            ->hidden()
                            ->default('local')
                            ->maxLength(5),
                        Toggle::make('mailinglists')
                            ->label('Mailing Lists Enabled')
                            ->default(false),
                    ])->columns(2),

                ...$extra,
            ]);
    }
}