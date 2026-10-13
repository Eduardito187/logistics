<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Contract;

use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;

/**
 * Puerto de persistencia del seguimiento. El dominio lo define; la infraestructura (Eloquent) lo
 * implementa.
 *
 * findByToken() sirve a la página pública (entra el token de la URL) y findByShipmentId() al puente de
 * proyección (llega un evento de execution con el id del envío).
 */
interface TrackingRepository
{
    public function nextIdentity(): TrackingId;

    public function save(ShipmentTracking $tracking): void;

    public function findById(TrackingId $id): ?ShipmentTracking;

    public function findByToken(TrackingToken $token): ?ShipmentTracking;

    public function findByShipmentId(string $shipmentId): ?ShipmentTracking;
}
