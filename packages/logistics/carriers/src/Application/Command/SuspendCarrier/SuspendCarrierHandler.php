<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\SuspendCarrier;

use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el transportista, lo suspende y persiste. Falla si no existe.
 *
 * TODO(outbox): publicar los eventos liberados cuando se integre el package outbox.
 *
 * @implements CommandHandler<SuspendCarrier>
 */
final readonly class SuspendCarrierHandler implements CommandHandler
{
    public function __construct(
        private CarrierRepository $carriers,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SuspendCarrier);

        $carrier = $this->carriers->findById(CarrierId::fromString($command->carrierId));
        if ($carrier === null) {
            throw CarriersException::because("No existe el transportista {$command->carrierId}.");
        }

        $carrier->suspend();

        $this->carriers->save($carrier);
        $carrier->releaseEvents();
    }
}
