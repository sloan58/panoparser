<?php

namespace App\Services\Panorama;

use Illuminate\Support\ServiceProvider;
use App\Services\Panorama\Contracts\XmlLoaderInterface;
use App\Services\Panorama\Contracts\CatalogBuilderInterface;
use App\Services\Panorama\Contracts\DereferencerInterface;
use App\Services\Panorama\Contracts\RuleEmitterInterface;

class PanoramaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Service bindings will be added as concrete implementations are created
        // This ensures proper dependency injection throughout the application
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}