<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;

/**
 * Traduce la posición en vivo: del ping entrante a la fila que se pisa, y de la fila a la estructura
 * primitiva que consume la capa de lectura (el contrato de dominio no conoce los ReadModels).
 */
final class VehiclePositionMapper
{
}
