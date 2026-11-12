<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Providers;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTrackingHandler;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTrackingHandler;
use Logistics\Tracking\Application\Command\MarkRatingRequested\MarkRatingRequested;
use Logistics\Tracking\Application\Command\MarkRatingRequested\MarkRatingRequestedHandler;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTrackingHandler;
use Logistics\Tracking\Application\Command\PlaceShipmentOnRoute\PlaceShipmentOnRoute;
use Logistics\Tracking\Application\Command\PlaceShipmentOnRoute\PlaceShipmentOnRouteHandler;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEventHandler;
use Logistics\Tracking\Application\Command\ProjectUnitExecutionEvent\ProjectUnitExecutionEvent;
use Logistics\Tracking\Application\Command\ProjectUnitExecutionEvent\ProjectUnitExecutionEventHandler;
use Logistics\Tracking\Application\Command\RecordSatisfactionSurvey\RecordSatisfactionSurvey;
use Logistics\Tracking\Application\Command\RecordSatisfactionSurvey\RecordSatisfactionSurveyHandler;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPositionHandler;
use Logistics\Tracking\Application\Contract\CustomerContactReader;
use Logistics\Tracking\Application\Contract\CustomerEmailBackfill;
use Logistics\Tracking\Application\Contract\DeliveredAtBackfill;
use Logistics\Tracking\Application\Contract\PublicLinkTtl;
use Logistics\Tracking\Application\Contract\RatingRequestStore;
use Logistics\Tracking\Application\Query\GetPublicLinkState\GetPublicLinkState;
use Logistics\Tracking\Application\Query\GetPublicLinkState\GetPublicLinkStateHandler;
use Logistics\Tracking\Application\Query\GetPublicSurveyState\GetPublicSurveyState;
use Logistics\Tracking\Application\Query\GetPublicSurveyState\GetPublicSurveyStateHandler;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTracking;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTrackingHandler;
use Logistics\Tracking\Application\Query\GetPublicUnits\GetPublicUnits;
use Logistics\Tracking\Application\Query\GetPublicUnits\GetPublicUnitsHandler;
use Logistics\Tracking\Application\Query\GetSatisfactionSummary\GetSatisfactionSummary;
use Logistics\Tracking\Application\Query\GetSatisfactionSummary\GetSatisfactionSummaryHandler;
use Logistics\Tracking\Application\Query\GetShipmentTracking\GetShipmentTracking;
use Logistics\Tracking\Application\Query\GetShipmentTracking\GetShipmentTrackingHandler;
use Logistics\Tracking\Application\Query\GetShipmentUnits\GetShipmentUnits;
use Logistics\Tracking\Application\Query\GetShipmentUnits\GetShipmentUnitsHandler;
use Logistics\Tracking\Application\Query\ListDeliveredAwaitingRating\ListDeliveredAwaitingRating;
use Logistics\Tracking\Application\Query\ListDeliveredAwaitingRating\ListDeliveredAwaitingRatingHandler;
use Logistics\Tracking\Domain\Contract\SatisfactionSurveyRepository;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Infrastructure\Persistence\Backfill\SqlCustomerEmailBackfill;
use Logistics\Tracking\Infrastructure\Persistence\Backfill\SqlDeliveredAtBackfill;
use Logistics\Tracking\Infrastructure\Persistence\DbRatingRequestStore;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\EloquentSatisfactionSurveyRepository;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\EloquentTrackingRepository;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\EloquentUnitTrackingRepository;
use Logistics\Tracking\Infrastructure\ReadSide\DbCustomerContactReader;
use Logistics\Tracking\Infrastructure\Settings\SettingsPublicLinkTtl;
use Logistics\Tracking\Presentation\Console\BackfillCustomerEmailCommand;
use Logistics\Tracking\Presentation\Console\BackfillDeliveredAtCommand;
use Logistics\Tracking\Presentation\Http\Controller\PublicTrackingPageController;
use Logistics\Tracking\Presentation\Http\Controller\RecordSatisfactionSurveyController;

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
        $this->app->bind(SatisfactionSurveyRepository::class, EloquentSatisfactionSurveyRepository::class);

        // TTL del enlace público desde Configuración, con default 72 h si no está publicado. `scoped`:
        // memoiza dentro del request/job (la página pública se pollea) y se reinicia entre requests.
        $this->app->scoped(PublicLinkTtl::class, SettingsPublicLinkTtl::class);

        // Backfill idempotente de delivered_at (comando logistics:tracking:backfill-delivered-at).
        $this->app->bind(DeliveredAtBackfill::class, static function ($app): SqlDeliveredAtBackfill {
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new SqlDeliveredAtBackfill($connections);
        });

        // Prerrequisito de la calificación: contacto del cliente desde el lado lectura de shipments y store
        // de invitaciones sobre tracking_shipments (email capturado, candidatos, marca de invitado).
        $this->app->bind(CustomerContactReader::class, static function ($app): DbCustomerContactReader {
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new DbCustomerContactReader($connections);
        });
        $this->app->bind(RatingRequestStore::class, static function ($app): DbRatingRequestStore {
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new DbRatingRequestStore($connections);
        });

        // Backfill idempotente de customer_email (comando logistics:tracking:backfill-customer-email).
        $this->app->bind(CustomerEmailBackfill::class, static function ($app): SqlCustomerEmailBackfill {
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new SqlCustomerEmailBackfill($connections);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Vistas Blade del contexto (página pública de rastreo), bajo el namespace "tracking::".
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'tracking');

        $this->registerPublicPageRoute();

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillDeliveredAtCommand::class, BackfillCustomerEmailCommand::class]);
        }

        // Los handlers se registran contra el HandlerRegistry singleton del núcleo compartido.
        // El bus los resuelve del contenedor, así reciben sus dependencias inyectadas.
        $registry = $this->app->make(HandlerRegistry::class);
        $registry->register(OpenTracking::class, OpenTrackingHandler::class);
        $registry->register(BindShipmentToTracking::class, BindShipmentToTrackingHandler::class);
        $registry->register(AdvanceTracking::class, AdvanceTrackingHandler::class);
        $registry->register(ProjectExecutionEvent::class, ProjectExecutionEventHandler::class);
        $registry->register(ProjectUnitExecutionEvent::class, ProjectUnitExecutionEventHandler::class);
        $registry->register(PlaceShipmentOnRoute::class, PlaceShipmentOnRouteHandler::class);
        $registry->register(UpdateTruckPosition::class, UpdateTruckPositionHandler::class);
        $registry->register(RecordSatisfactionSurvey::class, RecordSatisfactionSurveyHandler::class);
        $registry->register(MarkRatingRequested::class, MarkRatingRequestedHandler::class);
        $registry->register(GetPublicTracking::class, GetPublicTrackingHandler::class);
        $registry->register(GetShipmentTracking::class, GetShipmentTrackingHandler::class);
        $registry->register(GetShipmentUnits::class, GetShipmentUnitsHandler::class);
        $registry->register(GetPublicUnits::class, GetPublicUnitsHandler::class);
        $registry->register(GetPublicSurveyState::class, GetPublicSurveyStateHandler::class);
        $registry->register(GetPublicLinkState::class, GetPublicLinkStateHandler::class);
        $registry->register(GetSatisfactionSummary::class, GetSatisfactionSummaryHandler::class);
        $registry->register(ListDeliveredAwaitingRating::class, ListDeliveredAwaitingRatingHandler::class);
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

        // Endpoints PÚBLICOS de escritura por token (sin sesión ni CSRF: la credencial es el token). El
        // rate limit por IP acota el abuso. Devuelven JSON. No van bajo las puertas /api/v1 (que exigen
        // credenciales de canal/servicio): son parte de la página pública, igual que GET /t/{token}.
        Route::middleware('throttle:20,1')->group(function (): void {
            // Encuesta de satisfacción post-entrega (K-06).
            Route::post('/t/{token}/survey', RecordSatisfactionSurveyController::class)
                ->where('token', '[A-Za-z0-9_-]+')
                ->name('tracking.public.survey');
        });
    }
}
