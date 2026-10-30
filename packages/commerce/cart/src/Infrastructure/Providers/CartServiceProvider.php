<?php

declare(strict_types=1);

namespace Commerce\Cart\Infrastructure\Providers;

use Commerce\Cart\Application\Command\AddItem\AddItem;
use Commerce\Cart\Application\Command\AddItem\AddItemHandler;
use Commerce\Cart\Application\Command\ClearCart\ClearCart;
use Commerce\Cart\Application\Command\ClearCart\ClearCartHandler;
use Commerce\Cart\Application\Command\MarkCartConverted\MarkCartConverted;
use Commerce\Cart\Application\Command\MarkCartConverted\MarkCartConvertedHandler;
use Commerce\Cart\Application\Command\MergeGuestCart\MergeGuestCart;
use Commerce\Cart\Application\Command\MergeGuestCart\MergeGuestCartHandler;
use Commerce\Cart\Application\Command\OpenCart\OpenCart;
use Commerce\Cart\Application\Command\OpenCart\OpenCartHandler;
use Commerce\Cart\Application\Command\RemoveItem\RemoveItem;
use Commerce\Cart\Application\Command\RemoveItem\RemoveItemHandler;
use Commerce\Cart\Application\Command\UpdateItemQty\UpdateItemQty;
use Commerce\Cart\Application\Command\UpdateItemQty\UpdateItemQtyHandler;
use Commerce\Cart\Application\Query\GetCart\GetCart;
use Commerce\Cart\Application\Query\GetCart\GetCartHandler;
use Commerce\Cart\Application\Query\GetCartBySession\GetCartBySession;
use Commerce\Cart\Application\Query\GetCartBySession\GetCartBySessionHandler;
use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\Contract\InventoryAvailabilityPort;
use Commerce\Cart\Infrastructure\Inventory\AlwaysAvailableInventory;
use Commerce\Cart\Infrastructure\Persistence\Eloquent\Repository\EloquentCartRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Cart (carrito del frente comercial: líneas, fusión, conversión a orden).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle el proyecto. El núcleo SharedServiceProvider va primero.
 */
final class CartServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        OpenCart::class => OpenCartHandler::class,
        AddItem::class => AddItemHandler::class,
        UpdateItemQty::class => UpdateItemQtyHandler::class,
        RemoveItem::class => RemoveItemHandler::class,
        ClearCart::class => ClearCartHandler::class,
        MergeGuestCart::class => MergeGuestCartHandler::class,
        MarkCartConverted::class => MarkCartConvertedHandler::class,
        GetCart::class => GetCartHandler::class,
        GetCartBySession::class => GetCartBySessionHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(CartRepository::class, EloquentCartRepository::class);
        // Stub de disponibilidad (ATP): se re-bindea a un adaptador real de inventory en una fase posterior.
        $this->app->bind(InventoryAvailabilityPort::class, AlwaysAvailableInventory::class);
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
