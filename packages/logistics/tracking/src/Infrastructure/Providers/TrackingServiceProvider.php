<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTrackingHandler;
use Logistics\Tracking\Application\Command\PlaceShipmentOnRoute\PlaceShipmentOnRoute;
use Logistics\Tracking\Application\Command\PlaceShipmentOnRoute\PlaceShipmentOnRouteHandler;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEventHandler;
use Logistics\Tracking\Application\Command\ProjectUnitExecutionEvent\ProjectUnitExecutionEvent;
use Logistics\Tracking\Application\Command\ProjectUnitExecutionEvent\ProjectUnitExecutionEventHandler;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPositionHandler;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTracking;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTrackingHandler;
use Logistics\Tracking\Application\Query\GetPublicUnits\GetPublicUnits;
use Logistics\Tracking\Application\Query\GetPublicUnits\GetPublicUnitsHandler;
use Logistics\Tracking\Application\Query\GetShipmentTracking\GetShipmentTracking;
use Logistics\Tracking\Application\Query\GetShipmentTracking\GetShipmentTrackingHandler;
use Logistics\Tracking\Application\Query\GetShipmentUnits\GetShipmentUnits;
use Logistics\Tracking\Application\Query\GetShipmentUnits\GetShipmentUnitsHandler;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\EloquentTrackingRepository;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\EloquentUnitTrackingRepository;
use Logistics\Tracking\Presentation\Http\Controller\PublicTrackingPageController;

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
        $this->app->bind(UnitTrackingRepository::class, EloquentUnitTrackingRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Vistas Blade del contexto (página pública de rastreo), bajo el namespace "tracking::".
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'tracking');

        $this->registerPublicPageRoute();

        // Los handlers se registran contra el HandlerRegistry singleton del núcleo compartido.
        // El bus los resuelve del contenedor, así reciben sus dependencias inyectadas.
        $registry = $this->app->make(HandlerRegistry::class);
        $registry->register(OpenTracking::class, OpenTrackingHandler::class);
        $registry->register(ProjectExecutionEvent::class, ProjectExecutionEventHandler::class);
        $registry->register(ProjectUnitExecutionEvent::class, ProjectUnitExecutionEventHandler::class);
        $registry->register(PlaceShipmentOnRoute::class, PlaceShipmentOnRouteHandler::class);
        $registry->register(UpdateTruckPosition::class, UpdateTruckPositionHandler::class);
        $registry->register(GetPublicTracking::class, GetPublicTrackingHandler::class);
        $registry->register(GetShipmentTracking::class, GetShipmentTrackingHandler::class);
        $registry->register(GetShipmentUnits::class, GetShipmentUnitsHandler::class);
        $registry->register(GetPublicUnits::class, GetPublicUnitsHandler::class);
    }

    /**
     * Ruta web PÚBLICA de la página de rastreo: dismac.bo/t/{token}. Va en la pila "web" (sin auth: es
     * pública por diseño) y se registra desde el package para mantener el contexto autocontenido. El
     * token es base64url; la restricción evita colisiones con otras rutas web.
     */
    private function registerPublicPageRoute(): void
    {
        Route::middleware('web')
            ->get('/t/{token}', PublicTrackingPageController::class)
            ->where('token', '[A-Za-z0-9_-]+')
            ->name('tracking.public.page');
    }
}
