<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Contract;

/**
 * Puerto para resolver el email del cliente titular de un envío (vale nominativo), sin acoplar a tracking.
 * Devuelve null si no se conoce; el email puede completarse luego y el aviso se manda igual (principio c).
 */
interface CustomerContactReader
{
    public function emailForShipment(string $shipmentId): ?string;
}
