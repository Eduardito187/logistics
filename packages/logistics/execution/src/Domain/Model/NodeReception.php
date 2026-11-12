<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Event\NodeReceptionOpened;
use Logistics\Execution\Domain\Event\NodeReceptionReconciled;
use Logistics\Execution\Domain\Event\NodeUnitReceived;
use Logistics\Execution\Domain\Event\NodeUnitShelved;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\NodeReceptionId;
use Logistics\Execution\Domain\ValueObject\NodeReceptionStatus;
use Logistics\Execution\Domain\ValueObject\ReceivedUnit;
use Logistics\Execution\Domain\ValueObject\ReconciliationReport;
use Logistics\Execution\Domain\ValueObject\ShelfLocation;
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
 * Además guarda el informe del operador al conciliar (motivo de cada faltante, envío real de cada
 * sobrante, notas y foto) y la ubicación en estante VIGENTE de cada producto dentro del nodo.
 *
 * Referencia a envío y nodos por id string: no se acopla a shipments ni a network. La entrega al cliente
 * (CD→cliente) NO pasa por acá: la gobierna el agregado Delivery.
 */
final class NodeReception extends AggregateRoot
{
    /**
     * @param list<string>                 $expectedUnitIds
     * @param list<ReceivedUnit>           $receivedUnits
     * @param array<string, ShelfLocation> $shelfLocations  ubicación vigente por id de producto
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
        private array $shelfLocations,
        private ReconciliationReport $report,
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
            [],
            ReconciliationReport::empty(),
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
     * @param list<string>        $expectedUnitIds
     * @param list<ReceivedUnit>  $receivedUnits
     * @param list<ShelfLocation> $shelfLocations
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
        array $shelfLocations = [],
        ?ReconciliationReport $report = null,
    ): self {
        $locations = [];
        foreach ($shelfLocations as $shelfLocation) {
            $locations[$shelfLocation->unitId] = $shelfLocation;
        }

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
            $locations,
            $report ?? ReconciliationReport::empty(),
        );
    }

    /**
     * Escanea la recepción de un bulto en el nodo. Idempotente por idempotencyKey (reintento offline no
     * duplica). Solo mientras la recepción está abierta; graba NodeUnitReceived.
     *
     * El GPS es opcional (sin señal en interiores): null nunca se reemplaza por una posición inventada.
     */
    public function receiveUnit(
        string $unitId,
        ?GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        ?float $accuracyM = null,
        ?DateTimeImmutable $locationCapturedAt = null,
    ): void {
        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        if ($this->status !== NodeReceptionStatus::Open) {
            throw ExecutionException::because('no se pueden recibir productos en una recepción ya conciliada');
        }

        $this->receivedUnits[] = ReceivedUnit::record(
            $unitId,
            $geo,
            $device,
            $occurredAt,
            $idempotencyKey,
            $accuracyM,
            $locationCapturedAt,
        );

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
     * conciliada, no hace nada (no re-emite ni reescribe el informe). Graba NodeReceptionReconciled con
     * faltantes y sobrantes.
     *
     * El informe del operador (motivos, envío real de los sobrantes, notas, foto) es opcional y solo
     * EXPLICA las discrepancias calculadas: las declaraciones sobre productos que no son faltantes/sobrantes
     * según los escaneos se descartan.
     */
    public function reconcile(DeviceContext $by, DateTimeImmutable $occurredAt, ?ReconciliationReport $report = null): void
    {
        if ($this->status === NodeReceptionStatus::Reconciled) {
            return;
        }

        $missing = $this->missingUnitIds();
        $surplus = $this->surplusUnitIds();

        $this->status = NodeReceptionStatus::Reconciled;
        $this->reconciledAt = $occurredAt;
        $this->report = ($report ?? ReconciliationReport::empty())->restrictedTo($missing, $surplus);

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
            $this->report->missingToArray(),
            $this->report->surplusToArray(),
            $this->report->notes,
            $this->report->photoPath,
        ));
    }

    /**
     * Ubica (o reubica) en estante un producto de esta recepción. Vale tanto con la recepción abierta como
     * ya conciliada (el producto se guarda después de recibirlo). Graba NodeUnitShelved.
     *
     * Idempotente y tolerante al orden offline: repetir la misma ubicación no hace nada, y una ubicación
     * con hora ANTERIOR a la vigente (reenvío tardío de la cola) se ignora para no pisar la más nueva.
     *
     * @return bool true si cambió la ubicación vigente
     */
    public function assignShelfLocation(
        string $unitId,
        string $location,
        DeviceContext $by,
        DateTimeImmutable $occurredAt,
    ): bool {
        $unit = trim($unitId);
        if (!in_array($unit, $this->expectedUnitIds, true) && !in_array($unit, $this->receivedUnitIds(), true)) {
            throw ExecutionException::because("el producto {$unit} no pertenece a esta recepción");
        }

        $shelf = ShelfLocation::of($unit, $location, $occurredAt, $by);
        $current = $this->shelfLocations[$unit] ?? null;

        if ($current !== null && ($current->location === $shelf->location || $current->locatedAt > $occurredAt)) {
            return false;
        }

        $this->shelfLocations[$unit] = $shelf;

        $this->recordEvent(new NodeUnitShelved(
            $this->id->toString(),
            $this->shipmentId,
            $this->destinationNodeId,
            $unit,
            $shelf->location,
            $current?->location,
            $occurredAt,
        ));

        return true;
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
     * Ubicación en estante vigente de cada producto (solo los ya ubicados).
     *
     * @return list<ShelfLocation>
     */
    public function shelfLocations(): array
    {
        return array_values($this->shelfLocations);
    }

    public function shelfLocationOf(string $unitId): ?ShelfLocation
    {
        return $this->shelfLocations[trim($unitId)] ?? null;
    }

    /**
     * Informe del operador al conciliar (vacío mientras la recepción está abierta).
     */
    public function reconciliationReport(): ReconciliationReport
    {
        return $this->report;
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
