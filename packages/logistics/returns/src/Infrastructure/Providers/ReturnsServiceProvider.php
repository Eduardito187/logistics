<?php

declare(strict_types=1);

namespace Logistics\Returns\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Returns\Application\Command\DispositionReturn\DispositionReturn;
use Logistics\Returns\Application\Command\DispositionReturn\DispositionReturnHandler;
use Logistics\Returns\Application\Command\InitiateReturn\InitiateReturn;
use Logistics\Returns\Application\Command\InitiateReturn\InitiateReturnHandler;
use Logistics\Returns\Application\Command\ReceiveReturn\ReceiveReturn;
use Logistics\Returns\Application\Command\ReceiveReturn\ReceiveReturnHandler;
use Logistics\Returns\Application\Query\GetReturn\GetReturn;
use Logistics\Returns\Application\Query\GetReturn\GetReturnHandler;
use Logistics\Returns\Application\Query\ListReturns\ListReturns;
use Logistics\Returns\Application\Query\ListReturns\ListReturnsHandler;
use Logistics\Returns\Domain\Contract\ReturnOrderRepository;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Repository\EloquentReturnOrderRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Returns (logística inversa / devoluciones, ADR 017, Fase 4).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto. Bindea el repositorio, registra los pares
 * mensaje → handler en el bus CQRS compartido y carga sus migraciones.
 */
final class ReturnsServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        InitiateReturn::class => InitiateReturnHandler::class,
        ReceiveReturn::class => ReceiveReturnHandler::class,
        DispositionReturn::class => DispositionReturnHandler::class,
        GetReturn::class => GetReturnHandler::class,
        ListReturns::class => ListReturnsHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(ReturnOrderRepository::class, EloquentReturnOrderRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
