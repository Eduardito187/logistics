<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\AnnounceAsn;

use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Model\Asn;
use Logistics\Inbound\Domain\ValueObject\AsnLine;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el ASN, lo persiste y publica sus eventos al outbox (en la transacción del CommandBus).
 *
 * @implements CommandHandler<AnnounceAsn>
 */
final readonly class AnnounceAsnHandler implements CommandHandler
{
    public function __construct(
        private AsnRepository $asns,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AnnounceAsn);

        $lines = array_map(
            static fn (array $line): AsnLine => AsnLine::of($line['sku'], $line['expected_quantity']),
            array_values($command->lines),
        );

        $asn = Asn::announce(
            $this->asns->nextIdentity(),
            $command->sellerId,
            $command->warehouseId,
            $lines,
        );

        $this->asns->save($asn);

        $this->recordEvents->record($asn->releaseEvents(), 'inbound.asn');
    }
}
