<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Proyección de lectura del total gastado por un vehículo en un rango (insumo del TCO). El importe se
 * expone como centavos + moneda; `from`/`to` son las fechas ISO 8601 del rango consultado (o null).
 */
final readonly class ExpenseTotalView
{
    private function __construct(
        public string $vehicleId,
        public int $amountCents,
        public string $currency,
        public ?string $from,
        public ?string $to,
    ) {}

    public static function of(
        string $vehicleId,
        int $amountCents,
        string $currency,
        ?string $from,
        ?string $to,
    ): self {
        return new self($vehicleId, $amountCents, $currency, $from, $to);
    }

    /**
     * @return array{vehicle_id: string, amount_cents: int, currency: string, from: string|null, to: string|null}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
