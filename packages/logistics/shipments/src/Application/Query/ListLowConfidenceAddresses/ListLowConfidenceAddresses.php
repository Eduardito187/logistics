<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListLowConfidenceAddresses;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los envíos cuya dirección quedó con confianza baja: candidatos a confirmar con el cliente
 * antes de salir a ruta (KPI del piloto: llamadas del chofer por ubicación).
 */
final readonly class ListLowConfidenceAddresses implements Query {}
