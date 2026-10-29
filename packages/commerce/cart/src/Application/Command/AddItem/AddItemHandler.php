<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\AddItem;

use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\Contract\InventoryAvailabilityPort;
use Commerce\Cart\Domain\Exception\CartException;
use Commerce\Cart\Domain\ValueObject\CartId;
use Commerce\Cart\Domain\ValueObject\CartLine;
use Commerce\Cart\Domain\ValueObject\Sku;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Agrega una línea al carrito tras verificar disponibilidad (ATP) por el puerto de inventory. Rechaza
 * con CartException si no hay stock. Publica los eventos del agregado al outbox.
 *
 * @implements CommandHandler<AddItem>
 */
final readonly class AddItemHandler implements CommandHandler
{
    public function __construct(
        private CartRepository $carts,
        private InventoryAvailabilityPort $inventory,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AddItem);

        $cart = $this->carts->findById(CartId::fromString($command->cartId));
        if ($cart === null) {
            throw CartException::because('El carrito al que se agrega el ítem no existe.');
        }

        $sku = Sku::of($command->sku);
        if (! $this->inventory->isAvailable($sku, $command->qty)) {
            throw CartException::because("No hay disponibilidad para el SKU {$sku->value}.");
        }

        $cart->addItem(CartLine::of($sku, $command->productName, $command->unitPriceCents, $command->qty));

        $this->carts->save($cart);

        $this->recordEvents->record($cart->releaseEvents(), 'cart.cart');
    }
}
