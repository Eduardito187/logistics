<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromise;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromiseHandler;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservation;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservationHandler;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromise;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromiseHandler;
use Logistics\Promise\Application\Command\ReserveDeliverySlot\ReserveDeliverySlot;
use Logistics\Promise\Application\Command\ReserveDeliverySlot\ReserveDeliverySlotHandler;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromise;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromiseHandler;
use Logistics\Promise\Application\Query\GetPromise\GetPromise;
use Logistics\Promise\Application\Query\GetPromise\GetPromiseHandler;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlots;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlotsHandler;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedAvailabilityProvider;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedSlotReserver;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\EloquentPromiseRepository;
use Logistics\Promise\Infrastructure\Shipments\ShipmentsOrderDeliverySlotGateway;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Promesa de entrega.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class PromiseServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler del contexto (comandos y consultas).
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        QuotePromise::class => QuotePromiseHandler::class,
        ReservePromise::class => ReservePromiseHandler::class,
        ReserveDeliverySlot::class => ReserveDeliverySlotHandler::class,
        ConfirmPromise::class => ConfirmPromiseHandler::class,
        ExpireReservation::class => ExpireReservationHandler::class,
        ListAvailableSlots::class => ListAvailableSlotsHandler::class,
        GetPromise::class => GetPromiseHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(PromiseRepository::class, EloquentPromiseRepository::class);

        // Puertos hacia capacity: disponibilidad y reserva de cupo apoyadas en el contexto de capacidad
        // (cupos reales por zona/fecha/franja, con lock pesimista en el consumo). Reemplazan a los stubs
        // en memoria; el cruce es por el QueryBus/CommandBus, así promise no importa infraestructura ajena.
        $this->app->bind(AvailabilityProvider::class, CapacityBackedAvailabilityProvider::class);
        $this->app->bind(SlotReserver::class, CapacityBackedSlotReserver::class);

        // Puerto hacia shipments: leer y fijar la franja de entrega del pedido vía su comando/consulta.
        $this->app->bind(OrderDeliverySlotGateway::class, ShipmentsOrderDeliverySlotGateway::class);
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
