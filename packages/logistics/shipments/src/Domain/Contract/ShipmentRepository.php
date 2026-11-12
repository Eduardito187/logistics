<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Contract;

use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Puerta de persistencia del agregado Shipment. El dominio la define; la implementación Eloquent vive
 * en Infrastructure. Reconstruye el agregado completo (con sus líneas y unidades), no filas sueltas.
 */
interface ShipmentRepository
{
    /**
     * Reserva una identidad nueva. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextIdentity(): ShipmentId;

    public function save(Shipment $shipment): void;

    public function findById(ShipmentId $id): ?Shipment;

    /**
     * Igual que findById, pero BLOQUEA la fila del envío hasta el fin de la transacción en curso
     * (SELECT ... FOR UPDATE). Lo usan los cambios de estado que reaccionan a hechos concurrentes
     * (varios workers del outbox sobre el mismo envío) para no pisarse: el segundo espera al primero y
     * decide sobre el estado ya actualizado. Fuera de una transacción se comporta como findById.
     */
    public function findByIdForUpdate(ShipmentId $id): ?Shipment;

    /**
     * Clave de idempotencia: busca por el par (fuente, id externo). Devuelve null si no existe.
     */
    public function findBySourceReference(SourceReference $reference): ?Shipment;

    /**
     * @return list<Shipment>
     */
    public function all(): array;
}
