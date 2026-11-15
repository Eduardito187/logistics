<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

/**
 * Orden de reparto sugerido para un viaje, en primitivos.
 *
 * - `stops`: una entrada por pedido, en el orden sugerido. `eta_at` es null solo para un pedido sin
 *   ubicación (va al final y lleva su aviso); `within_window` es false cuando se llega después del cierre
 *   de la hora que eligió el cliente.
 * - `changed`: el orden sugerido difiere del que mandó la app.
 * - `warnings`: avisos legibles por máquina (`code`) y por el planificador (`detail`, en español y sin
 *   identificadores internos). Códigos: window_missed, over_capacity, out_of_department, missing_location.
 */
final readonly class TripSequenceSuggestionView
{
    public const WARNING_WINDOW_MISSED = 'window_missed';

    public const WARNING_OVER_CAPACITY = 'over_capacity';

    public const WARNING_OUT_OF_DEPARTMENT = 'out_of_department';

    public const WARNING_MISSING_LOCATION = 'missing_location';

    /**
     * @param list<string>                                                                              $orderedShipmentIds
     * @param list<array{shipment_id: string, sequence: int, eta_at: string|null, within_window: bool}> $stops
     * @param list<array{code: string, shipment_id: string, detail: string}>                            $warnings
     */
    public function __construct(
        public array $orderedShipmentIds,
        public array $stops,
        public float $distanceKm,
        public int $durationMinutes,
        public bool $changed,
        public array $warnings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ordered_shipment_ids' => $this->orderedShipmentIds,
            'stops' => $this->stops,
            'distance_km' => $this->distanceKm,
            'duration_minutes' => $this->durationMinutes,
            'changed' => $this->changed,
            'warnings' => $this->warnings,
        ];
    }
}
