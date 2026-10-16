<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Model;

use Logistics\SapConnector\Domain\Event\StockTransferAcknowledged;
use Logistics\SapConnector\Domain\Event\StockTransferEnqueued;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Domain\ValueObject\TransferKind;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Agregado raíz del conector: un traspaso de stock hacia SAP, nacido al recibir un bulto en un nodo.
 *
 * Es asíncrono por diseño (ADR 002): se encola Pending y el escaneo sigue; un worker lo empuja a SAP
 * más tarde. La clave de idempotencia es `eventId` (el event_id de la recepción de origen): un mismo
 * evento de recepción produce un solo traspaso, garantizado por el índice único en la persistencia y
 * resguardado a nivel de aplicación antes de crear el agregado.
 */
final class StockTransfer extends AggregateRoot
{
    private function __construct(
        private readonly StockTransferId $id,
        private readonly string $eventId,
        private readonly string $shipmentRef,
        private readonly SapMaterial $material,
        private readonly SapCenter $center,
        private readonly SapWarehouse $warehouse,
        private readonly int $quantity,
        private TransferStatus $status,
        private ?string $lastError,
        // Dimensión de propiedad (ADR 017): por defecto Owned, para que el flujo retail histórico quede
        // intacto. Consignment ⇒ ownerPartyId (seller) no nulo; Owned ⇒ sin dueño.
        private readonly TransferKind $kind = TransferKind::Owned,
        private readonly ?string $ownerPartyId = null,
    ) {}

    /**
     * Named constructor: encola un traspaso al recibir en un nodo. Nace Pending y graba el hecho de
     * encolado para que la aplicación lo mande al outbox hacia SAP.
     */
    public static function fromReception(
        StockTransferId $id,
        string $eventId,
        string $shipmentRef,
        SapMaterial $material,
        SapCenter $center,
        SapWarehouse $warehouse,
        int $quantity,
        // Opcionales y aditivos: por defecto es un traspaso propio (retail) sin dueño, el flujo de hoy.
        TransferKind $kind = TransferKind::Owned,
        ?string $ownerPartyId = null,
    ): self {
        $trimmedEventId = trim($eventId);
        if ($trimmedEventId === '') {
            throw SapConnectorException::because('el event_id de la recepción no puede estar vacío');
        }
        $trimmedShipmentRef = trim($shipmentRef);
        if ($trimmedShipmentRef === '') {
            throw SapConnectorException::because('la referencia de envío no puede estar vacía');
        }
        if ($quantity < 1) {
            throw SapConnectorException::because('la cantidad del traspaso debe ser al menos 1');
        }

        $normalizedOwner = self::normalizeOwner($kind, $ownerPartyId);

        $transfer = new self(
            $id,
            $trimmedEventId,
            $trimmedShipmentRef,
            $material,
            $center,
            $warehouse,
            $quantity,
            TransferStatus::Pending,
            null,
            $kind,
            $normalizedOwner,
        );

        $transfer->recordEvent(new StockTransferEnqueued(
            $id->toString(),
            $trimmedEventId,
            $trimmedShipmentRef,
            $material->value,
            $center->value,
            $warehouse->value,
            $quantity,
        ));

        return $transfer;
    }

    /**
     * Reconstituye el agregado desde la persistencia sin grabar eventos (no es un hecho nuevo).
     */
    public static function reconstitute(
        StockTransferId $id,
        string $eventId,
        string $shipmentRef,
        SapMaterial $material,
        SapCenter $center,
        SapWarehouse $warehouse,
        int $quantity,
        TransferStatus $status,
        ?string $lastError,
        // Aditivos: los datos de la fila ya son válidos, así que no se revalida la invariante aquí.
        TransferKind $kind = TransferKind::Owned,
        ?string $ownerPartyId = null,
    ): self {
        return new self(
            $id,
            $eventId,
            $shipmentRef,
            $material,
            $center,
            $warehouse,
            $quantity,
            $status,
            $lastError,
            $kind,
            $ownerPartyId,
        );
    }

    /**
     * Normaliza y valida la invariante de propiedad (ADR 017): la consignación exige un dueño (seller);
     * el stock propio (Owned) no puede tenerlo. Devuelve el id del dueño ya recortado (o null).
     */
    private static function normalizeOwner(TransferKind $kind, ?string $ownerPartyId): ?string
    {
        $trimmedOwner = $ownerPartyId !== null ? trim($ownerPartyId) : null;
        if ($trimmedOwner === '') {
            $trimmedOwner = null;
        }

        if ($kind->requiresOwner() && $trimmedOwner === null) {
            throw SapConnectorException::because('un traspaso de consignación exige el id del seller dueño del stock');
        }
        if (!$kind->requiresOwner() && $trimmedOwner !== null) {
            throw SapConnectorException::because('un traspaso de stock propio (Owned) no puede tener un seller dueño');
        }

        return $trimmedOwner;
    }

    /**
     * Marca el traspaso como empujado a SAP (a la espera de confirmación). Válido desde Pending o
     * desde Failed (reintento). Limpia el último error.
     */
    public function markSent(): void
    {
        if (!$this->status->isDispatchable()) {
            throw SapConnectorException::because("transición inválida a Sent desde {$this->status->value}");
        }

        $this->status = TransferStatus::Sent;
        $this->lastError = null;
    }

    /**
     * Registra la confirmación de SAP. Válido solo desde Sent. Graba el hecho de confirmación.
     */
    public function acknowledge(): void
    {
        if ($this->status !== TransferStatus::Sent) {
            throw SapConnectorException::because("transición inválida a Acknowledged desde {$this->status->value}");
        }

        $this->status = TransferStatus::Acknowledged;
        $this->recordEvent(new StockTransferAcknowledged(
            $this->id->toString(),
            $this->eventId,
        ));
    }

    /**
     * Marca un fallo de despacho con su motivo. Reintentable: queda Failed y el worker lo vuelve a
     * tomar. No se permite fallar un traspaso ya confirmado.
     */
    public function markFailed(string $error): void
    {
        if ($this->status === TransferStatus::Acknowledged) {
            throw SapConnectorException::because('no se puede marcar como fallido un traspaso ya confirmado');
        }

        $this->status = TransferStatus::Failed;
        $this->lastError = trim($error) !== '' ? trim($error) : 'error desconocido';
    }

    public function id(): StockTransferId
    {
        return $this->id;
    }

    /**
     * Clave de idempotencia: el event_id de la recepción que originó el traspaso.
     */
    public function eventId(): string
    {
        return $this->eventId;
    }

    public function shipmentRef(): string
    {
        return $this->shipmentRef;
    }

    public function material(): SapMaterial
    {
        return $this->material;
    }

    public function center(): SapCenter
    {
        return $this->center;
    }

    public function warehouse(): SapWarehouse
    {
        return $this->warehouse;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function status(): TransferStatus
    {
        return $this->status;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Dimensión de propiedad del traspaso (Owned/Consignment). Decide si se postea la titularidad a SAP.
     */
    public function kind(): TransferKind
    {
        return $this->kind;
    }

    /**
     * Id del seller dueño del stock en una consignación; null si es stock propio de Dismac (Owned).
     */
    public function ownerPartyId(): ?string
    {
        return $this->ownerPartyId;
    }

    /**
     * ¿Es stock de un seller (consignación)? Si lo es, NUNCA se postea a SAP como material propio.
     */
    public function isConsignment(): bool
    {
        return $this->kind === TransferKind::Consignment;
    }
}
