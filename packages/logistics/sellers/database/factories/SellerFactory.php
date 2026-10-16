<?php

declare(strict_types=1);

namespace Logistics\Sellers\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerRecord;

/**
 * Fábrica de sellers para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce el
 * repositorio en producción. No pasa por el dominio a propósito: una fábrica es un detalle de
 * infraestructura que arma filas directamente.
 *
 * @extends Factory<SellerRecord>
 */
final class SellerFactory extends Factory
{
    protected $model = SellerRecord::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'id' => SellerId::generate()->toString(),
            'legal_name' => $name,
            'tax_id' => 'NIT-'.$this->faker->unique()->numerify('#######'),
            'status' => 'onboarding',
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->unique()->companyEmail(),
            'contact_phone' => $this->faker->numerify('7#######'),
        ];
    }

    /**
     * Seller activo (habilitado a operar).
     */
    public function active(): self
    {
        return $this->state(fn (): array => ['status' => 'active']);
    }

    /**
     * Seller suspendido.
     */
    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => 'suspended']);
    }
}
