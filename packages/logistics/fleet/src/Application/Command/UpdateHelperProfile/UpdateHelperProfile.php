<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\UpdateHelperProfile;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de actualizar los datos personales (perfil) de un ayudante.
 *
 * Los campos llegan como primitivos (nullable) para no acoplar la entrada a los VO del dominio; el
 * handler los convierte. `birthDate` es 'Y-m-d' y `references` es una lista de pares
 * {name, relationship?, phone?, note?}. El teléfono primario del ayudante es el `phone` del agregado y
 * no se toca por acá.
 */
final readonly class UpdateHelperProfile implements Command
{
    /**
     * @param list<array{name: string, relationship?: string|null, phone?: string|null, note?: string|null}> $references
     */
    public function __construct(
        public string $helperId,
        public ?string $photoPath = null,
        public ?string $birthDate = null,
        public ?string $nationalId = null,
        public ?string $phoneSecondary = null,
        public ?string $address = null,
        public ?string $emergencyContactName = null,
        public ?string $emergencyContactPhone = null,
        public array $references = [],
    ) {}
}
