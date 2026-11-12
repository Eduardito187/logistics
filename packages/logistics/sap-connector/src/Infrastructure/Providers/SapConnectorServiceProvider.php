<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\ServiceProvider;
use Logistics\SapConnector\Application\Command\AcknowledgeStockTransfer\AcknowledgeStockTransfer;
use Logistics\SapConnector\Application\Command\AcknowledgeStockTransfer\AcknowledgeStockTransferHandler;
use Logistics\SapConnector\Application\Command\DefineMaterialMapping\DefineMaterialMapping;
use Logistics\SapConnector\Application\Command\DefineMaterialMapping\DefineMaterialMappingHandler;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfers;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfersHandler;
use Logistics\SapConnector\Application\Command\EnqueueStockTransfer\EnqueueStockTransfer;
use Logistics\SapConnector\Application\Command\EnqueueStockTransfer\EnqueueStockTransferHandler;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrders;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrdersHandler;
use Logistics\SapConnector\Application\Command\RequestSapOrdersImport\RequestSapOrdersImport;
use Logistics\SapConnector\Application\Command\RequestSapOrdersImport\RequestSapOrdersImportHandler;
use Logistics\SapConnector\Application\Command\RequestStockTransferDispatch\RequestStockTransferDispatch;
use Logistics\SapConnector\Application\Command\RequestStockTransferDispatch\RequestStockTransferDispatchHandler;
use Logistics\SapConnector\Application\Command\RetrySapNotification\RetrySapNotification;
use Logistics\SapConnector\Application\Command\RetrySapNotification\RetrySapNotificationHandler;
use Logistics\SapConnector\Application\Contract\SapWorkQueue;
use Logistics\SapConnector\Application\Notification\SapNotificationPolicy;
use Logistics\SapConnector\Application\Query\ListFailedTransfers\ListFailedTransfers;
use Logistics\SapConnector\Application\Query\ListFailedTransfers\ListFailedTransfersHandler;
use Logistics\SapConnector\Application\Query\ListMaterialMappings\ListMaterialMappings;
use Logistics\SapConnector\Application\Query\ListMaterialMappings\ListMaterialMappingsHandler;
use Logistics\SapConnector\Application\Query\ListPendingTransfers\ListPendingTransfers;
use Logistics\SapConnector\Application\Query\ListPendingTransfers\ListPendingTransfersHandler;
use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\SapNotificationRepository;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Infrastructure\Cache\CachingMaterialMappingRepository;
use Logistics\SapConnector\Infrastructure\Outbox\SapMessageDispatcher;
use Logistics\SapConnector\Infrastructure\Outbox\SapNotificationRecorder;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\EloquentMaterialMappingRepository;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\EloquentSapNotificationRepository;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\EloquentStockTransferRepository;
use Logistics\SapConnector\Infrastructure\Queue\LaravelSapWorkQueue;
use Logistics\SapConnector\Infrastructure\Sap\HttpSapClient;
use Logistics\SapConnector\Infrastructure\Sap\NullSapClient;
use Logistics\SapConnector\Presentation\Console\DispatchStockTransfersCommand;
use Logistics\SapConnector\Presentation\Console\RetrySapNotificationsCommand;
use Logistics\Shared\Application\HandlerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Registro del bounded context Conector SAP (capa anti-corrupción, ADR 002).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto. Bindea los repositorios, el puerto SAP (cliente
 * HTTP o stub Null según el interruptor `sap.enabled` + base_url), la cola dedicada `sap` y la política
 * de avisos; registra los pares mensaje → handler, carga sus migraciones y se engancha al outbox como
 * reactor (ruta `sap_connector.`) y como observador (avisos fire-and-forget a SAP).
 */
final class SapConnectorServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler del contexto (comandos y consultas).
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        ImportSapOrders::class => ImportSapOrdersHandler::class,
        EnqueueStockTransfer::class => EnqueueStockTransferHandler::class,
        AcknowledgeStockTransfer::class => AcknowledgeStockTransferHandler::class,
        DispatchPendingTransfers::class => DispatchPendingTransfersHandler::class,
        DefineMaterialMapping::class => DefineMaterialMappingHandler::class,
        RequestStockTransferDispatch::class => RequestStockTransferDispatchHandler::class,
        RequestSapOrdersImport::class => RequestSapOrdersImportHandler::class,
        RetrySapNotification::class => RetrySapNotificationHandler::class,
        ListPendingTransfers::class => ListPendingTransfersHandler::class,
        ListFailedTransfers::class => ListFailedTransfersHandler::class,
        ListMaterialMappings::class => ListMaterialMappingsHandler::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-sap-connector.php', 'logistics-sap-connector');

        // El mapa sku ↔ material SAP es un catálogo de referencia muy estable y leído en cada traducción:
        // se envuelve el repositorio Eloquent con el decorador de cache (Valkey), que cachea all() en forma
        // primitiva (regla #7) y lo invalida al guardar. Las traducciones puntuales (findBySku/
        // findByMaterial) y la escritura siguen yendo a la base.
        $this->app->bind(MaterialMappingRepository::class, function ($app): MaterialMappingRepository {
            return new CachingMaterialMappingRepository(
                $app->make(EloquentMaterialMappingRepository::class),
                $app->make(CacheRepository::class),
            );
        });
        $this->app->bind(StockTransferRepository::class, EloquentStockTransferRepository::class);

        // Avisos fire-and-forget a SAP: repositorio, cola dedicada y política vigente.
        $this->app->bind(SapNotificationRepository::class, EloquentSapNotificationRepository::class);
        $this->app->bind(SapWorkQueue::class, static function ($app): SapWorkQueue {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new LaravelSapWorkQueue(self::queueName($config));
        });

        // La política se arma en CADA resolución (no singleton) desde la config vigente: así un cambio
        // guardado en el panel de Configuración (que se overlaya en config) se respeta sin reiniciar.
        $this->app->bind(SapNotificationPolicy::class, static function ($app): SapNotificationPolicy {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return self::notificationPolicy($config);
        });

        // El puerto que habla SAP: cliente HTTP real solo si el conector está HABILITADO y tiene base_url;
        // si no, el stub Null, para que la plataforma arranque y se pueda desarrollar sin SAP (el escaneo
        // nunca depende de SAP). Se resuelve en cada uso (no singleton, es barato): el interruptor y la URL
        // del panel de Configuración se respetan en los workers de larga vida sin reiniciarlos.
        $this->app->bind(SapClient::class, static function ($app): SapClient {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $baseUrl = self::baseUrl($config);
            if (!self::connectorEnabled($config) || $baseUrl === null) {
                /** @var LoggerInterface|null $logger */
                $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

                return new NullSapClient($logger);
            }

            $username = $config->get('logistics-sap-connector.sap.username');
            $password = $config->get('logistics-sap-connector.sap.password');

            return new HttpSapClient(
                $app->make(HttpClient::class),
                $baseUrl,
                is_string($username) && $username !== '' ? $username : null,
                is_string($password) && $password !== '' ? $password : null,
                (string) $config->get('logistics-sap-connector.sap.orders_path', '/orders/pending'),
                (string) $config->get('logistics-sap-connector.sap.stock_transfers_path', '/stock-transfers'),
                (int) $config->get('logistics-sap-connector.sap.timeout', 10),
                (string) $config->get('logistics-sap-connector.sap.events_path', '/events'),
                (int) $config->get('logistics-sap-connector.notifications.timeout', 5),
            );
        });
    }

    /**
     * ¿El conector está encendido? Interruptor real (config `sap.enabled`, que el panel overlaya desde
     * `integrations.sap.enabled`). Apagado ⇒ stub Null y avisos Skipped, aunque haya base_url.
     */
    public static function connectorEnabled(Config $config): bool
    {
        return filter_var($config->get('logistics-sap-connector.sap.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    private static function baseUrl(Config $config): ?string
    {
        $baseUrl = $config->get('logistics-sap-connector.sap.base_url');

        return is_string($baseUrl) && trim($baseUrl) !== '' ? trim($baseUrl) : null;
    }

    private static function queueName(Config $config): string
    {
        $queue = $config->get('logistics-sap-connector.queue', 'sap');

        return is_string($queue) && $queue !== '' ? $queue : 'sap';
    }

    /**
     * Política de avisos desde la config vigente. El envío está habilitado solo con el conector encendido
     * Y una base_url; si no, los avisos se registran Skipped.
     */
    private static function notificationPolicy(Config $config): SapNotificationPolicy
    {
        $events = [];
        foreach ((array) $config->get('logistics-sap-connector.notifications.events', []) as $event) {
            if (is_string($event) && trim($event) !== '') {
                $events[] = trim($event);
            }
        }

        $backoff = [];
        foreach ((array) $config->get('logistics-sap-connector.notifications.backoff_minutes', []) as $attempt => $minutes) {
            if (is_numeric($minutes)) {
                $backoff[(int) $attempt] = max(0, (int) $minutes);
            }
        }

        return new SapNotificationPolicy(
            deliveryEnabled: self::connectorEnabled($config) && self::baseUrl($config) !== null,
            events: $events,
            maxAttempts: max(1, (int) $config->get('logistics-sap-connector.notifications.max_attempts', 3)),
            backoffMinutes: $backoff !== [] ? $backoff : [1 => 1, 2 => 5],
            graceSeconds: max(0, (int) $config->get('logistics-sap-connector.notifications.grace_seconds', 120)),
            retryBatch: max(1, (int) $config->get('logistics-sap-connector.notifications.retry_batch', 50)),
            requeueLeaseSeconds: max(60, (int) $config->get('logistics-sap-connector.notifications.requeue_lease_seconds', 300)),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $this->publishes([
            __DIR__.'/../../../config/logistics-sap-connector.php' => config_path('logistics-sap-connector.php'),
        ], 'logistics-sap-connector-config');

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchStockTransfersCommand::class, RetrySapNotificationsCommand::class]);
        }

        $this->registerOutboxRoute();
        $this->registerOutboxObserver();
    }

    /**
     * Engancha el aviso fire-and-forget a SAP como OBSERVADOR del outbox (no como reactor): por cada
     * mensaje procesado, SapNotificationRecorder registra el aviso si el evento está en la lista y encola
     * su envío en la cola `sap`. Al no estar en `routes` ni en ningún `fanout`, un fallo del aviso nunca
     * saltea a otro reactor ni cambia el desenlace del mensaje. Idempotente: no se duplica en la lista.
     */
    private function registerOutboxObserver(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        $observers = array_values((array) $config->get('logistics-outbox.observers', []));
        if (!in_array(SapNotificationRecorder::class, $observers, true)) {
            $observers[] = SapNotificationRecorder::class;
            $config->set('logistics-outbox.observers', $observers);
        }
    }

    /**
     * Cierra la cadena evento → outbox → SAP (ADR 002): registra el adapter del conector en el ruteo del
     * relay del outbox para el prefijo de sus eventos (`sap_connector.`). Así los hechos del conector
     * dejan de caer al dispatcher por defecto (Null) y pasan a empujarse a SAP por SapMessageDispatcher.
     *
     * Se hace desde el provider del propio contexto (no editando la config del outbox) para mantener el
     * conector autocontenido: el relay lee estas rutas de forma perezosa, bastante después del boot.
     */
    private function registerOutboxRoute(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        /** @var array<string, class-string> $routes */
        $routes = (array) $config->get('logistics-outbox.routes', []);
        if (!array_key_exists('sap_connector.', $routes)) {
            $routes['sap_connector.'] = SapMessageDispatcher::class;
            $config->set('logistics-outbox.routes', $routes);
        }
    }
}
