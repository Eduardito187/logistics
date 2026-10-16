<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\RecordArrival;

use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra la llegada del camión del seller (Scheduled → Arrived) y publica el evento al outbox.
 *
 * @implements CommandHandler<RecordArrival>
 */
final readonly class RecordArrivalHandler implements CommandHandler
{
    public function __construct(
        private AsnRepository $asns,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordArrival);

        $asn = $this->asns->findById(AsnId::fromString($command->asnId))
            ?? throw InboundException::because("No existe el ASN {$command->asnId}.");

        $asn->recordArrival($command->carrierReference);

        $this->asns->save($asn);

        $this->recordEvents->record($asn->releaseEvents(), 'inbound.asn');
    }
}
