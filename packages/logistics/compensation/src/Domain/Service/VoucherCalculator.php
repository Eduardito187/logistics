<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Service;

use Logistics\Compensation\Domain\ValueObject\AffectedValue;
use Logistics\Compensation\Domain\ValueObject\CalculationResult;
use Logistics\Compensation\Domain\ValueObject\CalculationTrace;
use Logistics\Compensation\Domain\ValueObject\CompensationPolicy;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\Percentage;
use Logistics\Compensation\Domain\ValueObject\TriggerPolicy;
use Logistics\Compensation\Domain\ValueObject\TriggerType;

/**
 * Servicio de dominio PURO que aplica las reglas de coherencia del vale (§2.1.2) y devuelve el monto
 * efectivo más la traza de cada tope. Invariante central: un vale NUNCA supera el valor afectado `B`.
 *
 * Pasos:
 *  1. Nominal: N = fixed ? value : floor(B × value / 10000).
 *  2. Topes: E = min(N, floor(B × maxPct / 10000), absCap, B).
 *  3. Tope acumulado: E = min(E, floor(B_order × cumulativePct / 10000) − ya_emitido_orden).
 *  4. Redondeo SIEMPRE hacia abajo al múltiplo de roundingStep.
 *  5. Mínimo: si E < minAmount ⇒ NotCompensable (no se emite).
 */
final readonly class VoucherCalculator
{
    /**
     * @param Money $alreadyIssuedForOrder suma de vales ya emitidos/propuestos de la orden (para el acumulado)
     */
    public function calculate(
        TriggerType $trigger,
        AffectedValue $affected,
        CompensationPolicy $policy,
        Money $alreadyIssuedForOrder,
    ): CalculationResult {
        $triggerPolicy = $policy->forTrigger($trigger);
        $base = $affected->base;
        $trace = CalculationTrace::empty()->with('base', $base);

        if (!$triggerPolicy->enabled) {
            return CalculationResult::notCompensable($base, 'El disparador está deshabilitado por política.', $trace);
        }

        // 1. Nominal.
        $nominal = $this->nominal($triggerPolicy, $base);
        $trace = $trace->with('nominal', $nominal);

        // 2. Topes por valor afectado.
        $maxByPct = Percentage::ofBasisPoints($triggerPolicy->maxPctBps)->applyTo($base);
        $absCap = Money::of($triggerPolicy->absCapCents, $base->currency);
        $effective = $nominal->min($maxByPct)->min($absCap)->min($base);
        $trace = $trace
            ->with('cap_max_pct', $maxByPct)
            ->with('cap_abs', $absCap)
            ->with('cap_base', $base)
            ->with('after_caps', $effective);

        // 3. Tope acumulado por orden.
        $cumulativeBudget = Percentage::ofBasisPoints($policy->cumulativePctBps)
            ->applyTo($affected->orderBase)
            ->subtractFloored($alreadyIssuedForOrder);
        $effective = $effective->min($cumulativeBudget);
        $trace = $trace->with('cumulative_budget', $cumulativeBudget)->with('after_cumulative', $effective);

        // 4. Redondeo hacia abajo.
        $effective = $effective->floorTo($policy->roundingStepCents);
        $trace = $trace->with('rounded', $effective);

        // 5. Mínimo.
        if ($effective->isLessThan($policy->minAmount())) {
            return CalculationResult::notCompensable(
                $nominal,
                'El monto efectivo ('.$effective->formatted().') es menor al mínimo ('.$policy->minAmount()->formatted().').',
                $trace,
            );
        }

        return CalculationResult::compensable($nominal, $effective, $trace);
    }

    private function nominal(TriggerPolicy $triggerPolicy, Money $base): Money
    {
        if ($triggerPolicy->isPercentage()) {
            return Percentage::ofBasisPoints($triggerPolicy->value)->applyTo($base);
        }

        return Money::of($triggerPolicy->value, $base->currency);
    }
}
