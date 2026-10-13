<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reemplazar las reglas de operación de una zona existente.
 */
final readonly class UpdateZoneRules implements Command
{
    /**
     * @param array<string, mixed> $rules     reglas en forma primitiva (ver ZoneRules::fromArray)
     * @param string|null          $updatedBy quién publica la versión, para la auditoría de settings
     */
    public function __construct(
        public string $zoneId,
        public array $rules,
        public ?string $updatedBy = null,
    ) {}
}
