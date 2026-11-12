<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReception;
use Logistics\Execution\Presentation\Http\Request\ReconcileNodeReceptionRequest;

/**
 * POST /app — cierra (concilia) la recepción de un envío en un nodo destino (puerta app).
 *
 * Compara lo recibido contra lo esperado y graba las discrepancias (faltantes/sobrantes). Idempotente:
 * reconciliar dos veces no re-emite el evento. Acepta además el informe del operador (notas, foto, motivo
 * de cada faltante, envío real de cada sobrante), que se devuelve en `reconciliation`.
 */
final class ReconcileNodeReceptionController extends ExecutionController
{
    public function __invoke(ReconcileNodeReceptionRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new ReconcileNodeReception(
            shipmentId: (string) $data['shipment_id'],
            destinationNodeId: (string) $data['destination_node_id'],
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: (string) $data['occurred_at'],
            notes: self::stringOrNull($data['notes'] ?? null),
            missing: self::missingFrom($data['missing'] ?? null),
            surplus: self::surplusFrom($data['surplus'] ?? null),
            photoPath: self::stringOrNull($data['photo_path'] ?? null),
        ));

        return $this->nodeReceptionResponse(
            (string) $data['shipment_id'],
            (string) $data['destination_node_id'],
        );
    }

    /**
     * @return list<array{unit_id: string, reason: string}>
     */
    private static function missingFrom(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $missing = [];
        foreach ($rows as $row) {
            $unitId = is_array($row) ? ($row['unit_id'] ?? null) : null;
            $reason = is_array($row) ? ($row['reason'] ?? null) : null;
            if (is_string($unitId) && is_string($reason)) {
                $missing[] = ['unit_id' => $unitId, 'reason' => $reason];
            }
        }

        return $missing;
    }

    /**
     * @return list<array{unit_id: string, belongs_to_shipment_id: string|null}>
     */
    private static function surplusFrom(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $surplus = [];
        foreach ($rows as $row) {
            $unitId = is_array($row) ? ($row['unit_id'] ?? null) : null;
            if (is_string($unitId)) {
                $surplus[] = [
                    'unit_id' => $unitId,
                    'belongs_to_shipment_id' => self::stringOrNull(is_array($row) ? ($row['belongs_to_shipment_id'] ?? null) : null),
                ];
            }
        }

        return $surplus;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
