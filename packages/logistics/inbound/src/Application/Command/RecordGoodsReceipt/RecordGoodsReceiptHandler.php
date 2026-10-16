<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\RecordGoodsReceipt;

use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Contract\GoodsReceiptRepository;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\Model\GoodsReceipt;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\QcOutcome;
use Logistics\Inbound\Domain\ValueObject\ReceiptLine;
use Logistics\Inbound\Domain\ValueObject\ReceivedQuantity;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Registra la recepción física del ASN: crea el agregado GoodsReceipt (con QC por línea, dueño FBD del
 * seller), avanza el ASN a Received y publica los eventos de ambos agregados al outbox en una transacción.
 *
 * El ASN y la recepción se persisten juntos; `GoodsReceived` lleva la dimensión de propiedad para que el
 * orquestador proyecte el stock del dueño correcto (inventory, fase 3) sin acople directo.
 *
 * @implements CommandHandler<RecordGoodsReceipt>
 */
final readonly class RecordGoodsReceiptHandler implements CommandHandler
{
    public function __construct(
        private AsnRepository $asns,
        private GoodsReceiptRepository $receipts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordGoodsReceipt);

        $asnId = AsnId::fromString($command->asnId);
        $asn = $this->asns->findById($asnId)
            ?? throw InboundException::because("No existe el ASN {$command->asnId}.");

        if ($this->receipts->findByAsnId($asnId) !== null) {
            throw InboundException::because('El ASN ya tiene una recepción registrada.');
        }

        $lines = array_map(
            static fn (array $line): ReceiptLine => ReceiptLine::of(
                $line['sku'],
                ReceivedQuantity::of($line['accepted'], $line['rejected'], $line['damaged']),
                QcOutcome::from($line['qc_outcome']),
            ),
            array_values($command->lines),
        );

        // Dueño del producto: seller en modo FBD (Fulfilled by Dismac). Dimensión de propiedad transversal.
        $ownership = Ownership::sellerFbd($asn->sellerUuid(), $command->sellerSkuRef);

        $asn->startReceiving();
        $asn->markReceived();

        $receipt = GoodsReceipt::record(
            $this->receipts->nextIdentity(),
            $asnId,
            $asn->warehouseId(),
            $ownership,
            $lines,
        );

        $this->asns->save($asn);
        $this->receipts->save($receipt);

        $this->recordEvents->record($asn->releaseEvents(), 'inbound.asn');
        $this->recordEvents->record($receipt->releaseEvents(), 'inbound.goods_receipt');
    }
}
