<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

/**
 * Ubicación administrativa resuelta de un punto: el departamento al que pertenece y, si se pudo
 * determinar, su municipio/ciudad.
 *
 * Es el resultado de subir por la jerarquía desde la zona que contiene el punto (zona → área padre
 * (ciudad) → … → departamento). Forma primitiva, pura: no conoce Laravel ni persistencia.
 *
 * El departamento es obligatorio: si no se pudo resolver, el localizador devuelve null en vez de un
 * AreaLocation con departamento desconocido. El municipio/ciudad es opcional (puede faltar ese nivel).
 */
final readonly class AreaLocation
{
    public function __construct(
        public string $departmentId,
        public ?string $departmentName,
        public ?string $cityId,
        public ?string $cityName,
    ) {}

    /**
     * Dos puntos están en el mismo departamento si comparten el id del departamento.
     */
    public function sameDepartmentAs(self $other): bool
    {
        return $this->departmentId === $other->departmentId;
    }

    /**
     * Dos puntos están en el mismo municipio si comparten el id de ciudad (y ambos lo tienen resuelto).
     */
    public function sameCityAs(self $other): bool
    {
        return $this->cityId !== null && $this->cityId === $other->cityId;
    }
}
