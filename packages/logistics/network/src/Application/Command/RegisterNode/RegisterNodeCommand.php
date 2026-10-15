<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\RegisterNode;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un nodo nuevo. DTO inmutable con datos primitivos: el handler los convierte
 * en los value objects del dominio y aplica las validaciones.
 */
final readonly class RegisterNodeCommand implements Command
{
    public function __construct(
        public string $code,
        public string $name,
        public string $type,
        public float $latitude,
        public float $longitude,
        public ?string $department = null,
    ) {}
}
