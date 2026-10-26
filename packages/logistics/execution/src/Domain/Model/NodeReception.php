<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Event\NodeReceptionOpened;
use Logistics\Execution\Domain\Event\NodeReceptionReconciled;
use Logistics\Execution\Domain\Event\NodeUnitReceived;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\NodeReceptionId;
use Logistics\Execution\Domain\ValueObject\NodeReceptionStatus;
use Logistics\Execution\Domain\ValueObject\ReceivedUnit;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Agregado raíz de la recepción en un nodo logístico: concilia un envío que LLEGA a un nodo destino por
 * un corredor interno (CD→CD, CD→tienda, tienda→tienda, tienda→CD).
 *
 * Es la fuente de verdad de los bultos recibidos (escaneos inmutables, append-only) y gobierna la
 * máquina de estados de la recepción: abierta → conciliada. Al conciliar compara lo recibido contra lo
 * esperado y calcula faltantes (esperados que no llegaron) y sobrantes (recibidos que no se esperaban),
 * grabando el evento que alimenta tracking y el conector SAP por el outbox.
 *
 * Referencia a envío y nodos por id string: no se acopla a shipments ni a network. La entrega al cliente
 * (CD→cliente) NO pasa por acá: la gobierna el agregado Delivery.
 */
final class NodeReception extends AggregateRoot
{
    /**
     * @param list<string>       $expectedUnitIds
     * @param list<ReceivedUnit> $receivedUnits
     */
    private function __construct(
        private readonly NodeReceptionId $id,
        private readonly string $shipmentId,
        private readonly string $originNodeId,
        private readonly string $destinationNodeId,
        private readonly Corridor $corridor,
        private readonly array $expectedUnitIds,
        private array $receivedUnits,
        private NodeReceptionStatus $status,
        private readonly DateTimeImmutable $openedAt,
        private ?DateTimeImmutable $reconciledAt,
    ) {}

    /**
     * Abre la recepción de un envío en el nodo destino: anuncia qué bultos se esperan por el corredor.
     * Graba NodeReceptionOpened.
     *
     * @param list<string> $expectedUnitIds Bultos esperados (se normalizan: recortados, sin vacíos, únicos).
     */
    public static function open(
        NodeReceptionId $id,
        string $shipmentId,
        string $originNodeId,
        string $destinationNodeId,
        Corridor $corridor,
        array $expectedUnitIds,
        DateTimeImmutable $openedAt,
    ): self {
        $shipment = trim($shipmentId);
        if ($shipment === '') {
            throw ExecutionException::because('la recepción en nodo requiere el id del envío');
        }

        $origin = trim($originNodeId);
        if ($origin === '') {
            throw ExecutionException::because('la recepción en nodo requiere el id del nodo de origen');
        }

        $destination = trim($destinationNodeId);
        if ($destination === '') {
            throw ExecutionException::because('la recepción en nodo requiere el id del nodo de destino');
        }

        if ($origin === $destination) {
            throw ExecutionException::because('el nodo de origen y el de destino no pueden ser el mismo');
        }

        $expected = self::normalizeUnitIds($expectedUnitIds);

        $reception = new self(
            $id,
            $shipment,
            $origin,
            $destination,
            $corridor,
            $expected,
            [],
            NodeReceptionStatus::Open,
            $openedAt,
            null,
        );

        $reception->recordEvent(new NodeReceptionOpened(
            $id->toString(),
            $shipment,
            $origin,
            $destination,
            $corridor->value,
            count($expected),
            $openedAt,
        ));

        return $reception;
    }

    /**
     * Reconstituye el agregado desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     *
     * @param list<string>       $expectedUnitIds
     * @param list<ReceivedUnit> $receivedUnits
     */
    public static function reconstitute(
        NodeReceptionId $id,
        string $shipmentId,
        string $originNodeId,
        string $destinationNodeId,
        Corridor $corridor,
        array $expectedUnitIds,
        array $receivedUnits,
        NodeReceptionStatus $status,
        DateTimeImmutable $openedAt,
        ?DateTimeImmutable $reconciledAt,
    ): self {
        return new self(
            $id,
            $shipmentId,
            $originNodeId,
            $destinationNodeId,
            $corridor,
            self::normalizeUnitIds($expectedUnitIds),
            array_values($receivedUnits),
            $status,
            $openedAt,
            $reconciledAt,
        );
    }

    /**
     * Escanea la recepción de un bulto en el nodo. Idempotente por idempotencyKey (reintento offline no
     * duplica). Solo mientras la recepción está abierta; graba NodeUnitReceived.
     */
    public function receiveUnit(
        string $unitId,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        if ($this->status !== NodeReceptionStatus::Open) {
            throw ExecutionException::because('no se pueden recibir productos en una recepción ya conciliada');
        }

        $this->receivedUnits[] = ReceivedUnit::record($unitId, $geo, $device, $occurredAt, $idempotencyKey);

        $this->recordEvent(new NodeUnitReceived(
            $this->id->toString(),
            $this->shipmentId,
            $this->destinationNodeId,
            trim($unitId),
            $occurredAt,
        ));
    }

    /**
     * Cierra la recepción conciliando lo recibido contra lo esperado. Idempotente: si ya estaba
     * conciliada, no hace nada (no re-emite). Graba NodeReceptionReconciled con faltantes y sobrantes.
     */
    public function reconcile(DeviceContext $by, DateTimeImmutable $occurredAt): void
    {
        if ($this->status === NodeReceptionStatus::Reconciled) {
            return;
        }

        $missing = $this->missingUnitIds();
        $surplus = $this->surplusUnitIds();

        $this->status = NodeReceptionStatus::Reconciled;
        $this->reconciledAt = $occurredAt;

        $this->recordEvent(new NodeReceptionReconciled(
            $this->id->toString(),
            $this->shipmentId,
            $this->originNodeId,
            $this->destinationNodeId,
            $this->corridor->value,
            count($this->expectedUnitIds),
            count($this->receivedUnitIds()),
            $missing,
            $surplus,
            $missing === [] && $surplus === [],
            $occurredAt,
        ));
    }

    public function id(): NodeReceptionId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function originNodeId(): string
    {
        return $this->originNodeId;
    }

    public function destinationNodeId(): string
    {
        return $this->destinationNodeId;
    }

    public function corridor(): Corridor
    {
        return $this->corridor;
    }

    /**
     * @return list<string>
     */
    public function expectedUnitIds(): array
    {
        return $this->expectedUnitIds;
    }

    /**
     * @return list<ReceivedUnit>
     */
    public function receivedUnits(): array
    {
        return $this->receivedUnits;
    }

    public function status(): NodeReceptionStatus
    {
        return $this->status;
    }

    public function openedAt(): DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function reconciledAt(): ?DateTimeImmutable
    {
        return $this->reconciledAt;
    }

    /**
     * Bultos recibidos (ids únicos, en orden de primera recepción).
     *
     * @return list<string>
     */
    public function receivedUnitIds(): array
    {
        $ids = [];
        foreach ($this->receivedUnits as $unit) {
            if (!in_array($unit->unitId, $ids, true)) {
                $ids[] = $unit->unitId;
            }
        }

        return $ids;
    }

    /**
     * Faltantes: esperados que no se recibieron.
     *
     * @return list<string>
     */
    public function missingUnitIds(): array
    {
        $received = $this->receivedUnitIds();

        return array_values(array_filter(
            $this->expectedUnitIds,
            static fn (string $expected): bool => !in_array($expected, $received, true),
        ));
    }

    /**
     * Sobrantes: recibidos que no se esperaban.
     *
     * @return list<string>
     */
    public function surplusUnitIds(): array
    {
        return array_values(array_filter(
            $this->receivedUnitIds(),
            fn (string $received): bool => !in_array($received, $this->expectedUnitIds, true),
        ));
    }

    /**
     * ¿Ya se registró un bulto con esta clave de idempotencia? Base de la idempotencia offline.
     */
    private function isDuplicate(string $idempotencyKey): bool
    {
        $key = trim($idempotencyKey);
        foreach ($this->receivedUnits as $unit) {
            if ($unit->idempotencyKey === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normaliza una lista de ids de bulto: recorta, descarta vacíos y elimina duplicados conservando el
     * orden.
     *
     * @param  list<string> $unitIds
     * @return list<string>
     */
    private static function normalizeUnitIds(array $unitIds): array
    {
        $normalized = [];
        foreach ($unitIds as $unitId) {
            $trimmed = trim($unitId);
            if ($trimmed !== '' && !in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }
}
