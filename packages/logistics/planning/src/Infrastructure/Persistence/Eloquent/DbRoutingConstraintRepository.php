<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Planning\Domain\Contract\RoutingConstraintRepository;
use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;
use Logistics\Planning\Domain\ValueObject\VehicleRouteProfile;

/**
 * Escritor de las restricciones de ruteo sobre las tablas propias de Planificación
 * (planning_vehicle_restrictions / planning_shipment_requirements), por query builder: upsert por id
 * (una fila por camión / por envío). Las listas van como jsonb; una lista vacía se guarda null (sin
 * restricción), igual que lo interpreta el lector.
 */
final readonly class DbRoutingConstraintRepository implements RoutingConstraintRepository
{
    private const VEHICLE_TABLE = 'planning_vehicle_restrictions';

    private const SHIPMENT_TABLE = 'planning_shipment_requirements';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function saveVehicleProfile(string $vehicleId, VehicleRouteProfile $profile): void
    {
        $data = $profile->toArray();
        $now = $this->now();

        $this->connection->table(self::VEHICLE_TABLE)->upsert(
            [[
                'vehicle_id' => $vehicleId,
                'allowed_load_types' => $this->json($data['allowed_load_types']),
                'allowed_zones' => $this->json($data['allowed_zones']),
                'forbidden_zones' => $this->json($data['forbidden_zones']),
                'max_height_m' => $data['max_height_m'],
                'max_weight_kg' => $data['max_weight_kg'],
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['vehicle_id'],
            ['allowed_load_types', 'allowed_zones', 'forbidden_zones', 'max_height_m', 'max_weight_kg', 'updated_at'],
        );
    }

    public function removeVehicleProfile(string $vehicleId): void
    {
        $this->connection->table(self::VEHICLE_TABLE)->where('vehicle_id', $vehicleId)->delete();
    }

    public function saveShipmentRequirement(string $shipmentId, ShipmentRouteRequirement $requirement): void
    {
        $data = $requirement->toArray();
        $now = $this->now();

        $this->connection->table(self::SHIPMENT_TABLE)->upsert(
            [[
                'shipment_id' => $shipmentId,
                'load_type' => $data['load_type'],
                'zone' => $data['zone'],
                'height_m' => $data['height_m'],
                'weight_kg' => $data['weight_kg'],
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['shipment_id'],
            ['load_type', 'zone', 'height_m', 'weight_kg', 'updated_at'],
        );
    }

    public function removeShipmentRequirement(string $shipmentId): void
    {
        $this->connection->table(self::SHIPMENT_TABLE)->where('shipment_id', $shipmentId)->delete();
    }

    /**
     * Lista de etiquetas como jsonb; null o vacía = sin restricción (columna null).
     *
     * @param list<string>|null $values
     */
    private function json(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        $encoded = json_encode(array_values($values));

        return $encoded === false ? null : $encoded;
    }

    private function now(): string
    {
        return (new DateTimeImmutable)->format('Y-m-d H:i:s');
    }
}
