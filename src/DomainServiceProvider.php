<?php
namespace VEximweb\Core\Domain;

use Filament\Panel;
use Illuminate\Support\ServiceProvider;
use VEximweb\Core\Data\Repositories\Interfaces\DomainRepositoryInterface;
use VEximweb\Core\Data\Repositories\DomainRepository;
use VEximweb\Core\Domain\Services\DomainAdminService;
use VEximweb\Core\Domain\Services\DomainAdminLimitService;

class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/domain.php',
            'domain'
        );
        
        // Bind plugin repositories
        $this->bindRepositories();
        
        // Bind plugin Services
        $this->bindServices();
        
        Panel::configureUsing(function (Panel $panel) {
            $panel->plugin(DomainPlugin::make());
        });        
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        //$this->loadViewsFrom(__DIR__ . '/../resources/views', 'domain');
        //$this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->publishes([
            __DIR__ . '/../config/domain.php' => config_path('domain.php'),
        ], 'domain-config');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([

            ]);
        }
    }
    
    /**
     * Bind all repositories to their interfaces.
     */
    protected function bindRepositories(): void
    {
        $this->app->bind(DomainRepositoryInterface::class, DomainRepository::class);
    }    
    
    /**
     * Bind all services to the container.
     */
    protected function bindServices(): void
    {
        
        $this->app->singleton(DomainAdminService::class, function ($app) {
            $repository = $app->make(\VEximweb\Core\Data\Repositories\Interfaces\SettingRepositoryInterface::class);
            return new DomainAdminService($repository);
        });

        $this->app->singleton(DomainAdminLimitService::class, function ($app) {
            $repository = $app->make(\VEximweb\Core\Data\Repositories\Interfaces\SettingRepositoryInterface::class);
            return new DomainAdminLimitService($repository);
        });        

    }    
}
