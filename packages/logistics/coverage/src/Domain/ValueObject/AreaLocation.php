<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

/**
 * Ubicación administrativa resuelta de un punto: el departamento al que pertenece y, si se pudieron
 * determinar, su provincia, municipio y ciudad.
 *
 * Es el resultado de subir por la jerarquía desde la zona que contiene el punto
 * (zona → ciudad → municipio → provincia → departamento). Forma primitiva, pura: no conoce Laravel ni
 * persistencia.
 *
 * El departamento es obligatorio: si no se pudo resolver, el localizador devuelve null en vez de un
 * AreaLocation con departamento desconocido. Los niveles intermedios (provincia, municipio, ciudad)
 * son opcionales: una zona puede colgar directo del departamento o de la ciudad y saltarse niveles.
 *
 * Los parámetros de provincia y municipio van DESPUÉS de los cuatro originales (departamento y ciudad)
 * y con valor por defecto, para no romper a quien ya construye un AreaLocation con cuatro argumentos
 * posicionales (p. ej. el contexto de planificación).
 */
final readonly class AreaLocation
{
    public function __construct(
        public string $departmentId,
        public ?string $departmentName,
        public ?string $cityId,
        public ?string $cityName,
        public ?string $provinceId = null,
        public ?string $provinceName = null,
        public ?string $municipalityId = null,
        public ?string $municipalityName = null,
    ) {}

    /**
     * Dos puntos están en el mismo departamento si comparten el id del departamento.
     */
    public function sameDepartmentAs(self $other): bool
    {
        return $this->departmentId === $other->departmentId;
    }

    /**
     * Dos puntos están en la misma provincia si comparten el id de provincia (y ambos lo tienen resuelto).
     */
    public function sameProvinceAs(self $other): bool
    {
        return $this->provinceId !== null && $this->provinceId === $other->provinceId;
    }

    /**
     * Dos puntos están en el mismo municipio si comparten el id de municipio (y ambos lo tienen resuelto).
     */
    public function sameMunicipalityAs(self $other): bool
    {
        return $this->municipalityId !== null && $this->municipalityId === $other->municipalityId;
    }

    /**
     * Dos puntos están en la misma ciudad si comparten el id de ciudad (y ambos lo tienen resuelto).
     */
    public function sameCityAs(self $other): bool
    {
        return $this->cityId !== null && $this->cityId === $other->cityId;
    }
}
