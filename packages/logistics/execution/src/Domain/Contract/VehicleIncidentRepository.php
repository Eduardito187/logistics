<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Contract;

use Logistics\Execution\Domain\Model\VehicleIncident;
use Logistics\Execution\Domain\ValueObject\VehicleIncidentId;

/**
 * Puerta de persistencia del agregado VehicleIncident (incidente de camión). El dominio la define; la
 * implementación Eloquent vive en Infrastructure. El incidente es un hecho inmutable: solo se agrega.
 */
interface VehicleIncidentRepository
{
    /**
     * Reserva una identidad nueva. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextIdentity(): VehicleIncidentId;

    public function save(VehicleIncident $incident): void;

    /**
     * ¿Ya existe un incidente con esta clave de idempotencia? Evita duplicar el hecho ante reenvíos de
     * la app offline del chofer. Null/empty nunca existe.
     */
    public function existsByIdempotencyKey(?string $idempotencyKey): bool;
}
