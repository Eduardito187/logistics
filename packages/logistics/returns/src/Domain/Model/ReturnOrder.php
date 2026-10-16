<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\Model;

use Logistics\Returns\Domain\Event\ReturnDispositioned;
use Logistics\Returns\Domain\Event\ReturnInitiated;
use Logistics\Returns\Domain\Event\ReturnReceived;
use Logistics\Returns\Domain\Exception\ReturnsException;
use Logistics\Returns\Domain\ValueObject\Disposition;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Returns\Domain\ValueObject\ReturnKind;
use Logistics\Returns\Domain\ValueObject\ReturnLine;
use Logistics\Returns\Domain\ValueObject\ReturnStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Devolución (logística inversa, ADR 017 §7.4). Agregado raíz del contexto returns.
 *
 * Custodia el ciclo de vida y sus invariantes: Initiated → Received → Dispositioned → Closed. La propiedad
 * del producto que vuelve es transversal (shared Ownership): retail (dueño null, modo `owned`) o de un
 * seller (dueño no null, modo `fbd`). El dueño y el almacén se guardan como id en texto (UUID de otros
 * contextos) para no acoplar returns a sellers ni a network: la referencia entre contextos es por id.
 *
 * Al disponer `Restock`, el evento `ReturnDispositioned` arrastra lo que inventory necesita para
 * `ReceiveStock` (almacén + propiedad + líneas); el enganche real lo hace el orquestador por el outbox.
 */
final class ReturnOrder extends AggregateRoot
{
    /**
     * @param list<ReturnLine> $lines
     */
    private function __construct(
        private readonly ReturnId $id,
        private readonly string $originalReference,
        private readonly Ownership $ownership,
        private readonly string $warehouseId,
        private readonly ReturnKind $kind,
        private array $lines,
        private ReturnStatus $status,
        private ?Disposition $disposition,
    ) {}

    /**
     * Inicia una devolución. Debe referenciar el envío/pedido original, el almacén central destino y al
     * menos una línea. Un RTV (return-to-vendor) exige un seller dueño; una devolución de cliente puede ser
     * retail (sin dueño) o de un seller.
     *
     * @param list<ReturnLine> $lines
     */
    public static function initiate(
        ReturnId $id,
        string $originalReference,
        ?string $ownerPartyId,
        string $warehouseId,
        ReturnKind $kind,
        array $lines,
    ): self {
        $originalReference = trim($originalReference);
        if ($originalReference === '') {
            throw ReturnsException::because('La devolución debe referenciar el envío/pedido original.');
        }
        $warehouseId = trim($warehouseId);
        if ($warehouseId === '') {
            throw ReturnsException::because('La devolución debe indicar el almacén central de retorno.');
        }
        if ($lines === []) {
            throw ReturnsException::because('Una devolución debe tener al menos una línea (sku + cantidad + motivo).');
        }

        $ownerPartyId = $ownerPartyId !== null ? trim($ownerPartyId) : null;
        $ownerPartyId = $ownerPartyId === '' ? null : $ownerPartyId;
        if ($kind->requiresOwner() && $ownerPartyId === null) {
            throw ReturnsException::because('Una devolución al proveedor (RTV) exige el seller dueño del producto.');
        }

        $ownership = self::ownershipFrom($ownerPartyId);

        $returnOrder = new self(
            $id,
            $originalReference,
            $ownership,
            $warehouseId,
            $kind,
            array_values($lines),
            ReturnStatus::Initiated,
            null,
        );
        $returnOrder->recordEvent(new ReturnInitiated(
            $id->toString(),
            $originalReference,
            $ownerPartyId,
            $warehouseId,
            $ownership->mode->value,
            $kind->value,
            array_map(static fn (ReturnLine $line): array => $line->toArray(), array_values($lines)),
        ));

        return $returnOrder;
    }

    /**
     * Reconstituye desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<ReturnLine> $lines
     */
    public static function reconstitute(
        ReturnId $id,
        string $originalReference,
        ?string $ownerPartyId,
        string $warehouseId,
        ReturnKind $kind,
        array $lines,
        ReturnStatus $status,
        ?Disposition $disposition,
    ): self {
        return new self(
            $id,
            $originalReference,
            self::ownershipFrom($ownerPartyId),
            $warehouseId,
            $kind,
            array_values($lines),
            $status,
            $disposition,
        );
    }

    /**
     * Registra la recepción física de la devolución en el almacén. Solo desde Initiated. Idempotente si ya
     * está Received.
     */
    public function receive(): void
    {
        if ($this->status === ReturnStatus::Received) {
            return;
        }
        if ($this->status !== ReturnStatus::Initiated) {
            throw ReturnsException::because("No se puede recibir una devolución en estado {$this->status->value}.");
        }

        $this->status = ReturnStatus::Received;
        $this->recordEvent(new ReturnReceived(
            $this->id->toString(),
            $this->warehouseId,
            $this->ownership->ownerPartyId?->value,
        ));
    }

    /**
     * Decide la disposición de la devolución recibida. Solo desde Received. `ReturnToSeller` exige un seller
     * dueño. Si la disposición es `Restock`, el evento lleva las líneas a re-ingresar (sku + cantidad) con la
     * dimensión de propiedad para que inventory cree/rellene el lote del dueño correcto.
     */
    public function dispose(Disposition $disposition): void
    {
        if ($this->status !== ReturnStatus::Received) {
            throw ReturnsException::because("No se puede disponer una devolución en estado {$this->status->value}.");
        }
        if ($disposition->requiresOwner() && $this->ownership->ownerPartyId === null) {
            throw ReturnsException::because('No se puede devolver al seller una devolución de producto retail (sin dueño).');
        }

        $this->status = ReturnStatus::Dispositioned;
        $this->disposition = $disposition;

        $restockLines = [];
        if ($disposition->isRestock()) {
            $restockLines = array_map(
                static fn (ReturnLine $line): array => ['sku' => $line->sku, 'quantity' => $line->quantity],
                $this->lines,
            );
        }

        $this->recordEvent(new ReturnDispositioned(
            $this->id->toString(),
            $disposition->value,
            $this->kind->value,
            $this->warehouseId,
            $this->ownership->mode->value,
            $this->ownership->ownerPartyId?->value,
            $disposition->isRestock(),
            $restockLines,
        ));
    }

    /**
     * Cierra la devolución (conciliada). Solo desde Dispositioned. Idempotente si ya está Closed. No emite
     * evento: es el cierre contable; el hecho de negocio ya ocurrió en la disposición.
     */
    public function close(): void
    {
        if ($this->status === ReturnStatus::Closed) {
            return;
        }
        if ($this->status !== ReturnStatus::Dispositioned) {
            throw ReturnsException::because("No se puede cerrar una devolución en estado {$this->status->value}.");
        }

        $this->status = ReturnStatus::Closed;
    }

    public function id(): ReturnId
    {
        return $this->id;
    }

    public function originalReference(): string
    {
        return $this->originalReference;
    }

    public function ownership(): Ownership
    {
        return $this->ownership;
    }

    /**
     * Id del seller dueño (null = retail Dismac).
     */
    public function ownerPartyId(): ?string
    {
        return $this->ownership->ownerPartyId?->value;
    }

    public function warehouseId(): string
    {
        return $this->warehouseId;
    }

    public function kind(): ReturnKind
    {
        return $this->kind;
    }

    /**
     * @return list<ReturnLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function status(): ReturnStatus
    {
        return $this->status;
    }

    public function disposition(): ?Disposition
    {
        return $this->disposition;
    }

    /**
     * Deriva la propiedad transversal a partir del id de dueño: sin dueño ⇒ retail (`owned`); con dueño ⇒
     * marketplace FBD (el restock vuelve al lote del seller con el modo correcto).
     */
    private static function ownershipFrom(?string $ownerPartyId): Ownership
    {
        if ($ownerPartyId === null || trim($ownerPartyId) === '') {
            return Ownership::dismacOwned();
        }

        return Ownership::sellerFbd(Uuid::fromString($ownerPartyId));
    }

    /**
     * Expuesto para lectura: ¿el producto que vuelve es de un seller (no retail)?
     */
    public function isMarketplace(): bool
    {
        return $this->ownership->mode !== FulfillmentMode::Owned;
    }
}
