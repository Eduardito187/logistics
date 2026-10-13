<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Shipments\Application\Command\GeocodeShipmentAddress\GeocodeShipmentAddress;
use Logistics\Shipments\Application\Command\GeocodeShipmentAddress\GeocodeShipmentAddressHandler;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipmentHandler;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\Query\FindShipment\FindShipmentHandler;
use Logistics\Shipments\Application\Query\ListLowConfidenceAddresses\ListLowConfidenceAddresses;
use Logistics\Shipments\Application\Query\ListLowConfidenceAddresses\ListLowConfidenceAddressesHandler;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\Query\ListShipments\ListShipmentsHandler;
use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Infrastructure\Geocoding\NullAddressConfidenceScorer;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\EloquentShipmentRepository;
use Logistics\Shipments\Presentation\Console\ReceiveManualShipmentCommand;

/**
 * Registro del bounded context Recepción de envíos.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class ShipmentsServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler del contexto (comandos y consultas).
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        ReceiveShipment::class => ReceiveShipmentHandler::class,
        GeocodeShipmentAddress::class => GeocodeShipmentAddressHandler::class,
        FindShipment::class => FindShipmentHandler::class,
        ListShipments::class => ListShipmentsHandler::class,
        ListLowConfidenceAddresses::class => ListLowConfidenceAddressesHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(ShipmentRepository::class, EloquentShipmentRepository::class);
        $this->app->bind(AddressConfidenceScorer::class, NullAddressConfidenceScorer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ReceiveManualShipmentCommand::class]);
        }
    }
}
