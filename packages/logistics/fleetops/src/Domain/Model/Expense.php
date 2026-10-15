<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Model;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Event\ExpenseRecorded;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\FleetOps\Domain\ValueObject\ExpenseId;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Gasto de flota que no es combustible ni mantenimiento (esos son otros agregados): peaje, multa,
 * llantas, repuestos sueltos, lavado, parqueo, seguro u otros. Alimenta el TCO del vehículo.
 *
 * El importe se guarda en centavos (Money) para exactitud; se referencia al vehículo por `vehicleId`.
 */
final class Expense extends AggregateRoot
{
    private function __construct(
        private readonly ExpenseId $id,
        private readonly string $vehicleId,
        private readonly ExpenseCategory $category,
        private readonly Money $amount,
        private readonly ?string $description,
        private readonly DateTimeImmutable $incurredAt,
        private readonly ?string $note,
    ) {}

    /**
     * Registra un gasto incurrido por un vehículo.
     */
    public static function record(
        ExpenseId $id,
        string $vehicleId,
        ExpenseCategory $category,
        Money $amount,
        ?string $description,
        DateTimeImmutable $incurredAt,
        ?string $note,
    ): self {
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            throw FleetOpsException::because('El vehículo del gasto no puede estar vacío.');
        }
        if ($amount->cents <= 0) {
            throw FleetOpsException::because('El importe del gasto debe ser positivo.');
        }

        $expense = new self($id, $vehicleId, $category, $amount, $description, $incurredAt, $note);
        $expense->recordEvent(new ExpenseRecorded(
            $id->toString(),
            $vehicleId,
            $category->value,
            $amount->cents,
            $amount->currency,
            $incurredAt->format(DateTimeImmutable::ATOM),
        ));

        return $expense;
    }

    /**
     * Reconstituye un gasto desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        ExpenseId $id,
        string $vehicleId,
        ExpenseCategory $category,
        Money $amount,
        ?string $description,
        DateTimeImmutable $incurredAt,
        ?string $note,
    ): self {
        return new self($id, $vehicleId, $category, $amount, $description, $incurredAt, $note);
    }

    public function id(): ExpenseId
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function category(): ExpenseCategory
    {
        return $this->category;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function incurredAt(): DateTimeImmutable
    {
        return $this->incurredAt;
    }

    public function note(): ?string
    {
        return $this->note;
    }
}
