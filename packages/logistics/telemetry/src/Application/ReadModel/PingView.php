<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

use Logistics\Telemetry\Domain\Model\VehiclePing;

/**
 * Proyección de lectura de un ping para dibujar la traza (track) de un vehículo. Solo lo que necesita el
 * mapa: posición, velocidad, rumbo e instante.
 */
final readonly class PingView
{
    private function __construct(
        public float $lat,
        public float $lng,
        public ?float $speedKph,
        public ?int $heading,
        public string $recordedAt,
    ) {}

    public static function fromDomain(VehiclePing $ping): self
    {
        return new self(
            $ping->point()->lat,
            $ping->point()->lng,
            $ping->speed()?->kph,
            $ping->heading()?->degrees,
            $ping->recordedAt()->format(DATE_ATOM),
        );
    }

    /**
     * @return array{lat: float, lng: float, speed_kph: float|null, heading: int|null, recorded_at: string}
     */
    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'speed_kph' => $this->speedKph,
            'heading' => $this->heading,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
