<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

/**
 * Medidas físicas de un producto, en las unidades en que las guarda el catálogo (commerce): peso en kg,
 * lados en cm, volumen en m³. Todo es opcional: un producto puede no tener medidas cargadas, y entonces
 * la elección de tamaño degrada al perfil Standard.
 *
 * Es un VO de dominio puro: Etiquetas NO conoce el package de catálogo. Un reader de infraestructura lee
 * las tablas del productor en solo-lectura y arma este VO (referencia cross-context por datos, tolerante).
 */
final readonly class ProductMeasures
{
    public function __construct(
        public ?float $weightKg,
        public ?float $lengthCm,
        public ?float $widthCm,
        public ?float $heightCm,
        public ?float $volumeM3,
    ) {}

    /**
     * Lado más largo conocido en centímetros, o null si no hay ninguna dimensión. Es la métrica que usa la
     * regla de tamaño: un producto «chico» por su mayor dimensión entra en una etiqueta chica.
     */
    public function longestSideCm(): ?float
    {
        $sides = array_values(array_filter(
            [$this->lengthCm, $this->widthCm, $this->heightCm],
            static fn (?float $side): bool => $side !== null && $side > 0.0,
        ));

        return $sides === [] ? null : max($sides);
    }

    /** True si no hay ninguna dimensión ni peso ni volumen cargado (degradar a Standard). */
    public function isEmpty(): bool
    {
        return $this->weightKg === null
            && $this->lengthCm === null
            && $this->widthCm === null
            && $this->heightCm === null
            && $this->volumeM3 === null;
    }
}
