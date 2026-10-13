<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterHelper;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un ayudante nuevo en la flota.
 *
 * Los documentos llegan como pares simples {type, expires_on (Y-m-d)} para no acoplar la entrada a los
 * VO del dominio; el handler los convierte.
 */
final readonly class RegisterHelper implements Command
{
    /**
     * @param list<array{type: string, expires_on: string}> $documents
     */
    public function __construct(
        public string $name,
        public ?string $phone = null,
        public array $documents = [],
        public bool $active = true,
    ) {}
}
