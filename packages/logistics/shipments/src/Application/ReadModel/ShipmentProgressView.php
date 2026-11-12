<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * AVANCE operativo de un envío para la app (preparador, chofer, tienda): a qué camión se asignó, cuándo
 * se preparó/cargó/entregó, cuántas etiquetas se emitieron y el estado de su entrega en ejecución.
 *
 * Es un enriquecimiento del lado de lectura (CQRS) armado desde las tablas de ejecución, planificación,
 * flota y etiquetas en lotes por página; no es parte del agregado. Cada campo es null cuando se
 * desconoce (todavía no ocurrió o la fuente no lo registra). Las fechas van en ISO 8601 (ATOM).
 */
final readonly class ShipmentProgressView
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $plate = null,
        public ?string $assignedAt = null,
        public ?string $preparedAt = null,
        public ?string $loadedAt = null,
        public ?string $deliveredAt = null,
        public ?int $labelsEmittedCount = null,
        public ?string $executionStatus = null,
    ) {}

    /**
     * Avance vacío (campos en null): envío sin rastros todavía fuera de su recepción.
     */
    public static function unknown(): self
    {
        return new self;
    }

    /**
     * @return array{vehicle_id: string|null, plate: string|null, assigned_at: string|null, prepared_at: string|null, loaded_at: string|null, delivered_at: string|null, labels_emitted_count: int|null, execution_status: string|null}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'plate' => $this->plate,
            'assigned_at' => $this->assignedAt,
            'prepared_at' => $this->preparedAt,
            'loaded_at' => $this->loadedAt,
            'delivered_at' => $this->deliveredAt,
            'labels_emitted_count' => $this->labelsEmittedCount,
            'execution_status' => $this->executionStatus,
        ];
    }
}
