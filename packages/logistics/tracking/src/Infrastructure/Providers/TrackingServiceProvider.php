<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Providers;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Cache;
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
use Logistics\Tracking\Application\Command\SyncRouteStops\SyncRouteStops;
use Logistics\Tracking\Application\Command\SyncRouteStops\SyncRouteStopsHandler;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPositionHandler;
use Logistics\Tracking\Application\Contract\CustomerContactReader;
use Logistics\Tracking\Application\Contract\CustomerEmailBackfill;
use Logistics\Tracking\Application\Contract\DeliveredAtBackfill;
use Logistics\Tracking\Application\Contract\PublicLinkTtl;
use Logistics\Tracking\Application\Contract\PublicTrackingReader;
use Logistics\Tracking\Application\Contract\RatingRequestStore;
use Logistics\Tracking\Application\Contract\ReturnCoverageReader;
use Logistics\Tracking\Application\Contract\RouteStopsReader;
use Logistics\Tracking\Application\Contract\ShipmentOrderReferenceReader;
use Logistics\Tracking\Application\Query\GetPublicLinkState\GetPublicLinkState;
use Logistics\Tracking\Application\Query\GetPublicLinkState\GetPublicLinkStateHandler;
use Logistics\Tracking\Application\Query\GetPublicSurveyState\GetPublicSurveyState;
use Logistics\Tracking\Application\Query\GetPublicSurveyState\GetPublicSurveyStateHandler;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTracking;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTrackingHandler;
use Logistics\Tracking\Application\Query\GetPublicTrackingSnapshot\GetPublicTrackingSnapshot;
use Logistics\Tracking\Application\Query\GetPublicTrackingSnapshot\GetPublicTrackingSnapshotHandler;
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
use Logistics\Tracking\Application\Service\LivePublicTrackingReader;
use Logistics\Tracking\Domain\Contract\SatisfactionSurveyRepository;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Infrastructure\Persistence\Backfill\SqlCustomerEmailBackfill;
use Logistics\Tracking\Infrastructure\Persistence\Backfill\SqlDeliveredAtBackfill;
use Logistics\Tracking\Infrastructure\Persistence\CacheInvalidatingTrackingRepository;
use Logistics\Tracking\Infrastructure\Persistence\DbRatingRequestStore;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\EloquentSatisfactionSurveyRepository;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\EloquentTrackingRepository;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\EloquentUnitTrackingRepository;
use Logistics\Tracking\Infrastructure\ReadSide\CachedPublicTrackingReader;
use Logistics\Tracking\Infrastructure\ReadSide\DbCustomerContactReader;
use Logistics\Tracking\Infrastructure\ReadSide\DbReturnCoverageReader;
use Logistics\Tracking\Infrastructure\ReadSide\DbRouteStopsReader;
use Logistics\Tracking\Infrastructure\ReadSide\DbShipmentOrderReferenceReader;
use Logistics\Tracking\Infrastructure\ReadSide\PublicTrackingCache;
use Logistics\Tracking\Infrastructure\Settings\SettingsPublicLinkTtl;
use Logistics\Tracking\Presentation\Console\BackfillCustomerEmailCommand;
use Logistics\Tracking\Presentation\Console\BackfillDeliveredAtCommand;
use Logistics\Tracking\Presentation\Console\ReconcileTrackingFromExecutionCommand;
use Logistics\Tracking\Presentation\Console\SendSurveyInvitationsCommand;
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
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-tracking.php', 'logistics-tracking');

        $this->registerPublicReadSide();

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

        // Lado lectura cross-context: el order_reference de un envío, para ABRIR el seguimiento de un envío
        // sin fila en tracking (upsert de ProjectExecutionEventHandler). Solo lectura de `shipments`.
        $this->app->bind(ShipmentOrderReferenceReader::class, static function ($app): DbShipmentOrderReferenceReader {
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new DbShipmentOrderReferenceReader($connections);
        });

        // Lado lectura cross-context: cuánto de un pedido ya se devolvió, por producto (devolución parcial).
        $this->app->bind(ReturnCoverageReader::class, static function ($app): DbReturnCoverageReader {
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new DbReturnCoverageReader($connections);
        });

        // Lado lectura cross-context: paradas de la ruta real del camión (execution), para ubicar cada envío
        // en su ruta y contar las paradas pendientes por delante. Solo lectura.
        $this->app->bind(RouteStopsReader::class, static function ($app): DbRouteStopsReader {
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new DbRouteStopsReader($connections);
        });

        // Backfill idempotente de customer_email (comando logistics:tracking:backfill-customer-email).
        $this->app->bind(CustomerEmailBackfill::class, static function ($app): SqlCustomerEmailBackfill {
            $connections = $app['db'];
            assert($connections instanceof ConnectionResolverInterface);

            return new SqlCustomerEmailBackfill($connections);
        });
    }

    /**
     * Read-side PÚBLICO del seguimiento (ola 6): caché de lectura por token con invalidación por evento.
     * Diseño en docs/perf/tracking-read-side.md.
     *
     * - {@see PublicTrackingCache}: dueño de claves y TTL. Singleton sin estado de request (Octane-safe).
     * - {@see TrackingRepository}: el Eloquent envuelto por el decorador que invalida al grabar. Es el
     *   ÚNICO punto de invalidación: comandos y reactores del outbox pasan por `save()`.
     * - {@see PublicTrackingReader}: lector en vivo envuelto por el cacheado.
     *
     * EXTRACCIÓN (unidad `tracking-read`): el día que el read-side se despliegue aparte, este método y
     * {@see registerPublicReadRoutes()} son lo único que ese proceso necesita registrar; el lado de
     * escritura conserva el decorador del repositorio para seguir invalidando el Valkey compartido.
     */
    private function registerPublicReadSide(): void
    {
        $this->app->singleton(PublicTrackingCache::class, static function (): PublicTrackingCache {
            $storeName = config('logistics-tracking.public_cache.store');
            $store = Cache::store(is_string($storeName) && $storeName !== '' ? $storeName : null);
            assert($store instanceof CacheRepository);

            return new PublicTrackingCache(
                $store,
                (bool) config('logistics-tracking.public_cache.enabled', true),
                (int) config('logistics-tracking.public_cache.ttl_fresh', 20),
                (int) config('logistics-tracking.public_cache.ttl_stale', 120),
                (int) config('logistics-tracking.public_cache.ttl_negative', 15),
            );
        });

        $this->app->bind(
            TrackingRepository::class,
            static fn (Application $app): CacheInvalidatingTrackingRepository => new CacheInvalidatingTrackingRepository(
                $app->make(EloquentTrackingRepository::class),
                $app->make(PublicTrackingCache::class),
            ),
        );

        $this->app->bind(
            PublicTrackingReader::class,
            static fn (Application $app): CachedPublicTrackingReader => new CachedPublicTrackingReader(
                $app->make(LivePublicTrackingReader::class),
                $app->make(PublicTrackingCache::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Vistas Blade del contexto (página pública de rastreo), bajo el namespace "tracking::".
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'tracking');

        $this->registerPublicReadRoutes();
        $this->registerPublicWriteRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillDeliveredAtCommand::class,
                BackfillCustomerEmailCommand::class,
                SendSurveyInvitationsCommand::class,
                ReconcileTrackingFromExecutionCommand::class,
            ]);
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
        $registry->register(SyncRouteStops::class, SyncRouteStopsHandler::class);
        $registry->register(UpdateTruckPosition::class, UpdateTruckPositionHandler::class);
        $registry->register(RecordSatisfactionSurvey::class, RecordSatisfactionSurveyHandler::class);
        $registry->register(MarkRatingRequested::class, MarkRatingRequestedHandler::class);
        $registry->register(GetPublicTracking::class, GetPublicTrackingHandler::class);
        $registry->register(GetPublicTrackingSnapshot::class, GetPublicTrackingSnapshotHandler::class);
        $registry->register(GetShipmentTracking::class, GetShipmentTrackingHandler::class);
        $registry->register(GetShipmentUnits::class, GetShipmentUnitsHandler::class);
        $registry->register(GetPublicUnits::class, GetPublicUnitsHandler::class);
        $registry->register(GetPublicSurveyState::class, GetPublicSurveyStateHandler::class);
        $registry->register(GetPublicLinkState::class, GetPublicLinkStateHandler::class);
        $registry->register(GetSatisfactionSummary::class, GetSatisfactionSummaryHandler::class);
        $registry->register(ListDeliveredAwaitingRating::class, ListDeliveredAwaitingRatingHandler::class);
    }

    /**
     * Ruta PÚBLICA de LECTURA: la página de rastreo dismac.bo/t/{token}. Es el punto de entrada del
     * read-side público. El token es base64url; la restricción evita colisiones con otras rutas web.
     *
     * Va SIN la pila "web" a propósito: la página no usa sesión, CSRF ni cookies (es anónima y de solo
     * lectura). Con "web", cada visita abría una sesión —una ESCRITURA en Valkey por request— y devolvía
     * `Set-Cookie`, lo que además impide que un CDN o el micro-caché de nginx guarden la respuesta.
     *
     * EXTRACCIÓN (unidad `tracking-read`): este es el punto que se condiciona al rol de servicio. Cuando
     * exista el helper `App\Support\ServiceRole`, el registro en boot() queda así (hoy NO se depende de él):
     *
     *     if (ServiceRole::serves('tracking-read')) { $this->registerPublicReadRoutes(); }
     *     if (ServiceRole::serves('tracking'))      { $this->registerPublicWriteRoutes(); }
     *
     * Las rutas JSON del mismo read-side (`GET /api/v1/channels/tracking/{token}` y `.../units`) se
     * montan desde `routes/api/tracking.php` (puerta `channels`): se condicionan igual en ese fragmento.
     */
    private function registerPublicReadRoutes(): void
    {
        Route::get('/t/{token}', PublicTrackingPageController::class)
            ->where('token', '[A-Za-z0-9_-]+')
            ->name('tracking.public.page');
    }

    /**
     * Endpoints PÚBLICOS de ESCRITURA por token (sin sesión ni CSRF: la credencial es el token). El
     * rate limit por IP acota el abuso. Devuelven JSON. No van bajo las puertas /api/v1 (que exigen
     * credenciales de canal/servicio): son parte de la página pública, igual que GET /t/{token}. Al
     * extraer el read-side estas rutas se quedan en el lado de escritura.
     */
    private function registerPublicWriteRoutes(): void
    {
        Route::middleware('throttle:20,1')->group(function (): void {
            // Encuesta de satisfacción post-entrega (K-06).
            Route::post('/t/{token}/survey', RecordSatisfactionSurveyController::class)
                ->where('token', '[A-Za-z0-9_-]+')
                ->name('tracking.public.survey');
        });
    }
}
