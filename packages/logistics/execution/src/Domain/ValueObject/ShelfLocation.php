<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Ubicación en estante de un producto recibido en el nodo (p. ej. "A-03-2"): dónde lo dejó el operador
 * para encontrarlo al entregarlo o despacharlo. Es la ubicación VIGENTE: una reubicación posterior la
 * reemplaza; quién y cuándo la fijó queda en la propia ubicación.
 */
final readonly class ShelfLocation
{
    public const int MAX_LENGTH = 64;

    private function __construct(
        public string $unitId,
        public string $location,
        public DateTimeImmutable $locatedAt,
        public DeviceContext $by,
    ) {}

    public static function of(string $unitId, string $location, DateTimeImmutable $locatedAt, DeviceContext $by): self
    {
        $unit = trim($unitId);
        if ($unit === '') {
            throw ExecutionException::because('la ubicación en estante requiere el id del producto');
        }

        $value = trim($location);
        if ($value === '') {
            throw ExecutionException::because('la ubicación en estante no puede estar vacía');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw ExecutionException::because('la ubicación en estante admite hasta '.self::MAX_LENGTH.' caracteres');
        }

        return new self($unit, $value, $locatedAt, $by);
    }
}
