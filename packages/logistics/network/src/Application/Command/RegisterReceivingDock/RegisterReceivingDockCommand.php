<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\RegisterReceivingDock;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un andén de recepción en un almacén central. DTO inmutable con datos
 * primitivos: el handler los convierte en value objects, valida que el almacén exista y sea un almacén
 * central, y rechaza códigos de andén duplicados dentro del mismo almacén.
 */
final readonly class RegisterReceivingDockCommand implements Command
{
    public function __construct(
        public string $warehouseId,
        public string $code,
        public string $name,
        public int $appointmentsPerSlot,
    ) {}
}
