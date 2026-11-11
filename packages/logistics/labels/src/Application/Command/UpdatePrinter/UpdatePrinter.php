<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\UpdatePrinter;

use Logistics\Shared\Application\Command\Command;

/**
 * Edita una impresora registrada (incluye alta/baja lógica por `active`). Cambiar host/puerto aquí se
 * refleja en la app en su próxima consulta, sin reinstalar.
 */
final readonly class UpdatePrinter implements Command
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
        public bool $active,
    ) {}
}
