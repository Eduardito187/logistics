<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\LabelReceivedUnits;

use Logistics\Inbound\Domain\Contract\GoodsReceiptRepository;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Etiqueta las unidades aceptadas de la recepción del ASN (punto de convergencia). Emite `UnitsLabeled`
 * con seller/sku/cantidades/ownership FBD para que el orquestador cree la unidad física y el QR en
 * shipments/labels. Idempotente: re-etiquetar no re-emite.
 *
 * @implements CommandHandler<LabelReceivedUnits>
 */
final readonly class LabelReceivedUnitsHandler implements CommandHandler
{
    public function __construct(
        private GoodsReceiptRepository $receipts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof LabelReceivedUnits);

        $receipt = $this->receipts->findByAsnId(AsnId::fromString($command->asnId))
            ?? throw InboundException::because("El ASN {$command->asnId} todavía no tiene una recepción registrada.");

        $receipt->labelUnits();

        $this->receipts->save($receipt);

        $this->recordEvents->record($receipt->releaseEvents(), 'inbound.goods_receipt');
    }
}
