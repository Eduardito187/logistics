<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\ListLegs;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista todos los tramos/corredores de la red. Sin filtros por ahora: el volumen de tramos es chico.
 */
final readonly class ListLegsQuery implements Query {}
