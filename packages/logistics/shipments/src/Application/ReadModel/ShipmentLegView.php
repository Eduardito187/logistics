<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Modelo de lectura de un tramo del envío. Solo primitivos, listo para serializar a JSON. Lo consumirán
 * las olas de UI/mapas (dibujar el recorrido por tramos, mostrar quién mueve cada uno).
 *
 * Los departamentos (`fromDepartment`/`toDepartment`) son el valor legible pensado para la UI; los ids de
 * nodo y de transportadora son técnicos (UUID) y NO se muestran crudos en el frontend (regla del dueño):
 * las olas de UI los resuelven a nombre/placa con los helpers del patrón de enriquecimiento.
 */
final readonly class ShipmentLegView
{
    public function __construct(
        public string $id,
        public int $seq,
        public ?string $fromNodeId,
        public ?string $toNodeId,
        public ?string $fromDepartment,
        public ?string $toDepartment,
        public string $haulageMode,
        public ?string $carrierId,
        public string $status,
        public ?string $departedAt,
        public ?string $arrivedAt,
    ) {}

    /**
     * @return array{id: string, seq: int, from_node_id: string|null, to_node_id: string|null, from_department: string|null, to_department: string|null, haulage_mode: string, carrier_id: string|null, status: string, departed_at: string|null, arrived_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'seq' => $this->seq,
            'from_node_id' => $this->fromNodeId,
            'to_node_id' => $this->toNodeId,
            'from_department' => $this->fromDepartment,
            'to_department' => $this->toDepartment,
            'haulage_mode' => $this->haulageMode,
            'carrier_id' => $this->carrierId,
            'status' => $this->status,
            'departed_at' => $this->departedAt,
            'arrived_at' => $this->arrivedAt,
        ];
    }
}
