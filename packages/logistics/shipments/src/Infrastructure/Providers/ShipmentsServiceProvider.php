<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registro del bounded context Recepción de envíos.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class ShipmentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }
}
