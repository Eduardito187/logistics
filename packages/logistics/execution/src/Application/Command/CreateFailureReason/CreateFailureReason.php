<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\CreateFailureReason;

use Logistics\Shared\Application\Command\Command;

/**
 * Alta de un motivo de entrega fallida en el catálogo (admin). Si no viene `code`, se deriva de la etiqueta
 * (slug en MAYÚSCULAS); debe ser único (si ya existe → excepción de dominio que la puerta traduce a 409).
 */
final readonly class CreateFailureReason implements Command
{
    public function __construct(
        public string $label,
        public ?string $code = null,
        public bool $active = true,
        public bool $requiresPhoto = false,
        public bool $requiresNote = false,
        public ?string $noteLabel = null,
        public int $sortOrder = 0,
    ) {}
}
