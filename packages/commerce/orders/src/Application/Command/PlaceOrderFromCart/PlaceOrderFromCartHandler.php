<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\PlaceOrderFromCart;

use Commerce\Orders\Application\Command\PlaceOrder\PlaceOrder;
use Commerce\Orders\Application\Contract\CartSnapshotReader;
use Commerce\Orders\Domain\Exception\OrdersException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Orquesta carrito → orden en el checkout: lee la instantánea del carrito y, reutilizando la lógica de
 * creación de orden, despacha `PlaceOrder` por el bus (una sola fuente de verdad para crear la orden;
 * no se reconstruye el agregado acá). Si el carrito no existe o está vacío, lanza una excepción de
 * dominio (el controller la traduce a 422).
 *
 * El precio viene congelado del carrito (client-side pricing): NO se consulta `pricing`. Los datos de
 * entrega llegan en el comando (el carrito no los guarda). La conversión del carrito NO se dispara acá:
 * va desacoplada por outbox (ver OrderPlacedCartConverter en el contexto `cart`).
 *
 * @implements CommandHandler<PlaceOrderFromCart>
 */
final readonly class PlaceOrderFromCartHandler implements CommandHandler
{
    public function __construct(
        private CartSnapshotReader $carts,
        private CommandBus $commandBus,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof PlaceOrderFromCart);

        $snapshot = $this->carts->snapshot($command->cartId);
        if ($snapshot === null) {
            throw OrdersException::because('El carrito del checkout no existe.');
        }
        if ($snapshot['lines'] === []) {
            throw OrdersException::because('No se puede crear una orden desde un carrito vacío.');
        }

        $this->commandBus->dispatch(new PlaceOrder(
            customerName: $command->customerName,
            addressText: $command->addressText,
            lines: $snapshot['lines'],
            customerId: $snapshot['customer_id'],
            addressReference: $command->addressReference,
            phone: $command->phone,
            email: $command->email,
            currency: $snapshot['currency'],
            cartId: $command->cartId,
        ));
    }
}
