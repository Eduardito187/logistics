<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Model;

use DateTimeImmutable;
use Logistics\Compensation\Domain\Event\CaseApproved;
use Logistics\Compensation\Domain\Event\CaseOpened;
use Logistics\Compensation\Domain\Event\CaseRejected;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\AffectedValue;
use Logistics\Compensation\Domain\ValueObject\ApprovalLevel;
use Logistics\Compensation\Domain\ValueObject\CalculationResult;
use Logistics\Compensation\Domain\ValueObject\CaseStatus;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Agregado raíz CASO DE COMPENSACIÓN: el problema y la decisión.
 *
 * Custodia las invariantes de aprobación: cuatro ojos (quien propone no aprueba), override solo a la baja,
 * un caso solo se decide una vez. El cálculo del monto lo hace el servicio {@see
 * \Logistics\Compensation\Domain\Service\VoucherCalculator}; el caso solo guarda el resultado y la traza.
 */
final class CompensationCase extends AggregateRoot
{
    public const string SYSTEM_ACTOR = 'system';

    /**
     * @param array<string, mixed> $dimensions ownership, driver_id, vehicle_id, origin_node_id (opcionales)
     */
    private function __construct(
        private readonly CompensationCaseId $id,
        private readonly TriggerType $trigger,
        private readonly CompensationScope $scope,
        private readonly ?string $shipmentId,
        private readonly ?string $orderReference,
        private readonly ?string $sourceEventId,
        private readonly string $idempotencyKey,
        private readonly AffectedValue $affected,
        private readonly Money $nominal,
        private Money $effective,
        private readonly int $policyVersion,
        private readonly ApprovalLevel $approvalLevel,
        private CaseStatus $status,
        private readonly string $proposedBy,
        private ?string $approvedBy,
        private ?DateTimeImmutable $decidedAt,
        private ?string $reason,
        private readonly array $dimensions,
    ) {}

    /**
     * Abre un caso a partir del resultado del cálculo. Si no es compensable (E < mínimo), nace en estado
     * NotCompensable (auditable, sin vale); si lo es, nace Proposed con el nivel de aprobación requerido.
     *
     * @param array<string, mixed> $dimensions
     */
    public static function open(
        CompensationCaseId $id,
        TriggerType $trigger,
        CompensationScope $scope,
        ?string $shipmentId,
        ?string $orderReference,
        ?string $sourceEventId,
        string $idempotencyKey,
        AffectedValue $affected,
        CalculationResult $result,
        ApprovalLevel $approvalLevel,
        int $policyVersion,
        string $proposedBy,
        array $dimensions = [],
    ): self {
        $proposedBy = trim($proposedBy);
        if ($proposedBy === '') {
            throw CompensationException::because('El proponente del caso no puede estar vacío.');
        }

        $status = $result->compensable ? CaseStatus::Proposed : CaseStatus::NotCompensable;

        $case = new self(
            id: $id,
            trigger: $trigger,
            scope: $scope,
            shipmentId: $shipmentId,
            orderReference: $orderReference,
            sourceEventId: $sourceEventId,
            idempotencyKey: $idempotencyKey,
            affected: $affected,
            nominal: $result->nominal,
            effective: $result->effective,
            policyVersion: $policyVersion,
            approvalLevel: $approvalLevel,
            status: $status,
            proposedBy: $proposedBy,
            approvedBy: null,
            decidedAt: null,
            reason: $result->notCompensableReason,
            dimensions: $dimensions,
        );

        $case->recordEvent(new CaseOpened(
            $id->toString(),
            $trigger->value,
            $scope->value,
            $orderReference,
            $shipmentId,
            $result->effective->cents,
            $result->effective->currency,
            $approvalLevel->value,
            $status->value,
            is_string($dimensions['ownership'] ?? null) ? $dimensions['ownership'] : null,
        ));

        return $case;
    }

    /**
     * Reconstituye el caso desde persistencia, sin grabar eventos.
     *
     * @param array<string, mixed> $dimensions
     */
    public static function reconstitute(
        CompensationCaseId $id,
        TriggerType $trigger,
        CompensationScope $scope,
        ?string $shipmentId,
        ?string $orderReference,
        ?string $sourceEventId,
        string $idempotencyKey,
        AffectedValue $affected,
        Money $nominal,
        Money $effective,
        int $policyVersion,
        ApprovalLevel $approvalLevel,
        CaseStatus $status,
        string $proposedBy,
        ?string $approvedBy,
        ?DateTimeImmutable $decidedAt,
        ?string $reason,
        array $dimensions,
    ): self {
        return new self(
            $id,
            $trigger,
            $scope,
            $shipmentId,
            $orderReference,
            $sourceEventId,
            $idempotencyKey,
            $affected,
            $nominal,
            $effective,
            $policyVersion,
            $approvalLevel,
            $status,
            $proposedBy,
            $approvedBy,
            $decidedAt,
            $reason,
            $dimensions,
        );
    }

    /**
     * Aprueba el caso. Cuatro ojos: quien propuso no puede aprobar (salvo el actor del sistema en AUTO).
     * El override solo puede bajar el monto, nunca subirlo (regla 2.4.4).
     */
    public function approve(string $approver, DateTimeImmutable $at, ?Money $override = null): void
    {
        $approver = trim($approver);
        if ($this->status !== CaseStatus::Proposed) {
            throw CompensationException::because('Solo se puede aprobar un caso propuesto.');
        }
        if ($approver === '') {
            throw CompensationException::because('El aprobador no puede estar vacío.');
        }
        if ($approver !== self::SYSTEM_ACTOR && $approver === $this->proposedBy) {
            throw CompensationException::because('Cuatro ojos: quien propone no puede aprobar su propio caso.');
        }

        if ($override !== null) {
            if ($override->cents > $this->effective->cents) {
                throw CompensationException::because('El override solo puede bajar el monto, nunca subirlo.');
            }
            $this->effective = $override;
        }

        $this->status = CaseStatus::Approved;
        $this->approvedBy = $approver;
        $this->decidedAt = $at;

        $this->recordEvent(new CaseApproved(
            $this->id->toString(),
            $approver,
            $this->effective->cents,
            $this->effective->currency,
            $this->orderReference,
        ));
    }

    /**
     * Rechaza el caso. Cuatro ojos como en la aprobación.
     */
    public function reject(string $approver, string $reason, DateTimeImmutable $at): void
    {
        $approver = trim($approver);
        $reason = trim($reason);
        if ($this->status !== CaseStatus::Proposed) {
            throw CompensationException::because('Solo se puede rechazar un caso propuesto.');
        }
        if ($reason === '') {
            throw CompensationException::because('El motivo del rechazo no puede estar vacío.');
        }
        if ($approver !== self::SYSTEM_ACTOR && $approver === $this->proposedBy) {
            throw CompensationException::because('Cuatro ojos: quien propone no puede rechazar su propio caso.');
        }

        $this->status = CaseStatus::Rejected;
        $this->approvedBy = $approver;
        $this->decidedAt = $at;
        $this->reason = $reason;

        $this->recordEvent(new CaseRejected($this->id->toString(), $approver, $reason));
    }

    /**
     * Marca el caso como emitido (ya se generó su vale). Solo desde Approved. No graba evento: el hecho
     * relevante es `voucher_issued`, que lo emite el agregado Voucher.
     */
    public function markIssued(): void
    {
        if ($this->status !== CaseStatus::Approved) {
            throw CompensationException::because('Solo se puede emitir el vale de un caso aprobado.');
        }
        $this->status = CaseStatus::Issued;
    }

    public function id(): CompensationCaseId
    {
        return $this->id;
    }

    public function trigger(): TriggerType
    {
        return $this->trigger;
    }

    public function scope(): CompensationScope
    {
        return $this->scope;
    }

    public function shipmentId(): ?string
    {
        return $this->shipmentId;
    }

    public function orderReference(): ?string
    {
        return $this->orderReference;
    }

    public function sourceEventId(): ?string
    {
        return $this->sourceEventId;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function affected(): AffectedValue
    {
        return $this->affected;
    }

    public function nominal(): Money
    {
        return $this->nominal;
    }

    public function effective(): Money
    {
        return $this->effective;
    }

    public function policyVersion(): int
    {
        return $this->policyVersion;
    }

    public function approvalLevel(): ApprovalLevel
    {
        return $this->approvalLevel;
    }

    public function status(): CaseStatus
    {
        return $this->status;
    }

    public function proposedBy(): string
    {
        return $this->proposedBy;
    }

    public function approvedBy(): ?string
    {
        return $this->approvedBy;
    }

    public function decidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function dimensions(): array
    {
        return $this->dimensions;
    }
}
