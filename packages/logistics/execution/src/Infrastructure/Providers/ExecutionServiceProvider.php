<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDeliveryHandler;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScanHandler;
use Logistics\Execution\Application\Command\RecordNodeReception\RecordNodeReception;
use Logistics\Execution\Application\Command\RecordNodeReception\RecordNodeReceptionHandler;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScanHandler;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDeliveryHandler;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryState;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryStateHandler;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicle;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicleHandler;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Ejecución.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class ExecutionServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler del contexto (comandos y consultas).
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        RecordPreparationScan::class => RecordPreparationScanHandler::class,
        RecordLoadScan::class => RecordLoadScanHandler::class,
        RecordProofOfDelivery::class => RecordProofOfDeliveryHandler::class,
        RecordFailedDelivery::class => RecordFailedDeliveryHandler::class,
        RecordNodeReception::class => RecordNodeReceptionHandler::class,
        GetDeliveryState::class => GetDeliveryStateHandler::class,
        ListDeliveriesByVehicle::class => ListDeliveriesByVehicleHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(DeliveryRepository::class, EloquentDeliveryRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
