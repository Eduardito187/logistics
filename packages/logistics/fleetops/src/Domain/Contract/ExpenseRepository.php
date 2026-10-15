<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\Expense;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\FleetOps\Domain\ValueObject\ExpenseId;

/**
 * Puerto de persistencia de gastos de flota. La implementación vive en Infrastructure.
 */
interface ExpenseRepository
{
    public function nextIdentity(): ExpenseId;

    public function save(Expense $expense): void;

    public function findById(ExpenseId $id): ?Expense;

    /**
     * Lista gastos filtrando opcionalmente por vehículo, categoría y rango de fechas de incurrencia.
     *
     * @return list<Expense>
     */
    public function listBy(
        ?string $vehicleId = null,
        ?ExpenseCategory $category = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array;

    /**
     * Total gastado por un vehículo en un rango (centavos + moneda), para el TCO.
     *
     * @return array{cents: int, currency: string}
     */
    public function totalFor(
        string $vehicleId,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array;
}
