<?php

declare(strict_types=1);

namespace Logistics\Network\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Infrastructure\Persistence\Eloquent\LegRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de tramos/corredores entre nodos para pruebas y datos de demo.
 *
 * Por defecto crea dos nodos nuevos como extremos; en escenarios reales se fijan con from()/to()
 * apuntando a nodos ya existentes para no inflar el grafo.
 *
 * @extends Factory<LegRecord>
 */
final class LegFactory extends Factory
{
    protected $model = LegRecord::class;

    public function definition(): array
    {
        /** @var CorridorType $corridor */
        $corridor = $this->faker->randomElement(CorridorType::cases());

        return [
            'id' => Uuid::generate()->value,
            'from_node_id' => NodeFactory::new(),
            'to_node_id' => NodeFactory::new(),
            'corridor_type' => $corridor->value,
        ];
    }

    /**
     * Fija el nodo de origen del tramo.
     */
    public function from(NodeRecord $node): self
    {
        return $this->state(fn (): array => ['from_node_id' => $node->id]);
    }

    /**
     * Fija el nodo de destino del tramo.
     */
    public function to(NodeRecord $node): self
    {
        return $this->state(fn (): array => ['to_node_id' => $node->id]);
    }

    /**
     * Fija el corredor del tramo.
     */
    public function corridor(CorridorType $corridor): self
    {
        return $this->state(fn (): array => ['corridor_type' => $corridor->value]);
    }
}
