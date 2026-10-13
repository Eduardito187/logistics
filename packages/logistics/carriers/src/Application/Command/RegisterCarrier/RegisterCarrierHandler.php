<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\RegisterCarrier;

use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Model\Carrier;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el transportista, lo persiste y libera sus eventos.
 *
 * TODO(outbox): cuando se integre el package outbox, los eventos liberados se publican en la misma
 * transacción. Por ahora se liberan y se descartan a propósito (no se depende de outbox todavía).
 *
 * @implements CommandHandler<RegisterCarrier>
 */
final readonly class RegisterCarrierHandler implements CommandHandler
{
    public function __construct(
        private CarrierRepository $carriers,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterCarrier);

        $carrier = Carrier::register(
            $this->carriers->nextIdentity(),
            $command->name,
            $command->taxId,
            $command->documents,
        );

        $this->carriers->save($carrier);
        $carrier->releaseEvents();
    }
}
