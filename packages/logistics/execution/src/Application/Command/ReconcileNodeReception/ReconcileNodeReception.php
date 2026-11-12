<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReconcileNodeReception;

use Logistics\Execution\Domain\ValueObject\MissingUnitReason;
use Logistics\Shared\Application\Command\Command;

/**
 * Cierra la recepción en el nodo conciliando lo recibido contra lo esperado (faltantes/sobrantes).
 *
 * Idempotente: si la recepción ya estaba conciliada, el agregado no hace nada.
 *
 * Informe opcional del operador (aditivo): notas, foto de evidencia (referencia del disco de evidencia),
 * motivo de cada faltante (`not_arrived`, `damaged`, `wrong_store`, `other`) y el envío real de cada
 * sobrante. Solo explica las discrepancias; nunca cambia el cálculo, que sale de los escaneos.
 */
final readonly class ReconcileNodeReception implements Command
{
    /**
     * @param list<array{unit_id: string, reason: string}>                      $missing
     * @param list<array{unit_id: string, belongs_to_shipment_id: string|null}> $surplus
     */
    public function __construct(
        public string $shipmentId,
        public string $destinationNodeId,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public ?string $notes = null,
        public array $missing = [],
        public array $surplus = [],
        public ?string $photoPath = null,
    ) {}

    /**
     * Motivos de faltante aceptados (para la validación HTTP, sin que Presentation toque el dominio).
     *
     * @return list<string>
     */
    public static function missingReasons(): array
    {
        return MissingUnitReason::values();
    }
}
