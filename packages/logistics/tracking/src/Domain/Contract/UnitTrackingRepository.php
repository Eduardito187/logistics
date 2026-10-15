<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Contract;

use Logistics\Tracking\Domain\Model\UnitTracking;
use Logistics\Tracking\Domain\ValueObject\UnitTrackingId;

/**
 * Puerto de persistencia del seguimiento per-bulto. El dominio lo define; Eloquent lo implementa.
 *
 * findByUnitId() sirve al puente de proyección (llega un evento per-bulto de execution con el unit_id)
 * y listByShipmentId() a las vistas (detalle de bultos de un envío).
 */
interface UnitTrackingRepository
{
    public function nextIdentity(): UnitTrackingId;

    public function save(UnitTracking $unit): void;

    public function findByUnitId(string $unitId): ?UnitTracking;

    /**
     * Bultos de un envío, en orden estable por unit_id.
     *
     * @return list<UnitTracking>
     */
    public function listByShipmentId(string $shipmentId): array;
}
