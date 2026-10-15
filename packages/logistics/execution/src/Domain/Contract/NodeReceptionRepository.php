<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Contract;

use Logistics\Execution\Domain\Model\NodeReception;
use Logistics\Execution\Domain\ValueObject\NodeReceptionId;

/**
 * Puerta de persistencia del agregado NodeReception (recepción en nodo). El dominio la define; la
 * implementación Eloquent vive en Infrastructure. Reconstruye el agregado completo con sus bultos
 * recibidos (append-only), no filas sueltas.
 */
interface NodeReceptionRepository
{
    /**
     * Reserva una identidad nueva. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextIdentity(): NodeReceptionId;

    public function save(NodeReception $reception): void;

    public function findById(NodeReceptionId $id): ?NodeReception;

    /**
     * Busca la recepción abierta o conciliada de un envío en un nodo destino (una por par
     * envío+destino). Devuelve null si todavía no se abrió.
     */
    public function findByShipmentAndDestination(string $shipmentId, string $destinationNodeId): ?NodeReception;
}
