<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Contract;

use Logistics\Network\Domain\ReceivingDock;
use Logistics\Network\Domain\ValueObject\DockCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\ReceivingDockId;

/**
 * Puerto de persistencia de andenes de recepción. El dominio lo define; la infraestructura (Eloquent) lo
 * implementa.
 *
 * nextIdentity() entrega el id antes de persistir, para que el agregado nazca ya con su identidad y sus
 * eventos puedan referenciarla.
 */
interface ReceivingDockRepository
{
    public function nextIdentity(): ReceivingDockId;

    public function save(ReceivingDock $dock): void;

    public function findById(ReceivingDockId $id): ?ReceivingDock;

    /**
     * Busca un andén por su código dentro de un almacén (clave humana única por almacén). Null si no existe.
     */
    public function findByCode(NodeId $warehouseId, DockCode $code): ?ReceivingDock;

    /**
     * Andenes de un almacén, ordenados de forma estable (por código).
     *
     * @return list<ReceivingDock>
     */
    public function byWarehouse(NodeId $warehouseId): array;
}
