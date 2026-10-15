<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\NodeReceptionStatus;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\NodeReceptionRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de recepciones en nodo para tests y seeders. Arranca abierta, con un puñado de bultos
 * esperados por un corredor interno.
 *
 * @extends Factory<NodeReceptionRecord>
 */
final class NodeReceptionRecordFactory extends Factory
{
    protected $model = NodeReceptionRecord::class;

    public function definition(): array
    {
        $corridors = array_map(fn (Corridor $corridor): string => $corridor->value, Corridor::cases());

        $expected = [];
        foreach (range(1, 3) as $n) {
            $expected[] = 'U-'.$this->faker->bothify('########');
        }

        return [
            'id' => Uuid::generate()->value,
            'shipment_id' => Uuid::generate()->value,
            'origin_node_id' => Uuid::generate()->value,
            'destination_node_id' => Uuid::generate()->value,
            'corridor' => $this->faker->randomElement($corridors),
            'status' => NodeReceptionStatus::Open->value,
            'expected_unit_ids' => $expected,
            'opened_at' => $this->faker->dateTimeThisMonth()->format(DATE_ATOM),
            'reconciled_at' => null,
        ];
    }

    /**
     * Recepción de un corredor concreto.
     */
    public function onCorridor(Corridor $corridor): self
    {
        return $this->state(fn (): array => ['corridor' => $corridor->value]);
    }

    /**
     * Recepción de un envío dado.
     */
    public function forShipment(string $shipmentId): self
    {
        return $this->state(fn (): array => ['shipment_id' => $shipmentId]);
    }
}
