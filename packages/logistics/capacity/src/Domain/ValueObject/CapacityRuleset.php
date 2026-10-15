<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Capacity\Domain\Exception\CapacityException;

/**
 * Reglas de cupo de la red: QUÉ franjas se ofrecen, en qué días, con qué corte y con cuánto cupo por
 * unidad, además de a qué nodos/zonas materializar el calendario. Es un objeto de dominio PHP puro,
 * reconstruido desde la configuración versionada de `logistics/settings` (clave `capacity.dispatch_rules`)
 * y, mientras no haya versión publicada, desde la config del package (ver docs/architecture.md, principio 5).
 *
 * La regla no vive como código ni como estado de un agregado: se versiona en settings y acá solo se
 * interpreta. Su forma primitiva (toArray) es lo que se serializa a JSON y lo que se cachea si hiciera
 * falta (regla 7): nunca se cachea el objeto.
 *
 * Granularidad soportada:
 *  - Días de servicio (días hábiles de entrega, 1 = lunes … 7 = domingo).
 *  - Horarios por día de la semana: ventanas por defecto más overrides por día.
 *  - Corte de pedido (order cutoff) en 'HH:MM'.
 *  - Feriados: fechas concretas excluidas aunque caigan en día de servicio.
 *  - Cupo por unidad (entregas/kg/m³): total por defecto y afinamiento por franja.
 *  - Horizonte: cuántos días de servicio hacia adelante se publica.
 *  - Scopes: nodos y/o zonas para los que se materializa el calendario.
 */
final readonly class CapacityRuleset
{
    private const string CUTOFF_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /** Tope de días de servicio que admite el horizonte (evita horizontes absurdos y bucles largos). */
    public const int MAX_HORIZON_DAYS = 120;

    /**
     * @param list<int>                              $serviceDays      días de servicio (1..7), únicos y ordenados
     * @param list<SlotDate>                         $holidays         fechas excluidas
     * @param list<TimeSlot>                         $defaultWindows   ventanas por defecto de un día de servicio
     * @param array<int, list<TimeSlot>>             $windowsByWeekday ventanas que reemplazan las por defecto en un día concreto
     * @param array<string, Quantity>                $defaultTotals    unidad => total por defecto
     * @param array<string, array<string, Quantity>> $totalsBySlot     unidad => (franja => total) que gana sobre el total por defecto
     * @param list<BucketScope>                      $scopes           nodos/zonas a materializar
     */
    private function __construct(
        private array $serviceDays,
        private string $orderCutoff,
        private int $horizonDays,
        private array $holidays,
        private array $defaultWindows,
        private array $windowsByWeekday,
        private array $defaultTotals,
        private array $totalsBySlot,
        private array $scopes,
    ) {}

    /**
     * Construye el conjunto de reglas desde su forma primitiva (lo que settings sirve como `value`).
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $serviceDays = self::parseWeekdays($raw['service_days'] ?? []);

        $cutoff = is_string($raw['order_cutoff'] ?? null) ? trim($raw['order_cutoff']) : '';
        if (preg_match(self::CUTOFF_PATTERN, $cutoff) !== 1) {
            throw CapacityException::because("corte de pedido con formato inválido (HH:MM): {$cutoff}");
        }

        $horizon = is_numeric($raw['horizon_days'] ?? null) ? (int) $raw['horizon_days'] : 0;
        if ($horizon < 1 || $horizon > self::MAX_HORIZON_DAYS) {
            throw CapacityException::because('el horizonte debe estar entre 1 y '.self::MAX_HORIZON_DAYS." días, se recibió {$horizon}");
        }

        $holidays = self::parseHolidays($raw['holidays'] ?? []);
        $defaultWindows = self::parseWindows($raw['windows'] ?? []);
        $windowsByWeekday = self::parseWindowsByWeekday($raw['windows_by_weekday'] ?? []);
        $defaultTotals = self::parseTotals($raw['totals'] ?? []);
        $totalsBySlot = self::parseTotalsBySlot($raw['totals_by_slot'] ?? []);
        $scopes = self::parseScopes($raw['scopes'] ?? []);

        return new self(
            $serviceDays,
            $cutoff,
            $horizon,
            $holidays,
            $defaultWindows,
            $windowsByWeekday,
            $defaultTotals,
            $totalsBySlot,
            $scopes,
        );
    }

    /**
     * Forma primitiva lista para serializar a JSON o persistir como `value` de settings (regla 7).
     *
     * @return array{service_days: list<int>, order_cutoff: string, horizon_days: int, holidays: list<string>, windows: list<string>, windows_by_weekday: array<int, list<string>>, totals: array<string, float>, totals_by_slot: array<string, array<string, float>>, scopes: list<array{node_id: string|null, zone_id: string|null, municipality_id: string|null}>}
     */
    public function toArray(): array
    {
        $windowsByWeekday = [];
        foreach ($this->windowsByWeekday as $weekday => $windows) {
            $windowsByWeekday[$weekday] = array_map(static fn (TimeSlot $w): string => $w->toString(), $windows);
        }

        $totals = [];
        foreach ($this->defaultTotals as $unit => $quantity) {
            $totals[$unit] = $quantity->value();
        }

        $totalsBySlot = [];
        foreach ($this->totalsBySlot as $unit => $bySlot) {
            $totalsBySlot[$unit] = [];
            foreach ($bySlot as $slot => $quantity) {
                $totalsBySlot[$unit][$slot] = $quantity->value();
            }
        }

        return [
            'service_days' => $this->serviceDays,
            'order_cutoff' => $this->orderCutoff,
            'horizon_days' => $this->horizonDays,
            'holidays' => array_map(static fn (SlotDate $d): string => $d->toString(), $this->holidays),
            'windows' => array_map(static fn (TimeSlot $w): string => $w->toString(), $this->defaultWindows),
            'windows_by_weekday' => $windowsByWeekday,
            'totals' => $totals,
            'totals_by_slot' => $totalsBySlot,
            'scopes' => array_map(
                static fn (BucketScope $s): array => [
                    'node_id' => $s->nodeId,
                    'zone_id' => $s->zoneId,
                    'municipality_id' => $s->municipalityId,
                ],
                $this->scopes,
            ),
        ];
    }

    /**
     * ¿La fecha es un feriado configurado?
     */
    public function isHoliday(SlotDate $date): bool
    {
        foreach ($this->holidays as $holiday) {
            if ($holiday->equals($date)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿La fecha es un día de servicio? (día de la semana habilitado y no feriado).
     */
    public function isServiceDay(SlotDate $date): bool
    {
        $weekday = (int) $date->toDateTime()->format('N');

        return in_array($weekday, $this->serviceDays, true) && !$this->isHoliday($date);
    }

    /**
     * Ventanas ofrecidas para una fecha: el override del día de la semana si existe, si no, las por
     * defecto. No comprueba si es día de servicio (eso lo decide quien planifica).
     *
     * @return list<TimeSlot>
     */
    public function windowsFor(SlotDate $date): array
    {
        $weekday = (int) $date->toDateTime()->format('N');

        return $this->windowsByWeekday[$weekday] ?? $this->defaultWindows;
    }

    /**
     * Cupo total para una unidad y franja: el afinamiento por franja si existe, si no, el total por
     * defecto de la unidad; null si no hay regla para esa unidad (entonces no se materializa bucket).
     */
    public function totalFor(CapacityUnit $unit, TimeSlot $slot): ?Quantity
    {
        $bySlot = $this->totalsBySlot[$unit->value][$slot->toString()] ?? null;
        if ($bySlot !== null) {
            return $bySlot;
        }

        return $this->defaultTotals[$unit->value] ?? null;
    }

    /**
     * Unidades para las que hay alguna regla de cupo (total por defecto o afinamiento por franja),
     * en el orden del enum para que el plan sea determinista.
     *
     * @return list<CapacityUnit>
     */
    public function units(): array
    {
        $units = [];
        foreach (CapacityUnit::cases() as $unit) {
            if (isset($this->defaultTotals[$unit->value]) || isset($this->totalsBySlot[$unit->value])) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * @return list<int>
     */
    public function serviceDays(): array
    {
        return $this->serviceDays;
    }

    public function orderCutoff(): string
    {
        return $this->orderCutoff;
    }

    public function horizonDays(): int
    {
        return $this->horizonDays;
    }

    /**
     * @return list<BucketScope>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /**
     * @return list<int>
     */
    private static function parseWeekdays(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw CapacityException::because('service_days debe ser una lista de días (1..7)');
        }

        $days = [];
        foreach ($raw as $value) {
            if (!is_numeric($value)) {
                throw CapacityException::because('cada día de servicio debe ser un número 1..7');
            }
            $day = (int) $value;
            if ($day < 1 || $day > 7) {
                throw CapacityException::because("día de servicio fuera de rango (1..7): {$day}");
            }
            $days[$day] = $day;
        }

        $days = array_values($days);
        sort($days);

        return $days;
    }

    /**
     * @return list<SlotDate>
     */
    private static function parseHolidays(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw CapacityException::because('holidays debe ser una lista de fechas Y-m-d');
        }

        $holidays = [];
        $seen = [];
        foreach ($raw as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw CapacityException::because('cada feriado debe ser una fecha Y-m-d');
            }
            $date = SlotDate::fromString($value);
            if (!isset($seen[$date->toString()])) {
                $seen[$date->toString()] = true;
                $holidays[] = $date;
            }
        }

        return $holidays;
    }

    /**
     * @return list<TimeSlot>
     */
    private static function parseWindows(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw CapacityException::because('windows debe ser una lista de franjas HH:MM-HH:MM');
        }

        $windows = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                throw CapacityException::because('cada ventana debe ser una franja HH:MM-HH:MM');
            }
            $windows[] = TimeSlot::fromString($value);
        }

        return $windows;
    }

    /**
     * @return array<int, list<TimeSlot>>
     */
    private static function parseWindowsByWeekday(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw CapacityException::because('windows_by_weekday debe mapear día (1..7) a lista de franjas');
        }

        $byWeekday = [];
        foreach ($raw as $weekday => $windows) {
            if (!is_numeric($weekday)) {
                throw CapacityException::because('la clave de windows_by_weekday debe ser un día 1..7');
            }
            $day = (int) $weekday;
            if ($day < 1 || $day > 7) {
                throw CapacityException::because("día de windows_by_weekday fuera de rango (1..7): {$day}");
            }
            $byWeekday[$day] = self::parseWindows($windows);
        }

        return $byWeekday;
    }

    /**
     * @return array<string, Quantity>
     */
    private static function parseTotals(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw CapacityException::because('totals debe mapear unidad a cantidad');
        }

        $totals = [];
        foreach ($raw as $unit => $value) {
            $case = is_string($unit) ? CapacityUnit::tryFrom($unit) : null;
            if ($case === null) {
                throw CapacityException::because('unidad de capacidad desconocida en totals: '.(is_string($unit) ? $unit : gettype($unit)));
            }
            if (!is_numeric($value)) {
                throw CapacityException::because("el total de {$case->value} debe ser numérico");
            }
            $totals[$case->value] = Quantity::of((float) $value);
        }

        return $totals;
    }

    /**
     * @return array<string, array<string, Quantity>>
     */
    private static function parseTotalsBySlot(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw CapacityException::because('totals_by_slot debe mapear unidad a (franja => cantidad)');
        }

        $bySlot = [];
        foreach ($raw as $unit => $slots) {
            $case = is_string($unit) ? CapacityUnit::tryFrom($unit) : null;
            if ($case === null) {
                throw CapacityException::because('unidad de capacidad desconocida en totals_by_slot: '.(is_string($unit) ? $unit : gettype($unit)));
            }
            if (!is_array($slots)) {
                throw CapacityException::because("totals_by_slot.{$case->value} debe mapear franja a cantidad");
            }
            $parsed = [];
            foreach ($slots as $slot => $value) {
                if (!is_string($slot)) {
                    throw CapacityException::because('la clave de totals_by_slot debe ser una franja HH:MM-HH:MM');
                }
                $normalizedSlot = TimeSlot::fromString($slot)->toString();
                if (!is_numeric($value)) {
                    throw CapacityException::because("el total de {$case->value} en {$normalizedSlot} debe ser numérico");
                }
                $parsed[$normalizedSlot] = Quantity::of((float) $value);
            }
            $bySlot[$case->value] = $parsed;
        }

        return $bySlot;
    }

    /**
     * @return list<BucketScope>
     */
    private static function parseScopes(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw CapacityException::because('scopes debe ser una lista de nodos/zonas');
        }

        $scopes = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw CapacityException::because('cada scope debe ser un objeto con municipality_id, zone_id o node_id');
            }
            $nodeId = isset($entry['node_id']) && is_string($entry['node_id']) ? $entry['node_id'] : null;
            $zoneId = isset($entry['zone_id']) && is_string($entry['zone_id']) ? $entry['zone_id'] : null;
            $municipalityId = isset($entry['municipality_id']) && is_string($entry['municipality_id']) ? $entry['municipality_id'] : null;
            $scopes[] = BucketScope::fromNullable($nodeId, $zoneId, $municipalityId);
        }

        return $scopes;
    }
}
