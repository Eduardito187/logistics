<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

/**
 * Proyección de lectura del historial operativo cuantificable de un chofer en un rango de fechas: qué
 * camiones manejó, con qué ayudantes, cuántas paradas atendió y cómo cerraron (entregadas, fallidas y
 * por qué motivo, reprogramadas). Primitivos: no expone objetos de dominio ni filas Eloquent.
 *
 * Totales del rango + desglose día por día (contrato §4). El lado de escritura no cambia: estos números
 * salen del join de `fleet_crews` del chofer con `execution_driver_routes` y sus paradas.
 */
final readonly class DriverHistoryView
{
    /**
     * @param list<string>       $vehicles       placas distintas manejadas en el rango
     * @param array<string, int> $failedByReason motivo de fallo (FailureReason) → cantidad
     * @param list<array{
     *     date: string,
     *     vehicle_plate: string,
     *     helper_names: list<string>,
     *     delivered: int,
     *     failed: int,
     *     rescheduled: int,
     *     reschedule_reasons: list<string>
     * }> $days
     */
    private function __construct(
        public string $driverId,
        public string $driverName,
        public string $from,
        public string $to,
        public int $daysWorked,
        public array $vehicles,
        public int $totalStops,
        public int $delivered,
        public int $failed,
        public array $failedByReason,
        public int $rescheduled,
        public array $days,
    ) {}

    /**
     * Construye la proyección a partir de los agregados ya calculados por el handler.
     *
     * @param list<string>       $vehicles
     * @param array<string, int> $failedByReason
     * @param list<array{
     *     date: string,
     *     vehicle_plate: string,
     *     helper_names: list<string>,
     *     delivered: int,
     *     failed: int,
     *     rescheduled: int,
     *     reschedule_reasons: list<string>
     * }> $days
     */
    public static function of(
        string $driverId,
        string $driverName,
        string $from,
        string $to,
        int $daysWorked,
        array $vehicles,
        int $totalStops,
        int $delivered,
        int $failed,
        array $failedByReason,
        int $rescheduled,
        array $days,
    ): self {
        return new self(
            $driverId,
            $driverName,
            $from,
            $to,
            $daysWorked,
            $vehicles,
            $totalStops,
            $delivered,
            $failed,
            $failedByReason,
            $rescheduled,
            $days,
        );
    }

    /**
     * Historial vacío: el chofer no tiene tripulaciones armadas en el rango.
     */
    public static function empty(string $driverId, string $driverName, string $from, string $to): self
    {
        return new self($driverId, $driverName, $from, $to, 0, [], 0, 0, 0, [], 0, []);
    }

    /**
     * @return array{
     *     driver_id: string,
     *     driver_name: string,
     *     from: string,
     *     to: string,
     *     totals: array{
     *         days_worked: int,
     *         vehicles: list<string>,
     *         total_stops: int,
     *         delivered: int,
     *         failed: int,
     *         failed_by_reason: array<string, int>,
     *         rescheduled: int
     *     },
     *     days: list<array{
     *         date: string,
     *         vehicle_plate: string,
     *         helper_names: list<string>,
     *         delivered: int,
     *         failed: int,
     *         rescheduled: int,
     *         reschedule_reasons: list<string>
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'driver_id' => $this->driverId,
            'driver_name' => $this->driverName,
            'from' => $this->from,
            'to' => $this->to,
            'totals' => [
                'days_worked' => $this->daysWorked,
                'vehicles' => $this->vehicles,
                'total_stops' => $this->totalStops,
                'delivered' => $this->delivered,
                'failed' => $this->failed,
                'failed_by_reason' => $this->failedByReason,
                'rescheduled' => $this->rescheduled,
            ],
            'days' => $this->days,
        ];
    }
}
