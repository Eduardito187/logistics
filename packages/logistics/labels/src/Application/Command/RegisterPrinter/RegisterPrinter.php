<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\RegisterPrinter;

use Logistics\Shared\Application\Command\Command;

/**
 * Registra una impresora de red para una sede. El id (UUID v7) lo genera quien despacha, para poder
 * redirigir/devolverlo sin releer.
 */
final readonly class RegisterPrinter implements Command
{
    public function __construct(
        public string $printerId,
        public string $name,
        public ?string $model,
        public string $host,
        public int $port,
        public string $language,
        public ?string $nodeId,
        public ?string $location,
        public bool $active = true,
    ) {}
}
