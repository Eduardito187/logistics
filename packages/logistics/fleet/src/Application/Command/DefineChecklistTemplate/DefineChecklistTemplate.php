<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\DefineChecklistTemplate;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de dar de alta (sin id) o reemplazar por completo (con id) una plantilla del checklist
 * pre-salida. Los ítems llegan como primitivos; el handler construye y valida los VO del dominio.
 */
final readonly class DefineChecklistTemplate implements Command
{
    /**
     * @param list<array{key: string, label: string, category: string, criticality: string, requires_photo?: bool}> $items
     */
    public function __construct(
        public string $name,
        public array $items,
        public ?string $vehicleType = null,
        public bool $active = true,
        public ?string $templateId = null,
    ) {}
}
