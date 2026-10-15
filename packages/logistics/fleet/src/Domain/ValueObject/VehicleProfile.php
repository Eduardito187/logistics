<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Agrupa la ficha técnica editable de un vehículo (marca, modelo, año, VIN, nº de motor, color) y las
 * fotos de sus cuatro lados (frente, atrás, izquierda, derecha).
 *
 * Es el argumento de `Vehicle::updateProfile()`: todos los campos son opcionales (nullable) porque la
 * ficha se completa de a poco y los vehículos ya registrados siguen válidos sin estos datos.
 */
final readonly class VehicleProfile
{
    public function __construct(
        public ?string $make = null,
        public ?string $model = null,
        public ?int $modelYear = null,
        public ?string $vin = null,
        public ?string $engineNumber = null,
        public ?string $color = null,
        public ?string $photoFront = null,
        public ?string $photoBack = null,
        public ?string $photoLeft = null,
        public ?string $photoRight = null,
    ) {}

    /**
     * Forma primitiva para persistencia y eventos (nunca se serializa el objeto de dominio).
     *
     * @return array{
     *     make: string|null,
     *     model: string|null,
     *     model_year: int|null,
     *     vin: string|null,
     *     engine_number: string|null,
     *     color: string|null,
     *     photo_front: string|null,
     *     photo_back: string|null,
     *     photo_left: string|null,
     *     photo_right: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'make' => $this->make,
            'model' => $this->model,
            'model_year' => $this->modelYear,
            'vin' => $this->vin,
            'engine_number' => $this->engineNumber,
            'color' => $this->color,
            'photo_front' => $this->photoFront,
            'photo_back' => $this->photoBack,
            'photo_left' => $this->photoLeft,
            'photo_right' => $this->photoRight,
        ];
    }
}
