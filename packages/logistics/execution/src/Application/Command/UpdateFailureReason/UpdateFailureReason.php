<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\UpdateFailureReason;

use Logistics\Shared\Application\Command\Command;

/**
 * Edita un motivo de entrega fallida existente, identificado por su `code` (inmutable). Los campos en null
 * se dejan como están (edición parcial); el code nunca cambia.
 */
final readonly class UpdateFailureReason implements Command
{
    public function __construct(
        public string $code,
        public ?string $label = null,
        public ?bool $active = null,
        public ?bool $requiresPhoto = null,
        public ?bool $requiresNote = null,
        public ?string $noteLabel = null,
        public ?int $sortOrder = null,
    ) {}
}
