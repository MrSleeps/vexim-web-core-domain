<?php

namespace VEximweb\Core\Domain;

use Filament\Contracts\Plugin;
use Filament\Panel;
use VEximweb\Core\Domain\Filament\Resources\DomainResource;

class DomainPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());
        return $plugin;
    }       
    
    public function getId(): string
    {
        return 'domains';
    }

    public function register(Panel $panel): void
    {
        // Register the Group resource
        $panel->resources([
            DomainResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        // Any boot logic
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getName(): string
    {
        return 'Domain Management';
    }

    public function getDescription(): string
    {
        return 'Manage system domains';
    }
}
