<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\Providers;

use Commerce\Orders\Application\Command\CancelOrder\CancelOrder;
use Commerce\Orders\Application\Command\CancelOrder\CancelOrderHandler;
use Commerce\Orders\Application\Command\MarkOrderFulfilling\MarkOrderFulfilling;
use Commerce\Orders\Application\Command\MarkOrderFulfilling\MarkOrderFulfillingHandler;
use Commerce\Orders\Application\Command\MarkOrderPaid\MarkOrderPaid;
use Commerce\Orders\Application\Command\MarkOrderPaid\MarkOrderPaidHandler;
use Commerce\Orders\Application\Command\PlaceOrder\PlaceOrder;
use Commerce\Orders\Application\Command\PlaceOrder\PlaceOrderHandler;
use Commerce\Orders\Application\Command\PlaceOrderFromCart\PlaceOrderFromCart;
use Commerce\Orders\Application\Command\PlaceOrderFromCart\PlaceOrderFromCartHandler;
use Commerce\Orders\Application\Command\RecordInitialPayment\RecordInitialPayment;
use Commerce\Orders\Application\Command\RecordInitialPayment\RecordInitialPaymentHandler;
use Commerce\Orders\Application\Command\SignOrderContract\SignOrderContract;
use Commerce\Orders\Application\Command\SignOrderContract\SignOrderContractHandler;
use Commerce\Orders\Application\Contract\CartSnapshotReader;
use Commerce\Orders\Application\Contract\PaymentScheduleReader;
use Commerce\Orders\Application\Query\GetOrder\GetOrder;
use Commerce\Orders\Application\Query\GetOrder\GetOrderHandler;
use Commerce\Orders\Application\Query\ListOrders\ListOrders;
use Commerce\Orders\Application\Query\ListOrders\ListOrdersHandler;
use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Infrastructure\Console\ExpireUnpaidOrdersCommand;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Repository\EloquentOrderRepository;
use Commerce\Orders\Infrastructure\ReadModel\DbCartSnapshotReader;
use Commerce\Orders\Infrastructure\ReadModel\DbPaymentScheduleReader;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Orders (OMS del frente comercial, EC-F0-06 / ADR 018).
 *
 * Los dos adaptadores de outbox del puente (payments → orders y orders → shipments) se registran en
 * el routing del outbox (`packages/logistics/outbox/config/logistics-outbox.php`), no acá.
 */
final class OrdersServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        PlaceOrder::class => PlaceOrderHandler::class,
        PlaceOrderFromCart::class => PlaceOrderFromCartHandler::class,
        MarkOrderPaid::class => MarkOrderPaidHandler::class,
        MarkOrderFulfilling::class => MarkOrderFulfillingHandler::class,
        CancelOrder::class => CancelOrderHandler::class,
        SignOrderContract::class => SignOrderContractHandler::class,
        RecordInitialPayment::class => RecordInitialPaymentHandler::class,
        GetOrder::class => GetOrderHandler::class,
        ListOrders::class => ListOrdersHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(OrderRepository::class, EloquentOrderRepository::class);
        // Lectura cruzada read-only del carrito para el checkout orquestado (carrito → orden).
        $this->app->bind(CartSnapshotReader::class, DbCartSnapshotReader::class);
        // Lectura cruzada read-only del pago iniciado para la expiración por método (ADR 020).
        $this->app->bind(PaymentScheduleReader::class, DbPaymentScheduleReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Comando de expiración de órdenes impagas (programado en el scheduler por el integrador).
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireUnpaidOrdersCommand::class]);
        }

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
