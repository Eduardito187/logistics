<?php

declare(strict_types=1);

namespace Logistics\Shared\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\Maintenance\RowPruner;
use Logistics\Shared\Infrastructure\Bus\LaravelCommandBus;
use Logistics\Shared\Infrastructure\Bus\LaravelQueryBus;
use Logistics\Shared\Infrastructure\Bus\TransactionalCommandBus;
use Logistics\Shared\Infrastructure\Clock\SystemClock;
use Logistics\Shared\Infrastructure\Maintenance\DbRowPruner;
use Logistics\Shared\Presentation\Console\MaintainDatabaseCommand;

/**
 * Registro del núcleo compartido. Va primero en bootstrap/providers.php: todos los contextos
 * registran sus handlers contra el HandlerRegistry singleton que vive acá.
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HandlerRegistry::class);

        // El CommandBus expuesto es el transaccional: envuelve al bus síncrono en una transacción de
        // base de datos para que el cambio de negocio y el append al outbox sean atómicos (ADR 002).
        // El límite transaccional vive acá, en Infrastructure, no en los handlers (Application es PHP puro).
        $this->app->singleton(CommandBus::class, function ($app): TransactionalCommandBus {
            $inner = new LaravelCommandBus($app, $app->make(HandlerRegistry::class));

            return new TransactionalCommandBus($inner, $app->make('db')->connection());
        });

        $this->app->singleton(QueryBus::class, function ($app): LaravelQueryBus {
            return new LaravelQueryBus($app, $app->make(HandlerRegistry::class));
        });

        $this->app->bind(Clock::class, SystemClock::class);

        // Puerto de poda de retención: borrado CHUNKED e idempotente de tablas de alta rotación. Vive en
        // el núcleo compartido porque lo reutiliza el Application de cada contexto (access, outbox,
        // telemetry) para podar sus propias tablas; el detalle del motor queda aislado en Infrastructure.
        $this->app->bind(RowPruner::class, DbRowPruner::class);
    }

    public function boot(): void
    {
        // Comando de mantenimiento físico de BD (ANALYZE / VACUUM ANALYZE de tablas calientes). Vive en
        // el núcleo compartido porque cruza todos los contextos; lo programa el scheduler.
        if ($this->app->runningInConsole()) {
            $this->commands([MaintainDatabaseCommand::class]);
        }
    }
}
