<?php

declare(strict_types=1);

namespace Logistics\Tracking\Database\Factories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Factory del read model de seguimiento (tabla tracking_shipments).
 *
 * Genera filas coherentes con el dominio: la pk y el id de envío son UUID, el token es un token
 * opaco real (base64url generado con bytes aleatorios) y el estado es uno del vocabulario visible.
 * Las coordenadas por defecto caen sobre Bolivia (área de La Paz) para que el mapa del admin tenga
 * sentido en la demo.
 *
 * @extends Factory<TrackingRecord>
 */
final class TrackingRecordFactory extends Factory
{
    protected $model = TrackingRecord::class;

    public function definition(): array
    {
        $hasPosition = $this->faker->boolean(70);
        $hasEta = $this->faker->boolean(60);
        $routePosition = $this->faker->numberBetween(1, 9);

        return [
            'id' => TrackingId::generate()->toString(),
            'shipment_id' => TrackingId::generate()->toString(),
            'token' => TrackingToken::generate()->toString(),
            'status' => $this->faker->randomElement(VisibleStatus::cases())->value,
            // stops_remaining se deriva de la posición en la ruta: paradas antes de la del cliente.
            'remaining_stops' => $routePosition - 1,
            'route_id' => TrackingId::generate()->toString(),
            'route_position' => $routePosition,
            'eta' => $hasEta
                ? $this->faker->dateTimeBetween('now', '+6 hours')->format(DateTimeImmutable::ATOM)
                : null,
            'last_lat' => $hasPosition ? (string) $this->faker->latitude(-16.6, -16.4) : null,
            'last_lng' => $hasPosition ? (string) $this->faker->longitude(-68.2, -68.0) : null,
            'last_position_at' => $hasPosition
                ? $this->faker->dateTimeBetween('-30 minutes', 'now')->format(DateTimeImmutable::ATOM)
                : null,
        ];
    }

    /**
     * Estado visible concreto (p. ej. en camino, entregado).
     */
    public function withStatus(VisibleStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    /**
     * Seguimiento en camino con posición GPS fresca y ETA definida.
     */
    public function onTheWay(): self
    {
        $routePosition = $this->faker->numberBetween(2, 6);

        return $this->state(fn (): array => [
            'status' => VisibleStatus::OnTheWay->value,
            'route_position' => $routePosition,
            'remaining_stops' => $routePosition - 1,
            'eta' => $this->faker->dateTimeBetween('+15 minutes', '+3 hours')->format(DateTimeImmutable::ATOM),
            'last_lat' => (string) $this->faker->latitude(-16.6, -16.4),
            'last_lng' => (string) $this->faker->longitude(-68.2, -68.0),
            'last_position_at' => (new DateTimeImmutable)->format(DateTimeImmutable::ATOM),
        ]);
    }

    /**
     * Seguimiento entregado (estado terminal): sin paradas restantes ni ETA.
     */
    public function delivered(): self
    {
        return $this->state(fn (): array => [
            'status' => VisibleStatus::Delivered->value,
            'remaining_stops' => 0,
            'eta' => null,
        ]);
    }
}
