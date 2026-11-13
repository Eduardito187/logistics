<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\ReadModel;

use Logistics\Compensation\Domain\Model\CompensationCase;

/**
 * Proyección de lectura de un caso de compensación para el admin y la API. Primitivos: no expone el agregado.
 * Montos en centavos + formato legible. Las líneas afectadas llevan nombre + SKU (nunca "bulto").
 */
final readonly class CompensationCaseView
{
    /**
     * @param list<array<string, mixed>>        $affectedLines
     * @param list<array{label: string, cents: int}> $calculationTrace
     */
    private function __construct(
        public string $id,
        public string $trigger,
        public string $scope,
        public ?string $orderReference,
        public ?string $shipmentId,
        public string $status,
        public string $approvalLevel,
        public int $nominalCents,
        public int $effectiveCents,
        public string $currency,
        public string $effectiveFormatted,
        public string $proposedBy,
        public ?string $approvedBy,
        public ?string $reason,
        public array $affectedLines,
        public array $calculationTrace,
    ) {}

    public static function fromDomain(CompensationCase $case): self
    {
        return new self(
            id: $case->id()->toString(),
            trigger: $case->trigger()->value,
            scope: $case->scope()->value,
            orderReference: $case->orderReference(),
            shipmentId: $case->shipmentId(),
            status: $case->status()->value,
            approvalLevel: $case->approvalLevel()->value,
            nominalCents: $case->nominal()->cents,
            effectiveCents: $case->effective()->cents,
            currency: $case->effective()->currency,
            effectiveFormatted: $case->effective()->formatted(),
            proposedBy: $case->proposedBy(),
            approvedBy: $case->approvedBy(),
            reason: $case->reason(),
            affectedLines: $case->affected()->linesToArray(),
            calculationTrace: [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'trigger' => $this->trigger,
            'scope' => $this->scope,
            'order_reference' => $this->orderReference,
            'shipment_id' => $this->shipmentId,
            'status' => $this->status,
            'approval_level' => $this->approvalLevel,
            'nominal_cents' => $this->nominalCents,
            'effective_cents' => $this->effectiveCents,
            'currency' => $this->currency,
            'effective_formatted' => $this->effectiveFormatted,
            'proposed_by' => $this->proposedBy,
            'approved_by' => $this->approvedBy,
            'reason' => $this->reason,
            'affected_lines' => $this->affectedLines,
            'calculation_trace' => $this->calculationTrace,
        ];
    }
}
