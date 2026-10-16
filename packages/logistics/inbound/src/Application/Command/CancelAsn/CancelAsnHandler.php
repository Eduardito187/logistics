<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\CancelAsn;

use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Cancela un ASN (cualquier estado no terminal → Cancelled) y publica el evento al outbox.
 *
 * @implements CommandHandler<CancelAsn>
 */
final readonly class CancelAsnHandler implements CommandHandler
{
    public function __construct(
        private AsnRepository $asns,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CancelAsn);

        $asn = $this->asns->findById(AsnId::fromString($command->asnId))
            ?? throw InboundException::because("No existe el ASN {$command->asnId}.");

        $asn->cancel($command->reason);

        $this->asns->save($asn);

        $this->recordEvents->record($asn->releaseEvents(), 'inbound.asn');
    }
}
