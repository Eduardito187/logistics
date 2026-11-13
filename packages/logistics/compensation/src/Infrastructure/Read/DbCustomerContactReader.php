<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Read;

use Illuminate\Database\ConnectionInterface;
use Logistics\Compensation\Application\Contract\CustomerContactReader;

/**
 * Resuelve el email del cliente de un envío desde la proyección de shipments (`customer_email`). Devuelve
 * null si no está cargado; el aviso se manda igual cuando el email se complete (principio c).
 */
final readonly class DbCustomerContactReader implements CustomerContactReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function emailForShipment(string $shipmentId): ?string
    {
        $value = $this->db->table('shipments')->where('id', $shipmentId)->value('customer_email');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
