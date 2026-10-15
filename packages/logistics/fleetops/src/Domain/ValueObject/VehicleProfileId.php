<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad interna del perfil técnico de un vehículo (para eventos). Por dentro es un UUID v7.
 *
 * Ojo: la tabla usa `vehicle_id` (el id del vehículo en fleet) como PK 1:1; este id vive aparte, en la
 * columna `id`, solo para dar identidad estable a los eventos de dominio del perfil.
 */
final readonly class VehicleProfileId extends AbstractId {}
