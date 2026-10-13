<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfers;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfersHandler;
use Logistics\SapConnector\Application\Command\EnqueueStockTransfer\EnqueueStockTransfer;
use Logistics\SapConnector\Application\Command\EnqueueStockTransfer\EnqueueStockTransferHandler;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrders;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrdersHandler;
use Logistics\SapConnector\Application\Query\ListFailedTransfers\ListFailedTransfers;
use Logistics\SapConnector\Application\Query\ListFailedTransfers\ListFailedTransfersHandler;
use Logistics\SapConnector\Application\Query\ListPendingTransfers\ListPendingTransfers;
use Logistics\SapConnector\Application\Query\ListPendingTransfers\ListPendingTransfersHandler;
use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\EloquentMaterialMappingRepository;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\EloquentStockTransferRepository;
use Logistics\SapConnector\Infrastructure\Sap\NullSapClient;
use Logistics\SapConnector\Presentation\Console\DispatchStockTransfersCommand;
use Logistics\Shared\Application\HandlerRegistry;

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
        DispatchPendingTransfers::class => DispatchPendingTransfersHandler::class,
        ListPendingTransfers::class => ListPendingTransfersHandler::class,
        ListFailedTransfers::class => ListFailedTransfersHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(MaterialMappingRepository::class, EloquentMaterialMappingRepository::class);
        $this->app->bind(StockTransferRepository::class, EloquentStockTransferRepository::class);

        // El puerto que habla SAP: por defecto el stub Null; el cliente real reemplaza este binding.
        $this->app->bind(SapClient::class, NullSapClient::class);
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
            $this->commands([DispatchStockTransfersCommand::class]);
        }
    }
}
