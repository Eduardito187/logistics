<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Domain\ValueObject\AddressConfidenceLevel;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Factory de la cabecera de envío, para tests y seeders. Genera ids UUID v7 (no autoincrementales) y
 * coordenadas realistas de Bolivia; el nivel de confianza se deriva SIEMPRE del score, como en el dominio.
 *
 * @extends Factory<ShipmentRecord>
 */
final class ShipmentRecordFactory extends Factory
{
    protected $model = ShipmentRecord::class;

    public function definition(): array
    {
        $score = $this->faker->numberBetween(10, 100);

        // Datos de contacto realistas de Bolivia: CI con extensión departamental (LP, SC, CB, …),
        // celular +591 7/6XXXXXXX y correo derivado del nombre.
        $name = $this->faker->name();
        $extension = $this->faker->randomElement(['LP', 'SC', 'CB', 'OR', 'PT', 'TJ', 'CH', 'BE', 'PA']);
        $mobilePrefix = $this->faker->randomElement(['7', '6']);

        return [
            'id' => Uuid::generate()->value,
            'source' => $this->faker->randomElement(array_map(
                fn (OrderSource $source): string => $source->value,
                OrderSource::cases(),
            )),
            'external_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'customer_name' => $name,
            'customer_document_id' => (string) $this->faker->numberBetween(1000000, 9999999).' '.$extension,
            'customer_phone' => '+591 '.$mobilePrefix.(string) $this->faker->numberBetween(1000000, 9999999),
            'customer_email' => $this->faker->unique()->safeEmail(),
            'address_text' => $this->faker->streetAddress().', '.$this->faker->city(),
            'address_reference' => $this->faker->boolean(40) ? $this->faker->sentence(4) : null,
            'lat' => $this->faker->randomFloat(7, -22.9, -9.7),
            'lng' => $this->faker->randomFloat(7, -69.6, -57.5),
            'address_confidence' => $score,
            'address_confidence_level' => AddressConfidenceLevel::fromScore($score)->value,
            'status' => ShipmentStatus::Received->value,
            // Nodo destino: por defecto null (lo pobla el ingest/SAP; ver la migración aditiva).
            'destination_node_id' => null,
            // Franja de entrega: por defecto sin reservar (se asigna al reservar el cupo de despacho).
            'delivery_date' => null,
            'delivery_window_start' => null,
            'delivery_window_end' => null,
        ];
    }

    /**
     * Fija el nodo destino del envío (UUID de un nodo de red).
     */
    public function forNode(string $destinationNodeId): self
    {
        return $this->state(fn (): array => [
            'destination_node_id' => $destinationNodeId,
        ]);
    }

    /**
     * Fija la franja de entrega del envío: día (Y-m-d) y ventana horaria (HH:MM–HH:MM).
     */
    public function withDeliverySlot(string $date, string $windowStart, string $windowEnd): self
    {
        return $this->state(fn (): array => [
            'delivery_date' => $date,
            'delivery_window_start' => $windowStart,
            'delivery_window_end' => $windowEnd,
        ]);
    }
}
