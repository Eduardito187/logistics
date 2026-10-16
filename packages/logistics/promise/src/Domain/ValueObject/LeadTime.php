<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use Logistics\Promise\Domain\Exception\PromiseException;

/**
 * Tiempo de espera ADICIONAL que se suma al tiempo base de la zona antes de poder prometer una entrega.
 *
 * Nace para el marketplace (ver docs/marketplace-dropship-roadmap.md §8): cuando el ítem es de un seller,
 * la fecha prometida debe contemplar el tiempo extra de que el seller deje el producto en el almacén
 * central (FBD sin stock) o lo despache él mismo (dropship). El retail (OWNED) usa `none()` → no cambia.
 *
 * Se modela en minutos (igual que `ZoneServiceProfile::leadMinutes`) para sumarse sin conversiones; el
 * named constructor `ofDays()` es la forma de expresarlo cómodamente en días operativos. Inmutable.
 */
final readonly class LeadTime
{
    private function __construct(public int $minutes) {}

    /**
     * Sin tiempo adicional (retail / FBD con stock disponible). Elemento neutro de la suma.
     */
    public static function none(): self
    {
        return new self(0);
    }

    public static function ofMinutes(int $minutes): self
    {
        if ($minutes < 0) {
            throw PromiseException::because("el lead time adicional no puede ser negativo: {$minutes}");
        }

        return new self($minutes);
    }

    /**
     * Días operativos completos (1 día = 1440 minutos).
     */
    public static function ofDays(int $days): self
    {
        if ($days < 0) {
            throw PromiseException::because("el lead time adicional no puede ser negativo: {$days} días");
        }

        return new self($days * 1440);
    }

    public function isZero(): bool
    {
        return $this->minutes === 0;
    }
}
