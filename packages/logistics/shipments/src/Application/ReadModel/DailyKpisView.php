<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * KPIs operativos de UN día (pantalla de indicadores de chofer y supervisor; GET /app/kpis/daily).
 *
 * Definiciones (todas sobre las PARADAS de las rutas del día, `execution_driver_routes.route_date`):
 *  - deliveries_total: paradas planificadas del día.
 *  - delivered: parada cerrada como completada (o, si aún no se cerró, entrega con POD).
 *  - failed: parada cerrada como fallida (o, si aún no se cerró, entrega marcada fallida).
 *  - rescheduled: parada reprogramada a otro día.
 *  - pending: el resto (aún sin resolver).
 *  - on_time_rate (0..1): de las entregadas con ventana prometida, las cerradas antes del fin de la
 *    ventana (hora de La Paz). Null si no hay ninguna medible.
 *  - first_attempt_success_rate (0..1): de las resueltas (entregadas + fallidas), las entregadas cuyo
 *    envío no tuvo una parada en un día anterior (primer intento). Null si no hay resueltas.
 *  - avg_stop_minutes: promedio llegada→cierre por parada resuelta. Null mientras la parada no registre
 *    su hora de llegada (`arrived_at`) o no haya ninguna medible.
 *  - km: suma de (odómetro de regreso − odómetro de salida) de las salidas del día con ambos odómetros.
 *    Null si no hay lecturas o si el alcance es un nodo (no atribuible).
 *  - failed_by_reason: top motivos de las fallidas del día.
 */
final readonly class DailyKpisView
{
    /**
     * @param list<array{reason: string, count: int}> $failedByReason
     */
    public function __construct(
        public string $date,
        public string $scope,
        public int $deliveriesTotal,
        public int $delivered,
        public int $failed,
        public int $rescheduled,
        public int $pending,
        public ?float $onTimeRate,
        public ?float $firstAttemptSuccessRate,
        public ?float $avgStopMinutes,
        public ?int $km,
        public array $failedByReason,
        public string $generatedAt,
    ) {}

    /**
     * KPIs en cero para un alcance sin datos posibles (p. ej. una cuenta de chofer sin chofer vinculado).
     */
    public static function empty(string $date, string $scope, string $generatedAt): self
    {
        return new self($date, $scope, 0, 0, 0, 0, 0, null, null, null, null, [], $generatedAt);
    }

    /**
     * Reconstruye la vista desde su forma primitiva (la que se cachea).
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reasons = [];
        foreach (is_array($data['failed_by_reason'] ?? null) ? $data['failed_by_reason'] : [] as $item) {
            if (is_array($item) && is_scalar($item['reason'] ?? null)) {
                $reasons[] = ['reason' => (string) $item['reason'], 'count' => self::int($item['count'] ?? 0)];
            }
        }

        return new self(
            self::str($data['date'] ?? ''),
            self::str($data['scope'] ?? ''),
            self::int($data['deliveries_total'] ?? 0),
            self::int($data['delivered'] ?? 0),
            self::int($data['failed'] ?? 0),
            self::int($data['rescheduled'] ?? 0),
            self::int($data['pending'] ?? 0),
            self::floatOrNull($data['on_time_rate'] ?? null),
            self::floatOrNull($data['first_attempt_success_rate'] ?? null),
            self::floatOrNull($data['avg_stop_minutes'] ?? null),
            is_numeric($data['km'] ?? null) ? (int) $data['km'] : null,
            $reasons,
            self::str($data['generated_at'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'scope' => $this->scope,
            'deliveries_total' => $this->deliveriesTotal,
            'delivered' => $this->delivered,
            'failed' => $this->failed,
            'rescheduled' => $this->rescheduled,
            'pending' => $this->pending,
            'on_time_rate' => $this->onTimeRate,
            'first_attempt_success_rate' => $this->firstAttemptSuccessRate,
            'avg_stop_minutes' => $this->avgStopMinutes,
            'km' => $this->km,
            'failed_by_reason' => $this->failedByReason,
            'generated_at' => $this->generatedAt,
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
