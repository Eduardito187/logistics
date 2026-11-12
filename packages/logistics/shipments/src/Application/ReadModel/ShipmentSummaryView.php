<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Resumen agregado de envíos para el tablero del supervisor (pedido #16 de la app): conteos por estado
 * del envío y de la entrega en ejecución, planes publicados, alertas abiertas y bultos totales.
 *
 * Cada cifra sale de agregados SQL (GROUP BY / COUNT); la app deja de bajar la colección histórica completa
 * para contar estados en el teléfono.
 */
final readonly class ShipmentSummaryView
{
    /**
     * @param array<string, int> $byStatus          estado del envío → cantidad (todos los estados, con 0)
     * @param array<string, int> $byExecutionStatus estado de la entrega en ejecución → cantidad
     */
    public function __construct(
        public ?string $dateFrom,
        public ?string $dateTo,
        public array $byStatus,
        public array $byExecutionStatus,
        public int $activePlans,
        public int $openAlerts,
        public int $unitsTotal,
        public string $generatedAt,
    ) {}

    /**
     * Reconstruye la vista desde su forma primitiva (la que se cachea).
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['date_from'] ?? null) ? $data['date_from'] : null,
            is_string($data['date_to'] ?? null) ? $data['date_to'] : null,
            self::counts($data['by_status'] ?? []),
            self::counts($data['by_execution_status'] ?? []),
            self::int($data['active_plans'] ?? 0),
            self::int($data['open_alerts'] ?? 0),
            self::int($data['units_total'] ?? 0),
            is_string($data['generated_at'] ?? null) ? $data['generated_at'] : '',
        );
    }

    /**
     * @return array{date_from: string|null, date_to: string|null, total: int, by_status: array<string, int>, by_execution_status: array<string, int>, active_plans: int, open_alerts: int, units_total: int, generated_at: string}
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'total' => array_sum($this->byStatus),
            'by_status' => $this->byStatus,
            'by_execution_status' => $this->byExecutionStatus,
            'active_plans' => $this->activePlans,
            'open_alerts' => $this->openAlerts,
            'units_total' => $this->unitsTotal,
            'generated_at' => $this->generatedAt,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function counts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $counts = [];
        foreach ($value as $key => $count) {
            $counts[(string) $key] = self::int($count);
        }

        return $counts;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
