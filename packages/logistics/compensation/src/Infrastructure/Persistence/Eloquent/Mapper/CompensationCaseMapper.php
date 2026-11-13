<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Compensation\Domain\Model\CompensationCase;
use Logistics\Compensation\Domain\ValueObject\AffectedLine;
use Logistics\Compensation\Domain\ValueObject\AffectedValue;
use Logistics\Compensation\Domain\ValueObject\ApprovalLevel;
use Logistics\Compensation\Domain\ValueObject\CaseStatus;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model\CompensationCaseRecord;

/**
 * Traduce entre el agregado CompensationCase y su fila Eloquent. El dominio no conoce Eloquent (Clean Arch).
 */
final readonly class CompensationCaseMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(CompensationCase $case): array
    {
        $dimensions = $case->dimensions();

        return [
            'id' => $case->id()->toString(),
            'trigger' => $case->trigger()->value,
            'scope' => $case->scope()->value,
            'shipment_id' => $case->shipmentId(),
            'order_reference' => $case->orderReference(),
            'source_event_id' => $case->sourceEventId(),
            'idempotency_key' => $case->idempotencyKey(),
            'base_cents' => $case->affected()->base->cents,
            'order_base_cents' => $case->affected()->orderBase->cents,
            'nominal_cents' => $case->nominal()->cents,
            'effective_cents' => $case->effective()->cents,
            'currency' => $case->effective()->currency,
            'affected_lines' => $case->affected()->linesToArray(),
            'policy_version' => $case->policyVersion(),
            'approval_level' => $case->approvalLevel()->value,
            'status' => $case->status()->value,
            'proposed_by' => $case->proposedBy(),
            'approved_by' => $case->approvedBy(),
            'decided_at' => $case->decidedAt(),
            'reason' => $case->reason(),
            'ownership' => is_string($dimensions['ownership'] ?? null) ? $dimensions['ownership'] : null,
            'driver_id' => is_string($dimensions['driver_id'] ?? null) ? $dimensions['driver_id'] : null,
            'vehicle_id' => is_string($dimensions['vehicle_id'] ?? null) ? $dimensions['vehicle_id'] : null,
            'origin_node_id' => is_string($dimensions['origin_node_id'] ?? null) ? $dimensions['origin_node_id'] : null,
        ];
    }

    public function toDomain(CompensationCaseRecord $record): CompensationCase
    {
        $currency = $record->currency;
        $lines = [];
        foreach ($record->affected_lines ?? [] as $raw) {
            if (is_array($raw)) {
                /** @var array{product_name: string, sku: string, affected_quantity: int, unit_price_cents: int, currency: string} $raw */
                $lines[] = AffectedLine::fromArray($raw);
            }
        }

        $affected = AffectedValue::manual(
            Money::of($record->base_cents, $currency),
            Money::of($record->order_base_cents, $currency),
            $lines,
        );

        return CompensationCase::reconstitute(
            id: CompensationCaseId::fromString($record->id),
            trigger: TriggerType::fromLoose($record->trigger),
            scope: CompensationScope::fromLoose($record->scope),
            shipmentId: $record->shipment_id,
            orderReference: $record->order_reference,
            sourceEventId: $record->source_event_id,
            idempotencyKey: $record->idempotency_key,
            affected: $affected,
            nominal: Money::of($record->nominal_cents, $currency),
            effective: Money::of($record->effective_cents, $currency),
            policyVersion: $record->policy_version,
            approvalLevel: ApprovalLevel::from($record->approval_level),
            status: CaseStatus::fromLoose($record->status),
            proposedBy: $record->proposed_by,
            approvedBy: $record->approved_by,
            decidedAt: $record->decided_at?->toDateTimeImmutable(),
            reason: $record->reason,
            dimensions: [
                'ownership' => $record->ownership,
                'driver_id' => $record->driver_id,
                'vehicle_id' => $record->vehicle_id,
                'origin_node_id' => $record->origin_node_id,
            ],
        );
    }
}
