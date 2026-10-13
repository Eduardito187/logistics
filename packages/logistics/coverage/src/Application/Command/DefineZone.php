<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir una zona de cobertura nueva.
 *
 * Lleva solo primitivos: el handler construye los value objects del dominio.
 */
final readonly class DefineZone implements Command
{
    /**
     * @param array<int, array{0: float|int, 1: float|int}> $ring  anillo exterior en orden GeoJSON [lng, lat]
     * @param array<string, mixed>                          $rules reglas en forma primitiva (ver ZoneRules::fromArray)
     */
    public function __construct(
        public string $name,
        public ?string $parentAreaId,
        public array $ring,
        public array $rules,
    ) {}
}
