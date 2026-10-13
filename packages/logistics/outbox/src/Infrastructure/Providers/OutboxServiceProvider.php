<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Infrastructure\Dispatch\NullMessageDispatcher;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\EloquentOutboxStore;
use Logistics\Outbox\Presentation\Console\RelayOutboxCommand;

/**
 * Registro del bounded context Outbox.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto.
 */
final class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Puerto de persistencia: PostgreSQL vía Eloquent, con FOR UPDATE SKIP LOCKED en el pull.
        $this->app->bind(OutboxStore::class, EloquentOutboxStore::class);

        // Dispatcher por defecto: no envía nada (solo log). Cada canal real reemplaza este binding.
        $this->app->bind(MessageDispatcher::class, NullMessageDispatcher::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([RelayOutboxCommand::class]);
        }
    }
}
