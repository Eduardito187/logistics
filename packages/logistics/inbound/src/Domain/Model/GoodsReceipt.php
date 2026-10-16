<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Model;

use Logistics\Inbound\Domain\Event\GoodsReceived;
use Logistics\Inbound\Domain\Event\UnitsLabeled;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\GoodsReceiptId;
use Logistics\Inbound\Domain\ValueObject\ReceiptLine;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Recepción física de un ASN con control de calidad por línea (ADR 017, Fase 2).
 *
 * Es un agregado propio (no parte del ASN): una recepción tiene su identidad, su detalle por SKU con el
 * resultado de QC y su etiquetado. Emite `GoodsReceived` al registrarse y `UnitsLabeled` al etiquetar las
 * unidades aceptadas — el **punto de convergencia**: a partir de ahí la unidad es idéntica a una Dismac.
 *
 * El dueño es el seller y el modo de fulfillment es FBD: la dimensión de propiedad (shared Ownership)
 * viaja en los eventos para que el orquestador cree la unidad física y la etiqueta QR en shipments/labels
 * sin que inbound dependa de esos contextos.
 */
final class GoodsReceipt extends AggregateRoot
{
    /**
     * @param list<ReceiptLine> $lines
     */
    private function __construct(
        private readonly GoodsReceiptId $id,
        private readonly AsnId $asnId,
        private readonly string $warehouseId,
        private readonly Ownership $ownership,
        private array $lines,
        private bool $labeled,
    ) {}

    /**
     * Registra la recepción de un ASN. Debe traer al menos una línea. El dueño viaja como Ownership FBD
     * (seller + sku ref opcional); el agregado exige que sea marketplace (no retail).
     *
     * @param list<ReceiptLine> $lines
     */
    public static function record(
        GoodsReceiptId $id,
        AsnId $asnId,
        string $warehouseId,
        Ownership $ownership,
        array $lines,
    ): self {
        $warehouseId = trim($warehouseId);
        if ($warehouseId === '') {
            throw InboundException::because('La recepción debe indicar el almacén central.');
        }
        if ($lines === []) {
            throw InboundException::because('Una recepción debe tener al menos una línea recibida.');
        }
        if (!$ownership->isMarketplace() || $ownership->ownerPartyId === null) {
            throw InboundException::because('La recepción de inbound es de un seller (FBD/dropship), nunca stock propio.');
        }

        $lines = array_values($lines);
        $receipt = new self($id, $asnId, $warehouseId, $ownership, $lines, false);
        $receipt->recordEvent(new GoodsReceived(
            $id->toString(),
            $asnId->toString(),
            $ownership->ownerPartyId->value,
            $warehouseId,
            $ownership->mode->value,
            array_map(static fn (ReceiptLine $line): array => [
                'sku' => $line->sku,
                'accepted' => $line->quantity->accepted,
                'rejected' => $line->quantity->rejected,
                'damaged' => $line->quantity->damaged,
                'qc_outcome' => $line->qcOutcome->value,
            ], $lines),
        ));

        return $receipt;
    }

    /**
     * Reconstituye desde persistencia, sin grabar eventos.
     *
     * @param list<ReceiptLine> $lines
     */
    public static function reconstitute(
        GoodsReceiptId $id,
        AsnId $asnId,
        string $warehouseId,
        Ownership $ownership,
        array $lines,
        bool $labeled,
    ): self {
        return new self($id, $asnId, $warehouseId, $ownership, array_values($lines), $labeled);
    }

    /**
     * Etiqueta las unidades ACEPTADAS de la recepción (punto de convergencia). Idempotente: una segunda
     * llamada no vuelve a emitir. Falla si no hay nada aceptado para etiquetar.
     */
    public function labelUnits(): void
    {
        if ($this->labeled) {
            return;
        }

        $units = [];
        foreach ($this->lines as $line) {
            $accepted = $line->acceptedQuantity();
            if ($accepted > 0) {
                $units[] = ['sku' => $line->sku, 'quantity' => $accepted];
            }
        }

        if ($units === []) {
            throw InboundException::because('No hay unidades aceptadas para etiquetar en esta recepción.');
        }

        $this->labeled = true;

        /** @var Uuid $ownerPartyId */
        $ownerPartyId = $this->ownership->ownerPartyId;
        $this->recordEvent(new UnitsLabeled(
            $this->id->toString(),
            $this->asnId->toString(),
            $ownerPartyId->value,
            $this->warehouseId,
            $this->ownership->mode->value,
            $units,
        ));
    }

    public function id(): GoodsReceiptId
    {
        return $this->id;
    }

    public function asnId(): AsnId
    {
        return $this->asnId;
    }

    public function warehouseId(): string
    {
        return $this->warehouseId;
    }

    public function ownership(): Ownership
    {
        return $this->ownership;
    }

    /**
     * @return list<ReceiptLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function isLabeled(): bool
    {
        return $this->labeled;
    }

    /**
     * Total de piezas aceptadas en toda la recepción (lo que se etiqueta y pasa a stock).
     */
    public function totalAccepted(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->acceptedQuantity();
        }

        return $total;
    }
}
