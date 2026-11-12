<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ResolveNodeReceptionTarget;

use Logistics\Execution\Application\Contract\NodeReceptionTargetReader;
use Logistics\Execution\Application\ReadModel\NodeReceptionTarget;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Deriva destino, origen, corredor y manifiesto de una recepción en nodo (ver ResolveNodeReceptionTarget).
 *
 * Si algo no se puede derivar (envío inexistente, sin destino, sin productos, sin CD de origen, origen =
 * destino) lanza ExecutionException → 422 con el motivo, para que la app lo muestre y no reintente a ciegas.
 *
 * @implements QueryHandler<ResolveNodeReceptionTarget, NodeReceptionTarget>
 */
final class ResolveNodeReceptionTargetHandler implements QueryHandler
{
    public function __construct(
        private readonly NodeReceptionTargetReader $reader,
    ) {}

    public function handle(Query $query): NodeReceptionTarget
    {
        if (!$query instanceof ResolveNodeReceptionTarget) {
            throw ExecutionException::because('ResolveNodeReceptionTargetHandler solo maneja ResolveNodeReceptionTarget');
        }

        $explicitExpected = $query->expectedUnitIds !== null && $query->expectedUnitIds !== [];
        $needsShipment = self::blank($query->destinationNodeId) || self::blank($query->originNodeId) || !$explicitExpected;

        $shipment = $needsShipment ? $this->reader->shipment($query->shipmentId) : null;
        if ($needsShipment && $shipment === null) {
            throw ExecutionException::because("el envío {$query->shipmentId} no existe");
        }

        // Destino: explícito → destino del envío → nodo del usuario.
        $destination = self::firstFilled(
            $query->destinationNodeId,
            $shipment['destination_node_id'] ?? null,
            $query->fallbackDestinationNodeId,
        );
        if ($destination === null) {
            throw ExecutionException::because('no se pudo determinar el nodo destino del envío: no tiene destino asignado');
        }

        // Origen: explícito → base del camión del envío → CD que abastece al destino / CD principal.
        $vehicleHome = $shipment['vehicle_home_node_id'] ?? null;
        $origin = self::firstFilled(
            $query->originNodeId,
            $vehicleHome !== $destination ? $vehicleHome : null,
        ) ?? $this->reader->defaultOriginFor($destination);
        if ($origin === null) {
            throw ExecutionException::because('no se pudo determinar el nodo de origen del envío: no hay centro de distribución activo');
        }
        if ($origin === $destination) {
            throw ExecutionException::because('el nodo de origen y el de destino no pueden ser el mismo');
        }

        // Productos esperados: explícitos → productos físicos del envío.
        $expected = $explicitExpected ? array_values($query->expectedUnitIds ?? []) : ($shipment['unit_ids'] ?? []);
        if ($expected === []) {
            throw ExecutionException::because("el envío {$query->shipmentId} no tiene productos para recibir");
        }

        // Tipos de nodo (corredor): explícitos o desde network.
        $kinds = self::blank($query->originNodeKind) || self::blank($query->destinationNodeKind)
            ? $this->reader->nodeKinds([$origin, $destination])
            : [];
        $originKind = self::firstFilled($query->originNodeKind, $kinds[$origin] ?? null);
        $destinationKind = self::firstFilled($query->destinationNodeKind, $kinds[$destination] ?? null);
        if ($originKind === null || $destinationKind === null) {
            throw ExecutionException::because('no se pudo determinar el tipo de nodo (CD/tienda) del origen o del destino');
        }

        return new NodeReceptionTarget(
            $query->shipmentId,
            $origin,
            $destination,
            strtoupper($originKind),
            strtoupper($destinationKind),
            $expected,
        );
    }

    private static function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    private static function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if (!self::blank($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }
}
