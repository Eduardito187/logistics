<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\RateCarrier;

use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\Rating;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el transportista, agrega la calificación (recalcula el promedio) y persiste. Falla si no existe.
 *
 * TODO(outbox): publicar los eventos liberados cuando se integre el package outbox.
 *
 * @implements CommandHandler<RateCarrier>
 */
final readonly class RateCarrierHandler implements CommandHandler
{
    public function __construct(
        private CarrierRepository $carriers,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RateCarrier);

        $carrier = $this->carriers->findById(CarrierId::fromString($command->carrierId));
        if ($carrier === null) {
            throw CarriersException::because("No existe el transportista {$command->carrierId}.");
        }

        $carrier->addRating(Rating::of($command->rating));

        $this->carriers->save($carrier);
        $carrier->releaseEvents();
    }
}
