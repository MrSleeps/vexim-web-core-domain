<?php

namespace VEximweb\Core\Domain\Filament\Resources;

use VEximweb\Core\Domain\Filament\Resources\Pages\CreateDomain;
use VEximweb\Core\Domain\Filament\Resources\Pages\EditDomain;
use VEximweb\Core\Domain\Filament\Resources\Pages\ListDomains;
use VEximweb\Core\Domain\Filament\Resources\Schemas\DomainForm;
use VEximweb\Core\Domain\Filament\Resources\Tables\DomainsTable;
use VEximweb\Core\Data\Models\Domain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;
    
    protected static ?string $slug = 'domains';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'domain';
    
    protected static string|\UnitEnum|null $navigationGroup = 'Domain Management';
    
    protected static ?string $navigationLabel = 'Domains';
    
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return DomainForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DomainsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }
    
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        
        // If no user is logged in, return empty query
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }
        
        // System admins can see all domains
        if ($user->isSystemAdmin()) {
            return $query;
        }
        
        // Domain admins only see domains they're assigned to
        if ($user->isDomainAdmin()) {
            return $query->whereHas('administrators', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->where('role', 'domain_admin');
            });
        }
        
        // Domain users shouldn't see any domains
        return $query->whereRaw('1 = 0');
    }
    
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        
        // If no user is logged in
        if (!$user) {
            return null;
        }
        
        // System-admin sees all domains
        if ($user->isSystemAdmin()) {
            return (string) Domain::count();
        }
        
        // Domain-admin sees only their domains
        if ($user->isDomainAdmin()) {
            $count = Domain::whereHas('administrators', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->where('role', 'domain_admin');
            })->count();
            return (string) $count;
        }
        
        // Domain-user sees nothing
        return null;
    }
    
    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }
    
    public static function getNavigationBadgeTooltip(): ?string
    {
        $user = auth()->user();
        
        if (!$user) {
            return null;
        }
        
        if ($user->isSystemAdmin()) {
            return 'Total number of domains in the system';
        }
        
        if ($user->isDomainAdmin()) {
            return 'Total number of domains you administer';
        }
        
        return null;
    }
    
    // Hide navigation item for domain-users
    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        
        // Hide for domain-user
        if (!$user || $user->isDomainUser()) {
            return false;
        }
        
        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'edit' => EditDomain::route('/{record}/edit'),
        ];
    }
    
    public static function getGloballySearchableAttributes(): array
    {
        return ['domain'];
    }    
}