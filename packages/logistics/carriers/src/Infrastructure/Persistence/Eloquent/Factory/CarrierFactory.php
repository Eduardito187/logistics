<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;

/**
 * Fábrica de transportistas para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce
 * el repositorio en producción. No pasa por el dominio a propósito: una fábrica es un detalle de
 * infraestructura que arma filas directamente.
 *
 * @extends Factory<CarrierRecord>
 */
final class CarrierFactory extends Factory
{
    protected $model = CarrierRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => CarrierId::generate()->toString(),
            'name' => $this->faker->unique()->company(),
            'tax_id' => 'NIT-'.$this->faker->unique()->numerify('#######'),
            'status' => 'active',
            'rating' => null,
            'rating_count' => 0,
            'documents' => [],
        ];
    }

    /**
     * Transportista suspendido.
     */
    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => 'suspended']);
    }

    /**
     * Transportista con reputación acumulada (promedio y cantidad de calificaciones).
     */
    public function rated(float $average, int $count): self
    {
        return $this->state(fn (): array => [
            'rating' => $average,
            'rating_count' => $count,
        ]);
    }

    /**
     * Transportista con documentos (habilitaciones, pólizas, etc.).
     *
     * @param list<array{type: string, reference: string}> $documents
     */
    public function withDocuments(array $documents): self
    {
        return $this->state(fn (): array => ['documents' => $documents]);
    }
}
