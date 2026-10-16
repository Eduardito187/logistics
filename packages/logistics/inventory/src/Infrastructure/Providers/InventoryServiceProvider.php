<?php

declare(strict_types=1);

namespace Logistics\Inventory\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Inventory\Application\Command\AdjustStock\AdjustStock;
use Logistics\Inventory\Application\Command\AdjustStock\AdjustStockHandler;
use Logistics\Inventory\Application\Command\PickStock\PickStock;
use Logistics\Inventory\Application\Command\PickStock\PickStockHandler;
use Logistics\Inventory\Application\Command\ReceiveStock\ReceiveStock;
use Logistics\Inventory\Application\Command\ReceiveStock\ReceiveStockHandler;
use Logistics\Inventory\Application\Command\ReleaseStock\ReleaseStock;
use Logistics\Inventory\Application\Command\ReleaseStock\ReleaseStockHandler;
use Logistics\Inventory\Application\Command\ReserveStock\ReserveStock;
use Logistics\Inventory\Application\Command\ReserveStock\ReserveStockHandler;
use Logistics\Inventory\Application\Command\TransferStock\TransferStock;
use Logistics\Inventory\Application\Command\TransferStock\TransferStockHandler;
use Logistics\Inventory\Application\Query\GetAvailableToPromise\GetAvailableToPromise;
use Logistics\Inventory\Application\Query\GetAvailableToPromise\GetAvailableToPromiseHandler;
use Logistics\Inventory\Application\Query\GetStockOnHand\GetStockOnHand;
use Logistics\Inventory\Application\Query\GetStockOnHand\GetStockOnHandHandler;
use Logistics\Inventory\Application\Query\ListLots\ListLots;
use Logistics\Inventory\Application\Query\ListLots\ListLotsHandler;
use Logistics\Inventory\Domain\Contract\StockLotRepository;
use Logistics\Inventory\Domain\Contract\StockQueryStore;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\ReadStore\EloquentStockQueryStore;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Repository\EloquentStockLotRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Inventory (stock con atribución de dueño; consignación vs. propio).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        ReceiveStock::class => ReceiveStockHandler::class,
        ReserveStock::class => ReserveStockHandler::class,
        ReleaseStock::class => ReleaseStockHandler::class,
        PickStock::class => PickStockHandler::class,
        TransferStock::class => TransferStockHandler::class,
        AdjustStock::class => AdjustStockHandler::class,
        GetStockOnHand::class => GetStockOnHandHandler::class,
        ListLots::class => ListLotsHandler::class,
        GetAvailableToPromise::class => GetAvailableToPromiseHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(StockLotRepository::class, EloquentStockLotRepository::class);
        $this->app->bind(StockQueryStore::class, EloquentStockQueryStore::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
