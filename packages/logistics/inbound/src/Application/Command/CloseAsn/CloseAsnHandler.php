<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\CloseAsn;

use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Cierra un ASN (Received → Closed) y publica el evento al outbox.
 *
 * @implements CommandHandler<CloseAsn>
 */
final readonly class CloseAsnHandler implements CommandHandler
{
    public function __construct(
        private AsnRepository $asns,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CloseAsn);

        $asn = $this->asns->findById(AsnId::fromString($command->asnId))
            ?? throw InboundException::because("No existe el ASN {$command->asnId}.");

        $asn->close();

        $this->asns->save($asn);

        $this->recordEvents->record($asn->releaseEvents(), 'inbound.asn');
    }
}
