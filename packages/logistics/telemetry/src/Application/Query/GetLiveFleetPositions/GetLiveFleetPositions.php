<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetLiveFleetPositions;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la posición en vivo de toda la flota (la última lectura conocida de cada vehículo).
 */
final readonly class GetLiveFleetPositions implements Query {}
