<?php

declare(strict_types=1);

namespace Commerce\Pricing\Database\Factories;

use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model\PriceListRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de listas de precios para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce el
 * repositorio en producción. No pasa por el dominio a propósito: una fábrica es un detalle de
 * infraestructura que arma filas directamente.
 *
 * @extends Factory<PriceListRecord>
 */
final class PriceListFactory extends Factory
{
    protected $model = PriceListRecord::class;

    public function definition(): array
    {
        return [
            'id' => PriceListId::generate()->toString(),
            'name' => ucfirst($this->faker->unique()->word()).' price list',
            'currency' => 'BOB',
            'channel' => 'retail',
            'status' => 'draft',
        ];
    }

    /**
     * Lista activa (vigente en su canal).
     */
    public function active(): self
    {
        return $this->state(fn (): array => ['status' => 'active']);
    }

    /**
     * Lista archivada.
     */
    public function archived(): self
    {
        return $this->state(fn (): array => ['status' => 'archived']);
    }

    /**
     * Lista para un canal concreto (retail|b2b|c2c).
     */
    public function forChannel(string $channel): self
    {
        return $this->state(fn (): array => ['channel' => $channel]);
    }
}
