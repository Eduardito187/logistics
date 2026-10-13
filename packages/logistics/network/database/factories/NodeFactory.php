<?php

declare(strict_types=1);

namespace Logistics\Network\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de nodos de la red para pruebas y datos de demo.
 *
 * Genera coordenadas dentro del rango aproximado de Bolivia para que los nodos de prueba caigan en
 * un mapa coherente. El id es un UUID v7 como en producción.
 *
 * @extends Factory<NodeRecord>
 */
final class NodeFactory extends Factory
{
    protected $model = NodeRecord::class;

    public function definition(): array
    {
        /** @var NodeType $type */
        $type = $this->faker->randomElement(NodeType::cases());

        $prefix = match ($type) {
            NodeType::DistributionCenter => 'CD',
            NodeType::Store => 'T',
            NodeType::PickupPoint => 'PK',
        };

        return [
            'id' => Uuid::generate()->value,
            'code' => sprintf('%s-%s-%02d', $prefix, strtoupper($this->faker->lexify('???')), $this->faker->numberBetween(1, 99)),
            'name' => $this->faker->company(),
            'type' => $type->value,
            // Rango aproximado del territorio boliviano (lat -22..-9, lng -70..-57).
            'lat' => $this->faker->randomFloat(7, -22.9, -9.7),
            'lng' => $this->faker->randomFloat(7, -69.6, -57.5),
            'status' => NodeStatus::Active->value,
        ];
    }

    /**
     * Estado: nodo dado de baja.
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => NodeStatus::Inactive->value]);
    }

    /**
     * Estado: centro de distribución.
     */
    public function distributionCenter(): self
    {
        return $this->state(fn (): array => ['type' => NodeType::DistributionCenter->value]);
    }

    /**
     * Estado: tienda. Se llama `asStore` para no colisionar con `Factory::store()` de Eloquent.
     */
    public function asStore(): self
    {
        return $this->state(fn (): array => ['type' => NodeType::Store->value]);
    }
}
