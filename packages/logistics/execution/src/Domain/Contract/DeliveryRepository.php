<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Contract;

use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;

/**
 * Puerta de persistencia del agregado Delivery. El dominio la define; la implementación Eloquent vive
 * en Infrastructure. Reconstruye el agregado completo con sus escaneos (append-only), no filas sueltas.
 */
interface DeliveryRepository
{
    /**
     * Reserva una identidad nueva. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextIdentity(): DeliveryId;

    public function save(Delivery $delivery): void;

    public function findById(DeliveryId $id): ?Delivery;

    /**
     * Busca la entrega de un envío (una por envío). Devuelve null si todavía no se abrió.
     */
    public function findByShipmentId(string $shipmentId): ?Delivery;

    /**
     * Entregas asignadas a un vehículo (su hoja de ruta), en orden estable. Con $limit, solo esa página
     * (LIMIT/OFFSET en la base, sin materializar la colección completa). Con $statuses, solo las entregas
     * en alguno de esos estados (valores de DeliveryStatus); vacío = todas.
     *
     * @param  list<string>   $statuses
     * @return list<Delivery>
     */
    public function findByVehicleId(string $vehicleId, ?int $limit = null, int $offset = 0, array $statuses = []): array;

    /**
     * Total de entregas asignadas a un vehículo (para la meta de la paginación), con el mismo filtro.
     *
     * @param list<string> $statuses
     */
    public function countByVehicleId(string $vehicleId, array $statuses = []): int;
}
