<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTrackingHandler;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEventHandler;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPositionHandler;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTracking;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTrackingHandler;
use Logistics\Tracking\Application\Query\GetShipmentTracking\GetShipmentTracking;
use Logistics\Tracking\Application\Query\GetShipmentTracking\GetShipmentTrackingHandler;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\EloquentTrackingRepository;

/**
 * Registro del bounded context Tracking: estado visible del envío, página pública y posición del camión.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class TrackingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TrackingRepository::class, EloquentTrackingRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Los handlers se registran contra el HandlerRegistry singleton del núcleo compartido.
        // El bus los resuelve del contenedor, así reciben sus dependencias inyectadas.
        $registry = $this->app->make(HandlerRegistry::class);
        $registry->register(OpenTracking::class, OpenTrackingHandler::class);
        $registry->register(ProjectExecutionEvent::class, ProjectExecutionEventHandler::class);
        $registry->register(UpdateTruckPosition::class, UpdateTruckPositionHandler::class);
        $registry->register(GetPublicTracking::class, GetPublicTrackingHandler::class);
        $registry->register(GetShipmentTracking::class, GetShipmentTrackingHandler::class);
    }
}
