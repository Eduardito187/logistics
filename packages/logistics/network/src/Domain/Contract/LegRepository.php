<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Contract;

use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeId;

/**
 * Puerto de persistencia de tramos/corredores. El dominio lo define; la infraestructura (Eloquent)
 * lo implementa.
 *
 * nextIdentity() entrega el id antes de persistir, para que el agregado nazca ya con su identidad y
 * sus eventos puedan referenciarla.
 */
interface LegRepository
{
    public function nextIdentity(): LegId;

    public function save(Leg $leg): void;

    public function remove(Leg $leg): void;

    public function findById(LegId $id): ?Leg;

    /**
     * Devuelve el tramo con ese origen/destino/corredor, o null si no existe. Sirve para evitar
     * duplicados (el índice único de la tabla cubre la misma combinación).
     */
    public function findByEndpoints(NodeId $from, NodeId $to, CorridorType $corridor): ?Leg;

    /**
     * @return list<Leg>
     */
    public function all(): array;
}
