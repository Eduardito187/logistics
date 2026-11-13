<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Política completa de compensación: topes/umbrales globales + una {@see TriggerPolicy} por disparador.
 *
 * Es PHP puro y su forma primitiva es cacheable (regla 7 de CLAUDE.md: se cachea el array, no el objeto).
 * La fuente real es la configuración versionada de `logistics/settings`; el provider de infraestructura
 * la arma con `fromArray()` y cae al `config/logistics-compensation.php` como fallback.
 */
final readonly class CompensationPolicy
{
    /**
     * @param array<string, TriggerPolicy> $triggers              por valor de TriggerType
     * @param list<string>                  $compensableFailures  motivos de fallida que compensan
     */
    private function __construct(
        public int $cumulativePctBps,
        public int $roundingStepCents,
        public int $minAmountCents,
        public int $autoApproveMaxCents,
        public int $level1MaxCents,
        public int $approvalSlaHours,
        public int $lateToleranceMinutes,
        public string $currency,
        public int $lowRatingThreshold,
        public array $triggers,
        public array $compensableFailures,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $level1 = (int) ($data['level1_max'] ?? 30000);
        $auto = (int) ($data['auto_approve_max'] ?? 5000);
        if ($level1 < $auto) {
            throw CompensationException::because('level1_max debe ser mayor o igual que auto_approve_max.');
        }

        $triggers = [];
        $rawTriggers = $data['triggers'] ?? [];
        if (is_array($rawTriggers)) {
            /** @var mixed $raw */
            foreach ($rawTriggers as $key => $raw) {
                if (is_string($key) && is_array($raw)) {
                    $triggers[strtoupper($key)] = TriggerPolicy::fromArray($raw);
                }
            }
        }

        $failures = [];
        $rawFailures = $data['compensable_failure_reasons'] ?? [];
        if (is_array($rawFailures)) {
            /** @var mixed $reason */
            foreach ($rawFailures as $reason) {
                if (is_string($reason)) {
                    $failures[] = strtoupper(trim($reason));
                }
            }
        }

        return new self(
            cumulativePctBps: (int) ($data['cumulative_pct'] ?? 4000),
            roundingStepCents: max(1, (int) ($data['rounding_step'] ?? 100)),
            minAmountCents: (int) ($data['min_amount'] ?? 1000),
            autoApproveMaxCents: $auto,
            level1MaxCents: $level1,
            approvalSlaHours: max(1, (int) ($data['approval_sla_hours'] ?? 24)),
            lateToleranceMinutes: max(0, (int) ($data['late_tolerance_minutes'] ?? 60)),
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : Money::DEFAULT_CURRENCY,
            lowRatingThreshold: (int) ($data['low_rating_threshold'] ?? 3),
            triggers: $triggers,
            compensableFailures: array_values(array_unique($failures)),
        );
    }

    public function forTrigger(TriggerType $trigger): TriggerPolicy
    {
        return $this->triggers[$trigger->value]
            ?? throw CompensationException::because("No hay política configurada para el disparador {$trigger->value}.");
    }

    public function isFailureCompensable(string $failureReason): bool
    {
        return in_array(strtoupper(trim($failureReason)), $this->compensableFailures, true);
    }

    public function autoApproveMax(): Money
    {
        return Money::of($this->autoApproveMaxCents, $this->currency);
    }

    public function level1Max(): Money
    {
        return Money::of($this->level1MaxCents, $this->currency);
    }

    public function minAmount(): Money
    {
        return Money::of($this->minAmountCents, $this->currency);
    }
}
