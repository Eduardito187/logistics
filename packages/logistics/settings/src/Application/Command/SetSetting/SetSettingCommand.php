<?php

declare(strict_types=1);

namespace Logistics\Settings\Application\Command\SetSetting;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de fijar el valor de una clave: crea su próxima versión, nunca pisa la anterior.
 *
 * `effectiveFrom` es una fecha ISO 8601 opcional; si viene null, rige desde "ahora" (lo resuelve el
 * handler con el Clock). `createdBy` identifica a quién hizo el cambio, para la auditoría.
 */
final readonly class SetSettingCommand implements Command
{
    /**
     * @param array<string, mixed> $value
     */
    public function __construct(
        public string $key,
        public array $value,
        public ?string $effectiveFrom = null,
        public ?string $createdBy = null,
    ) {}
}
