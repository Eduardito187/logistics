<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

use Logistics\Labels\Domain\Model\Printer;
use Logistics\Labels\Domain\ValueObject\PrinterId;

/**
 * Persistencia del agregado Printer (lado escritura).
 */
interface PrinterRepository
{
    public function find(PrinterId $id): ?Printer;

    public function save(Printer $printer): void;

    public function remove(PrinterId $id): void;
}
