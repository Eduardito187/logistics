<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Execution\Domain\Model\NodeReception;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\NodeReceptionId;
use Logistics\Execution\Domain\ValueObject\NodeReceptionStatus;
use Logistics\Execution\Domain\ValueObject\ReceivedUnit;

/**
 * Traduce entre el agregado NodeReception (con sus bultos recibidos) y sus filas Eloquent. Toda la
 * conversión a/desde primitivos vive acá: ni el dominio ni el repositorio conocen la forma de las tablas.
 */
final class NodeReceptionMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toReceptionRow(NodeReception $reception): array
    {
        return [
            'id' => $reception->id()->toString(),
            'shipment_id' => $reception->shipmentId(),
            'origin_node_id' => $reception->originNodeId(),
            'destination_node_id' => $reception->destinationNodeId(),
            'corridor' => $reception->corridor()->value,
            'status' => $reception->status()->value,
            'expected_unit_ids' => $reception->expectedUnitIds(),
            'opened_at' => $reception->openedAt()->format(DATE_ATOM),
            'reconciled_at' => $reception->reconciledAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * Fila de un bulto recibido (sin id: el repositorio lo genera al insertar, append-only por clave de
     * idempotencia).
     *
     * @return array<string, mixed>
     */
    public function toUnitRow(NodeReception $reception, ReceivedUnit $unit): array
    {
        return [
            'node_reception_id' => $reception->id()->toString(),
            'unit_id' => $unit->unitId,
            'lat' => $unit->geo->lat,
            'lng' => $unit->geo->lng,
            'device_id' => $unit->device->deviceId,
            'user_id' => $unit->device->userId,
            'occurred_at' => $unit->occurredAt->format(DATE_ATOM),
            'idempotency_key' => $unit->idempotencyKey,
        ];
    }

    /**
     * Reconstruye el agregado completo (con sus bultos recibidos) desde sus filas.
     */
    public function toDomain(NodeReceptionRecord $record): NodeReception
    {
        $received = [];
        foreach ($record->units as $unitRecord) {
            $received[] = ReceivedUnit::record(
                $unitRecord->unit_id,
                GeoPoint::of((float) $unitRecord->lat, (float) $unitRecord->lng),
                DeviceContext::of($unitRecord->device_id, $unitRecord->user_id),
                new DateTimeImmutable($unitRecord->occurred_at),
                $unitRecord->idempotency_key,
            );
        }

        return NodeReception::reconstitute(
            NodeReceptionId::fromString($record->id),
            $record->shipment_id,
            $record->origin_node_id,
            $record->destination_node_id,
            Corridor::from($record->corridor),
            $record->expected_unit_ids,
            $received,
            NodeReceptionStatus::from($record->status),
            new DateTimeImmutable($record->opened_at),
            $record->reconciled_at !== null ? new DateTimeImmutable($record->reconciled_at) : null,
        );
    }
}
