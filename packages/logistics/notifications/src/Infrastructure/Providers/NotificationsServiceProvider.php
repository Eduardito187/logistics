<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registro del bounded context Notificaciones al cliente.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }
}
