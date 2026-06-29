<?php

namespace  VEximweb\Core\Domain\Filament\Resources\Tables;

use VEximweb\Core\Data\Models\Domain;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\Action;
use VEximweb\Core\EximUser\Filament\Resources\EximUserResource;
use VEximweb\Core\EximAlias\Filament\Resources\EximAliasResource;
use VEximweb\Core\EximCatchAll\Filament\Resources\EximCatchAllResource;
use VEximweb\Core\EximFail\Filament\Resources\EximFailResource;
use Filament\Support\Icons\Heroicon;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('max_accounts')
                    ->label('Max Accounts')
                    ->sortable(),
                TextColumn::make('quotas')
                    ->label('Quota (MB)')
                    ->sortable(),
                IconColumn::make('enabled')
                    ->boolean()
                    ->sortable(),
                    
                IconColumn::make('dkim.enabled')
                    ->label('DKIM')
                    ->boolean()
                    ->trueIcon('heroicon-o-key')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(function ($record): string {
                        if ($record->dkim && $record->dkim->enabled) {
                            return "DKIM active (selector: {$record->dkim->selector})";
                        } elseif ($record->dkim && !$record->dkim->enabled) {
                            return "DKIM keys exist but are disabled";
                        } else {
                            return "No DKIM keys configured";
                        }
                    }),
                    
                TextColumn::make('administrators.name')
                    ->label('Administrators')
                    ->badge()
                    ->separator(',')
                    ->limitList(3)
                    ->tooltip(function ($record) {
                        $admins = $record->administrators->pluck('name')->implode(', ');
                        return $admins ?: 'No administrators assigned';
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('enabled')
                    ->options([
                        '1' => 'Enabled',
                        '0' => 'Disabled',
                    ]),
                SelectFilter::make('spamassassin')
                    ->label('Spam Filtering')
                    ->options([
                        '1' => 'Enabled',
                        '0' => 'Disabled',
                    ]),
                SelectFilter::make('dkim_status')
                    ->label('DKIM Status')
                    ->options([
                        'has_dkim' => 'Has DKIM (Active)',
                        'has_disabled' => 'Has DKIM (Disabled)',
                        'no_dkim' => 'No DKIM',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if ($data['value'] === 'has_dkim') {
                            return $query->whereHas('dkim', function ($q) {
                                $q->where('enabled', true);
                            });
                        }
                        if ($data['value'] === 'has_disabled') {
                            return $query->whereHas('dkim', function ($q) {
                                $q->where('enabled', false);
                            });
                        }
                        if ($data['value'] === 'no_dkim') {
                            return $query->whereDoesntHave('dkim');
                        }
                        return $query;
                    }),
            ])
            ->recordActions([
                Action::make('viewLocal')
                    ->icon(Heroicon::Envelope)
                    ->label('')
                    ->tooltip('View local accounts for this domain')
                    ->url(function ($record) {
                        $baseUrl = EximUserResource::getUrl('index');
                        return $baseUrl . '?filters[domain_id][value]=' . $record->domain_id;
                    })
                    ->openUrlInNewTab(false),
                Action::make('viewAlias')
                    ->icon(Heroicon::ArrowUturnRight)
                    ->label('')
                    ->tooltip('View forwarding accounts for this domain')
                    ->url(function ($record) {
                        $baseUrl = EximAliasResource::getUrl('index');
                        return $baseUrl . '?filters[domain_id][value]=' . $record->domain_id;
                    })
                    ->openUrlInNewTab(false), 
                Action::make('viewACatchAll')
                    ->icon(Heroicon::Funnel)
                    ->label('')
                    ->tooltip('View catchall accounts for this domain')
                    ->url(function ($record) {
                        $baseUrl = EximCatchAllResource::getUrl('index');
                        return $baseUrl . '?filters[domain_id][value]=' . $record->domain_id;
                    })
                    ->openUrlInNewTab(false), 
                Action::make('viewFails')
                    ->icon(Heroicon::ExclamationTriangle)
                    ->label('')
                    ->tooltip('View fail accounts for this domain')
                    ->url(function ($record) {
                        $baseUrl = EximFailResource::getUrl('index');
                        return $baseUrl . '?filters[domain_id][value]=' . $record->domain_id;
                    })
                    ->openUrlInNewTab(false), 
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}