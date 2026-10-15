<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\UpdateVehicleProfile;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de actualizar la ficha técnica (perfil) de un vehículo.
 *
 * Los campos llegan como primitivos (nullable) para no acoplar la entrada a los VO del dominio; el
 * handler los pasa al VO VehicleProfile. `modelYear` es el año de fabricación; las rutas de foto son las
 * claves del objeto en disco de cada uno de los cuatro lados.
 */
final readonly class UpdateVehicleProfile implements Command
{
    public function __construct(
        public string $vehicleId,
        public ?string $make = null,
        public ?string $model = null,
        public ?int $modelYear = null,
        public ?string $vin = null,
        public ?string $engineNumber = null,
        public ?string $color = null,
        public ?string $photoFront = null,
        public ?string $photoBack = null,
        public ?string $photoLeft = null,
        public ?string $photoRight = null,
    ) {}
}
