<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Política de un disparador concreto: método de cálculo, valor, topes, auto-aprobación y vigencia.
 *
 * El método `percentage` usa `value` como bps sobre la base; `fixed` usa `value` como centavos.
 */
final readonly class TriggerPolicy
{
    public const string METHOD_PERCENTAGE = 'percentage';

    public const string METHOD_FIXED = 'fixed';

    private function __construct(
        public bool $enabled,
        public string $method,
        public int $value,
        public int $maxPctBps,
        public int $absCapCents,
        public bool $autoApprove,
        public int $validityDays,
    ) {}

    public static function of(
        bool $enabled,
        string $method,
        int $value,
        int $maxPctBps,
        int $absCapCents,
        bool $autoApprove,
        int $validityDays,
    ): self {
        $method = strtolower(trim($method));
        if (!in_array($method, [self::METHOD_PERCENTAGE, self::METHOD_FIXED], true)) {
            throw CompensationException::because("Método de cálculo inválido: {$method}.");
        }
        if ($value < 0) {
            throw CompensationException::because('El valor de la política no puede ser negativo.');
        }
        if ($maxPctBps < 0 || $maxPctBps > Percentage::MAX_BPS) {
            throw CompensationException::because('max_pct debe estar entre 0 y '.Percentage::MAX_BPS.' bps.');
        }
        if ($absCapCents < 0) {
            throw CompensationException::because('El tope absoluto no puede ser negativo.');
        }
        if ($validityDays < 1) {
            throw CompensationException::because('La vigencia debe ser al menos 1 día.');
        }

        return new self($enabled, $method, $value, $maxPctBps, $absCapCents, $autoApprove, $validityDays);
    }

    public function isPercentage(): bool
    {
        return $this->method === self::METHOD_PERCENTAGE;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            (bool) ($data['enabled'] ?? true),
            is_string($data['method'] ?? null) ? $data['method'] : self::METHOD_PERCENTAGE,
            (int) ($data['value'] ?? 0),
            (int) ($data['max_pct'] ?? Percentage::MAX_BPS),
            (int) ($data['abs_cap'] ?? 0),
            (bool) ($data['auto_approve'] ?? false),
            (int) ($data['validity_days'] ?? 90),
        );
    }

    /**
     * @return array{enabled: bool, method: string, value: int, max_pct: int, abs_cap: int, auto_approve: bool, validity_days: int}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'method' => $this->method,
            'value' => $this->value,
            'max_pct' => $this->maxPctBps,
            'abs_cap' => $this->absCapCents,
            'auto_approve' => $this->autoApprove,
            'validity_days' => $this->validityDays,
        ];
    }
}
