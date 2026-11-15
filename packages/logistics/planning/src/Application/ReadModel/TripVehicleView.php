<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * El camión visto desde el armado de viajes: su capacidad nominal y su BASE (el nodo del que sale), con
 * nombre legible, ubicación y departamento. Solo primitivos; lo arma el adaptador leyendo Flota y Red.
 *
 * `department` es el código de departamento de la base (`la_paz`, `santa_cruz`, …), el mismo vocabulario
 * que el departamento de destino de los envíos; null si la base no lo tiene resuelto.
 */
final readonly class TripVehicleView
{
    public function __construct(
        public string $vehicleId,
        public ?string $plate,
        public float $capacityKg,
        public float $capacityM3,
        public ?string $baseNodeId = null,
        public ?string $baseNodeName = null,
        public ?float $baseLatitude = null,
        public ?float $baseLongitude = null,
        public ?string $department = null,
    ) {}

    /**
     * Punto de salida del camión, o null si su base no tiene ubicación conocida.
     */
    public function base(): ?GeoPoint
    {
        if ($this->baseLatitude === null || $this->baseLongitude === null) {
            return null;
        }

        try {
            return GeoPoint::of($this->baseLatitude, $this->baseLongitude);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Nodo de origen en la forma del contrato ({id, name}), o null si el camión no tiene base conocida.
     *
     * @return array{id: string, name: string}|null
     */
    public function originNode(): ?array
    {
        if ($this->baseNodeId === null || $this->baseNodeName === null) {
            return null;
        }

        return ['id' => $this->baseNodeId, 'name' => $this->baseNodeName];
    }
}
