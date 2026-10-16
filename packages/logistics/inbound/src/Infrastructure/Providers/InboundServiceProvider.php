<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Inbound\Application\Command\AnnounceAsn\AnnounceAsn;
use Logistics\Inbound\Application\Command\AnnounceAsn\AnnounceAsnHandler;
use Logistics\Inbound\Application\Command\CancelAsn\CancelAsn;
use Logistics\Inbound\Application\Command\CancelAsn\CancelAsnHandler;
use Logistics\Inbound\Application\Command\CloseAsn\CloseAsn;
use Logistics\Inbound\Application\Command\CloseAsn\CloseAsnHandler;
use Logistics\Inbound\Application\Command\LabelReceivedUnits\LabelReceivedUnits;
use Logistics\Inbound\Application\Command\LabelReceivedUnits\LabelReceivedUnitsHandler;
use Logistics\Inbound\Application\Command\RecordArrival\RecordArrival;
use Logistics\Inbound\Application\Command\RecordArrival\RecordArrivalHandler;
use Logistics\Inbound\Application\Command\RecordGoodsReceipt\RecordGoodsReceipt;
use Logistics\Inbound\Application\Command\RecordGoodsReceipt\RecordGoodsReceiptHandler;
use Logistics\Inbound\Application\Command\ScheduleAsn\ScheduleAsn;
use Logistics\Inbound\Application\Command\ScheduleAsn\ScheduleAsnHandler;
use Logistics\Inbound\Application\Query\GetAsn\GetAsn;
use Logistics\Inbound\Application\Query\GetAsn\GetAsnHandler;
use Logistics\Inbound\Application\Query\GetGoodsReceipt\GetGoodsReceipt;
use Logistics\Inbound\Application\Query\GetGoodsReceipt\GetGoodsReceiptHandler;
use Logistics\Inbound\Application\Query\GetReceivingDockCalendar\GetReceivingDockCalendar;
use Logistics\Inbound\Application\Query\GetReceivingDockCalendar\GetReceivingDockCalendarHandler;
use Logistics\Inbound\Application\Query\ListAsns\ListAsns;
use Logistics\Inbound\Application\Query\ListAsns\ListAsnsHandler;
use Logistics\Inbound\Application\Query\ListPendingArrivals\ListPendingArrivals;
use Logistics\Inbound\Application\Query\ListPendingArrivals\ListPendingArrivalsHandler;
use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Contract\DockQuotaReserver;
use Logistics\Inbound\Domain\Contract\GoodsReceiptRepository;
use Logistics\Inbound\Infrastructure\Capacity\CapacityDockQuotaReserver;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Repository\EloquentAsnRepository;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Repository\EloquentGoodsReceiptRepository;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Inbound (ASN/cita, cupos de andén y recepción con QC de sellers, Fase 2).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto.
 *
 * El cupo de andén se reserva por el puerto DockQuotaReserver, bindeado por defecto al adaptador que
 * habla con `capacity` por el CommandBus; así inbound se mantiene extraíble (el dominio no conoce
 * capacity). En un entorno sin capacity se puede rebindear el puerto a un doble sin tocar el dominio.
 */
final class InboundServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        AnnounceAsn::class => AnnounceAsnHandler::class,
        ScheduleAsn::class => ScheduleAsnHandler::class,
        RecordArrival::class => RecordArrivalHandler::class,
        RecordGoodsReceipt::class => RecordGoodsReceiptHandler::class,
        LabelReceivedUnits::class => LabelReceivedUnitsHandler::class,
        CloseAsn::class => CloseAsnHandler::class,
        CancelAsn::class => CancelAsnHandler::class,
        GetAsn::class => GetAsnHandler::class,
        ListAsns::class => ListAsnsHandler::class,
        ListPendingArrivals::class => ListPendingArrivalsHandler::class,
        GetReceivingDockCalendar::class => GetReceivingDockCalendarHandler::class,
        GetGoodsReceipt::class => GetGoodsReceiptHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(AsnRepository::class, EloquentAsnRepository::class);
        $this->app->bind(GoodsReceiptRepository::class, EloquentGoodsReceiptRepository::class);

        // Adaptador por defecto del cupo de andén: consume capacidad de recepción en `capacity`.
        $this->app->bind(DockQuotaReserver::class, static fn ($app): CapacityDockQuotaReserver => new CapacityDockQuotaReserver(
            $app->make(CommandBus::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'logistics-inbound');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
