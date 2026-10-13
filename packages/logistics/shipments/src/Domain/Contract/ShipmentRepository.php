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
     * Clave de idempotencia: busca por el par (fuente, id externo). Devuelve null si no existe.
     */
    public function findBySourceReference(SourceReference $reference): ?Shipment;

    /**
     * @return list<Shipment>
     */
    public function all(): array;
}
