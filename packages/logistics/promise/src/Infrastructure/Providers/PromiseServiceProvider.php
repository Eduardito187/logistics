<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromise;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromiseHandler;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservation;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservationHandler;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromise;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromiseHandler;
use Logistics\Promise\Application\Command\ReserveDeliverySlot\ReserveDeliverySlot;
use Logistics\Promise\Application\Command\ReserveDeliverySlot\ReserveDeliverySlotHandler;
use Logistics\Promise\Application\Command\ReserveMunicipalitySlot\ReserveMunicipalitySlot;
use Logistics\Promise\Application\Command\ReserveMunicipalitySlot\ReserveMunicipalitySlotHandler;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromise;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromiseHandler;
use Logistics\Promise\Application\Query\CalculateDeliveryPromise\CalculateDeliveryPromise;
use Logistics\Promise\Application\Query\CalculateDeliveryPromise\CalculateDeliveryPromiseHandler;
use Logistics\Promise\Application\Query\GetPromise\GetPromise;
use Logistics\Promise\Application\Query\GetPromise\GetPromiseHandler;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlots;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlotsHandler;
use Logistics\Promise\Application\Query\ListMunicipalityPromise\ListMunicipalityPromise;
use Logistics\Promise\Application\Query\ListMunicipalityPromise\ListMunicipalityPromiseHandler;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\Contract\CapacityCalendar;
use Logistics\Promise\Domain\Contract\CorridorAvailabilityProvider;
use Logistics\Promise\Domain\Contract\CoverageProfileProvider;
use Logistics\Promise\Domain\Contract\FulfillmentLeadTimeProvider;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchCalendar;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchGate;
use Logistics\Promise\Domain\Contract\MunicipalityResolver;
use Logistics\Promise\Domain\Contract\MunicipalitySlotReserver;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Contract\ShipmentLocator;
use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Infrastructure\Availability\CachingAvailabilityProvider;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedAvailabilityProvider;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedCapacityCalendar;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedMunicipalityDispatchCalendar;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedMunicipalitySlotReserver;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedSlotReserver;
use Logistics\Promise\Infrastructure\Availability\DispatchDayGate;
use Logistics\Promise\Infrastructure\Cache\CacheInvalidatingPromiseRepository;
use Logistics\Promise\Infrastructure\Cache\CachingGetPromiseHandler;
use Logistics\Promise\Infrastructure\Coverage\CoverageBackedMunicipalityResolver;
use Logistics\Promise\Infrastructure\Coverage\CoverageBackedProfileProvider;
use Logistics\Promise\Infrastructure\Fulfillment\ConfigurableFulfillmentLeadTimeProvider;
use Logistics\Promise\Infrastructure\Fulfillment\SellerBackedFulfillmentLeadTimeProvider;
use Logistics\Promise\Infrastructure\Network\NetworkBackedCorridorProvider;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\EloquentPromiseRepository;
use Logistics\Promise\Infrastructure\Shipments\ShipmentsOrderDeliverySlotGateway;
use Logistics\Promise\Infrastructure\Shipments\ShipmentsShipmentLocator;
use Logistics\Promise\Presentation\Console\ExpireReservationsCommand;
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
        ReserveMunicipalitySlot::class => ReserveMunicipalitySlotHandler::class,
        ConfirmPromise::class => ConfirmPromiseHandler::class,
        ExpireReservation::class => ExpireReservationHandler::class,
        ListAvailableSlots::class => ListAvailableSlotsHandler::class,
        ListMunicipalityPromise::class => ListMunicipalityPromiseHandler::class,
        CalculateDeliveryPromise::class => CalculateDeliveryPromiseHandler::class,
        GetPromise::class => CachingGetPromiseHandler::class,
    ];

    public function register(): void
    {
        // Repositorio de promesas envuelto por el decorador que invalida el cache del modelo de lectura
        // (frente E — escala): guarda igual que el Eloquent y, al guardar, olvida la vista cacheada de esa
        // promesa. No cachea lecturas, así los comandos que cargan-mutan-guardan siguen leyendo de la base.
        $this->app->bind(PromiseRepository::class, function ($app): PromiseRepository {
            return new CacheInvalidatingPromiseRepository(
                $app->make(EloquentPromiseRepository::class),
                $app->make(CacheRepository::class),
            );
        });

        // Lectura de promesa por id con cache (Valkey) de su modelo de lectura en forma primitiva (regla #7),
        // invalidado por evento desde el repositorio. El handler plano queda como dependencia interna.
        $this->app->bind(CachingGetPromiseHandler::class, function ($app): CachingGetPromiseHandler {
            return new CachingGetPromiseHandler(
                $app->make(GetPromiseHandler::class),
                $app->make(CacheRepository::class),
            );
        });

        // Puertos hacia capacity: disponibilidad y reserva de cupo apoyadas en el contexto de capacidad
        // (cupos reales por zona/fecha/franja, con lock pesimista en el consumo). Reemplazan a los stubs
        // en memoria; el cruce es por el QueryBus/CommandBus, así promise no importa infraestructura ajena.
        // La disponibilidad se sirve con cache (Valkey) de las franjas en forma primitiva (regla #7),
        // invalidado por la generación de cupos del día que sube capacity.
        $this->app->bind(AvailabilityProvider::class, function ($app): AvailabilityProvider {
            return new CachingAvailabilityProvider(
                $app->make(CapacityBackedAvailabilityProvider::class),
                $app->make(CacheRepository::class),
            );
        });
        $this->app->bind(SlotReserver::class, CapacityBackedSlotReserver::class);

        // Puertos del cálculo integrado de promesa (CalculateDeliveryPromise): cobertura (perfil de la
        // zona), corredor (red) y calendario de capacidad (cupo libre por franja). Cada uno lee de solo
        // consulta su contexto; el servicio de dominio los combina. El cruce es por el bus y por Records,
        // así promise no agrega dependencia composer a coverage/network.
        $this->app->bind(CoverageProfileProvider::class, CoverageBackedProfileProvider::class);
        $this->app->bind(CorridorAvailabilityProvider::class, NetworkBackedCorridorProvider::class);
        $this->app->bind(CapacityCalendar::class, CapacityBackedCapacityCalendar::class);

        // Puerto hacia shipments: leer y fijar la franja de entrega del pedido vía su comando/consulta.
        $this->app->bind(OrderDeliverySlotGateway::class, ShipmentsOrderDeliverySlotGateway::class);

        // Eje MUNICIPIO del canal del cliente (K-03/K-05): resolver el municipio de una coordenada/envío
        // y ofrecer/reservar sobre el calendario de despacho por municipio. Cada puerto cruza a su contexto
        // (coverage/shipments/capacity) por el bus o por su puerto público, sin dependencia composer nueva.
        $this->app->bind(MunicipalityResolver::class, CoverageBackedMunicipalityResolver::class);
        $this->app->bind(ShipmentLocator::class, ShipmentsShipmentLocator::class);
        $this->app->bind(MunicipalityDispatchCalendar::class, CapacityBackedMunicipalityDispatchCalendar::class);
        $this->app->bind(MunicipalityDispatchGate::class, DispatchDayGate::class);
        $this->app->bind(MunicipalitySlotReserver::class, CapacityBackedMunicipalitySlotReserver::class);

        // Lead time adicional del marketplace en la promesa (ver docs/marketplace-dropship-roadmap.md §8.1):
        // para retail (OWNED) es cero y la promesa no cambia; para FBD/DROPSHIP se suma el lead del seller.
        // La política de días por modo sale de config con un fallback conservador; el adaptador por seller
        // la decora para respetar un lead por seller cuando su ficha lo tenga (hoy cae al default por modo).
        $this->app->bind(FulfillmentLeadTimeProvider::class, function ($app): FulfillmentLeadTimeProvider {
            $fbdDays = (int) config('promise.fulfillment_lead_days.fbd', 2);
            $dropshipDays = (int) config('promise.fulfillment_lead_days.dropship', 3);

            return new SellerBackedFulfillmentLeadTimeProvider(
                new ConfigurableFulfillmentLeadTimeProvider($fbdDays, $dropshipDays),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        if ($this->app->runningInConsole()) {
            // Worker del barrido de reservas vencidas; el scheduler lo corre cada minuto (ver routes/console.php).
            $this->commands([ExpireReservationsCommand::class]);
        }

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
