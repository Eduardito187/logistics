<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\OpenCompensationCase;

use Logistics\Compensation\Application\Contract\AffectedValueReader;
use Logistics\Compensation\Application\Contract\CustomerContactReader;
use Logistics\Compensation\Application\Service\VoucherIssuer;
use Logistics\Compensation\Domain\Contract\CaseAuditLog;
use Logistics\Compensation\Domain\Contract\CompensationCaseRepository;
use Logistics\Compensation\Domain\Contract\CompensationPolicyProvider;
use Logistics\Compensation\Domain\Model\CompensationCase;
use Logistics\Compensation\Domain\Service\VoucherCalculator;
use Logistics\Compensation\Domain\ValueObject\AffectedLine;
use Logistics\Compensation\Domain\ValueObject\AffectedValue;
use Logistics\Compensation\Domain\ValueObject\ApprovalLevel;
use Logistics\Compensation\Domain\ValueObject\CaseStatus;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Abre el caso: resuelve la base, calcula el monto efectivo con el VoucherCalculator, decide el nivel
 * de aprobación y persiste. Si el nivel es AUTO (monto bajo + disparador auto-aprobable con evidencia), ENCADENA
 * la aprobación por el sistema y emite el vale en la misma transacción. Idempotente por clave.
 *
 * @implements CommandHandler<OpenCompensationCase>
 */
final readonly class OpenCompensationCaseHandler implements CommandHandler
{
    public function __construct(
        private CompensationCaseRepository $cases,
        private CompensationPolicyProvider $policyProvider,
        private VoucherCalculator $calculator,
        private AffectedValueReader $affectedValueReader,
        private CustomerContactReader $customerContactReader,
        private VoucherIssuer $voucherIssuer,
        private RecordDomainEvents $recordEvents,
        private CaseAuditLog $auditLog,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof OpenCompensationCase);

        $trigger = TriggerType::fromString($command->trigger);
        $scope = CompensationScope::fromString($command->scope);
        $idempotencyKey = $command->idempotencyKey ?? $this->deriveKey($trigger, $command);

        // Idempotencia: reentrega del outbox o doble clic en el admin ⇒ no se abre dos veces.
        if ($this->cases->findByIdempotencyKey($idempotencyKey) !== null) {
            return;
        }

        $affected = $this->resolveAffectedValue($command, $scope);
        if ($affected === null) {
            // Sin base calculable (líneas sin precio / moneda ≠ BOB) y sin base manual: no se abre caso
            // automático (regla 6). El operador deberá abrirlo manual con base declarada.
            return;
        }

        $policy = $this->policyProvider->current();
        $currency = $affected->base->currency;
        $alreadyIssued = $command->orderReference !== null
            ? $this->cases->sumCommittedForOrder($command->orderReference, $currency)
            : Money::zero($currency);

        $result = $this->calculator->calculate($trigger, $affected, $policy, $alreadyIssued);

        $triggerPolicy = $policy->forTrigger($trigger);
        $level = ApprovalLevel::decide(
            $result->effective,
            $policy->autoApproveMax(),
            $policy->level1Max(),
            $triggerPolicy->autoApprove,
        );

        $case = CompensationCase::open(
            id: $this->cases->nextIdentity(),
            trigger: $trigger,
            scope: $scope,
            shipmentId: $command->shipmentId,
            orderReference: $command->orderReference,
            sourceEventId: $command->sourceEventId,
            idempotencyKey: $idempotencyKey,
            affected: $affected,
            result: $result,
            approvalLevel: $level,
            policyVersion: $this->policyProvider->version(),
            proposedBy: $command->proposedBy,
            dimensions: $this->dimensions($command),
        );

        // AUTO: el sistema aprueba y emite el vale en el acto (daño validado, tardía dentro del tope, …).
        $autoApproved = $result->compensable
            && $level === ApprovalLevel::Auto
            && $triggerPolicy->autoApprove;
        if ($autoApproved) {
            $case->approve(CompensationCase::SYSTEM_ACTOR, $this->clock->now());
            $case->markIssued();
        }

        $this->cases->save($case);
        $this->recordEvents->record($case->releaseEvents(), 'compensation.case');
        $this->auditLog->record(
            $case->id()->toString(),
            null,
            'opened',
            $command->proposedBy,
            [
                'trigger' => $trigger->value,
                'status' => $case->status()->value,
                'effective_cents' => $case->effective()->cents,
                'calculation_trace' => $result->trace->toArray(),
            ],
        );

        if ($autoApproved && $case->status() === CaseStatus::Issued) {
            $email = $command->shipmentId !== null
                ? $this->customerContactReader->emailForShipment($command->shipmentId)
                : null;
            $this->voucherIssuer->issueFor($case, $email);
        }
    }

    private function resolveAffectedValue(OpenCompensationCase $command, CompensationScope $scope): ?AffectedValue
    {
        // Base declarada manualmente (regla 6): el operador la justifica, siempre con aprobación.
        if ($command->manualBaseCents !== null) {
            return AffectedValue::manual(
                Money::of($command->manualBaseCents),
                null,
                $this->manualLines($command),
            );
        }

        if ($command->shipmentId === null) {
            return null;
        }

        return $this->affectedValueReader->affectedForShipment($command->shipmentId, $scope, $command->affectedSkus);
    }

    /**
     * @return list<AffectedLine>
     */
    private function manualLines(OpenCompensationCase $command): array
    {
        $lines = [];
        foreach ($command->manualLines as $raw) {
            $name = $raw['product_name'] ?? null;
            $sku = $raw['sku'] ?? null;
            if (is_string($name) && is_string($sku)) {
                $lines[] = AffectedLine::of(
                    $name,
                    $sku,
                    (int) ($raw['affected_quantity'] ?? 1),
                    Money::of((int) ($raw['unit_price_cents'] ?? 0)),
                );
            }
        }

        return $lines;
    }

    private function deriveKey(TriggerType $trigger, OpenCompensationCase $command): string
    {
        if ($command->sourceEventId !== null) {
            return $trigger->value.':event:'.$command->sourceEventId;
        }

        return $trigger->value.':'.($command->shipmentId ?? 'manual:'.bin2hex(random_bytes(8)));
    }

    /**
     * @return array<string, mixed>
     */
    private function dimensions(OpenCompensationCase $command): array
    {
        return [
            'ownership' => $command->ownership,
            'driver_id' => $command->driverId,
            'vehicle_id' => $command->vehicleId,
            'origin_node_id' => $command->originNodeId,
        ];
    }
}
