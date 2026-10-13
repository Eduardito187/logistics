<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

use Logistics\Coverage\Domain\Exception\CoverageException;

/**
 * Días de la semana en los que la zona tiene servicio.
 *
 * Se representa con los números ISO-8601 (1 = lunes … 7 = domingo). Se guarda ordenado y sin
 * repetidos para que dos conjuntos equivalentes serialicen igual.
 */
final readonly class ServiceDays
{
    /**
     * @param array<int, int> $days días ISO-8601 (1..7), ordenados y únicos
     */
    private function __construct(public array $days) {}

    /**
     * @param int ...$days días ISO-8601 (1 = lunes … 7 = domingo)
     */
    public static function of(int ...$days): self
    {
        $unique = [];
        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                throw CoverageException::because("día de la semana fuera de rango (1..7): {$day}");
            }
            $unique[$day] = $day;
        }

        $ordered = array_values($unique);
        sort($ordered);

        return new self($ordered);
    }

    /**
     * Lunes a domingo.
     */
    public static function everyday(): self
    {
        return self::of(1, 2, 3, 4, 5, 6, 7);
    }

    /**
     * Reconstruye desde una lista de enteros (p. ej. leída de jsonb).
     *
     * @param array<int, int> $days
     */
    public static function fromArray(array $days): self
    {
        return self::of(...array_values($days));
    }

    public function includes(int $isoWeekday): bool
    {
        return in_array($isoWeekday, $this->days, true);
    }

    /**
     * @return array<int, int>
     */
    public function toArray(): array
    {
        return $this->days;
    }
}
