<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\UpdateDriverProfile;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de actualizar los datos personales (perfil) de un chofer.
 *
 * Los campos llegan como primitivos (nullable) para no acoplar la entrada a los VO del dominio; el
 * handler los convierte. `birthDate` es 'Y-m-d', `licenseCategory` es el valor del enum LicenseCategory,
 * y `references` es una lista de pares {name, relationship?, phone?, note?}.
 */
final readonly class UpdateDriverProfile implements Command
{
    /**
     * @param list<array{name: string, relationship?: string|null, phone?: string|null, note?: string|null}> $references
     */
    public function __construct(
        public string $driverId,
        public ?string $photoPath = null,
        public ?string $birthDate = null,
        public ?string $nationalId = null,
        public ?string $phonePrimary = null,
        public ?string $phoneSecondary = null,
        public ?string $address = null,
        public ?string $licenseCategory = null,
        public ?string $emergencyContactName = null,
        public ?string $emergencyContactPhone = null,
        public array $references = [],
    ) {}
}
