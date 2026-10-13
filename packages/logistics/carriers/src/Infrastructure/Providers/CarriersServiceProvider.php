<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Carriers\Application\Command\RateCarrier\RateCarrier;
use Logistics\Carriers\Application\Command\RateCarrier\RateCarrierHandler;
use Logistics\Carriers\Application\Command\RegisterCarrier\RegisterCarrier;
use Logistics\Carriers\Application\Command\RegisterCarrier\RegisterCarrierHandler;
use Logistics\Carriers\Application\Command\SetTariff\SetTariff;
use Logistics\Carriers\Application\Command\SetTariff\SetTariffHandler;
use Logistics\Carriers\Application\Command\SuspendCarrier\SuspendCarrier;
use Logistics\Carriers\Application\Command\SuspendCarrier\SuspendCarrierHandler;
use Logistics\Carriers\Application\Query\CheapestCarrierForCorridor\CheapestCarrierForCorridor;
use Logistics\Carriers\Application\Query\CheapestCarrierForCorridor\CheapestCarrierForCorridorHandler;
use Logistics\Carriers\Application\Query\GetTariffForCorridor\GetTariffForCorridor;
use Logistics\Carriers\Application\Query\GetTariffForCorridor\GetTariffForCorridorHandler;
use Logistics\Carriers\Application\Query\ListCarriers\ListCarriers;
use Logistics\Carriers\Application\Query\ListCarriers\ListCarriersHandler;
use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Contract\TariffRepository;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Repository\EloquentCarrierRepository;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Repository\EloquentTariffRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Transportistas (tarifas por corredor y calificación).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class CarriersServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        RegisterCarrier::class => RegisterCarrierHandler::class,
        SuspendCarrier::class => SuspendCarrierHandler::class,
        SetTariff::class => SetTariffHandler::class,
        RateCarrier::class => RateCarrierHandler::class,
        ListCarriers::class => ListCarriersHandler::class,
        GetTariffForCorridor::class => GetTariffForCorridorHandler::class,
        CheapestCarrierForCorridor::class => CheapestCarrierForCorridorHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(CarrierRepository::class, EloquentCarrierRepository::class);
        $this->app->bind(TariffRepository::class, EloquentTariffRepository::class);
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
