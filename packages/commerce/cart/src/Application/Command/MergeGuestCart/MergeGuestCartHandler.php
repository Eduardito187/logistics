<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\MergeGuestCart;

use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\Exception\CartException;
use Commerce\Cart\Domain\ValueObject\CartId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Fusiona el carrito de invitado dentro del destino, persiste el destino y publica sus eventos.
 *
 * @implements CommandHandler<MergeGuestCart>
 */
final readonly class MergeGuestCartHandler implements CommandHandler
{
    public function __construct(
        private CartRepository $carts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof MergeGuestCart);

        $target = $this->carts->findById(CartId::fromString($command->targetCartId));
        if ($target === null) {
            throw CartException::because('El carrito destino de la fusión no existe.');
        }
        $guest = $this->carts->findById(CartId::fromString($command->guestCartId));
        if ($guest === null) {
            throw CartException::because('El carrito de invitado a fusionar no existe.');
        }

        $target->mergeFrom($guest);

        $this->carts->save($target);

        $this->recordEvents->record($target->releaseEvents(), 'cart.cart');
    }
}
