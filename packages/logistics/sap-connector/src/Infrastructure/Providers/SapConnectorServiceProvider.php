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
use Logistics\SapConnector\Application\Query\ListFailedTransfers\ListFailedTransfers;
use Logistics\SapConnector\Application\Query\ListFailedTransfers\ListFailedTransfersHandler;
use Logistics\SapConnector\Application\Query\ListMaterialMappings\ListMaterialMappings;
use Logistics\SapConnector\Application\Query\ListMaterialMappings\ListMaterialMappingsHandler;
use Logistics\SapConnector\Application\Query\ListPendingTransfers\ListPendingTransfers;
use Logistics\SapConnector\Application\Query\ListPendingTransfers\ListPendingTransfersHandler;
use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Infrastructure\Cache\CachingMaterialMappingRepository;
use Logistics\SapConnector\Infrastructure\Outbox\SapMessageDispatcher;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\EloquentMaterialMappingRepository;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\EloquentStockTransferRepository;
use Logistics\SapConnector\Infrastructure\Sap\HttpSapClient;
use Logistics\SapConnector\Infrastructure\Sap\NullSapClient;
use Logistics\SapConnector\Presentation\Console\DispatchStockTransfersCommand;
use Logistics\Shared\Application\HandlerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Registro del bounded context Conector SAP (capa anti-corrupción, ADR 002).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto. Bindea los repositorios y el puerto SAP (hoy al
 * stub Null), registra los pares mensaje → handler y carga sus migraciones.
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

        // El puerto que habla SAP: cliente HTTP real si hay base_url configurada; si no, el stub Null,
        // para que la plataforma arranque y se pueda desarrollar sin SAP (el escaneo nunca depende de
        // SAP). La selección se resuelve lazy, al primer uso.
        $this->app->singleton(SapClient::class, function ($app): SapClient {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $baseUrl = $config->get('logistics-sap-connector.sap.base_url');
            if (!is_string($baseUrl) || $baseUrl === '') {
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
            );
        });
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
            $this->commands([DispatchStockTransfersCommand::class]);
        }

        $this->registerOutboxRoute();
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
