<?php

declare(strict_types=1);

namespace Logistics\Fleet\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\FleetDocumentModel;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `fleet_documents`. Produce documentos de flota de demo asociados por defecto a un
 * vehículo, con emisión pasada y vencimiento a futuro; sin archivo adjunto salvo que se pida.
 *
 * @extends Factory<FleetDocumentModel>
 */
final class FleetDocumentFactory extends Factory
{
    protected $model = FleetDocumentModel::class;

    public function definition(): array
    {
        $issuedOn = $this->faker->dateTimeBetween('-2 years', '-6 months');

        return [
            'id' => Uuid::generate()->value,
            'owner_type' => DocumentOwnerType::Vehicle->value,
            'owner_id' => Uuid::generate()->value,
            'document_type' => $this->faker->randomElement(['soat', 'technical_inspection', 'insurance_policy']),
            'expires_on' => $this->faker->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d'),
            'issued_on' => $issuedOn->format('Y-m-d'),
            'file_path' => null,
            'note' => null,
        ];
    }

    /**
     * Documento de un dueño concreto (vehículo, chofer o ayudante) por su tipo e id.
     */
    public function forOwner(DocumentOwnerType $ownerType, string $ownerId): self
    {
        return $this->state(fn (): array => [
            'owner_type' => $ownerType->value,
            'owner_id' => $ownerId,
        ]);
    }

    /**
     * Documento ya vencido (para probar las alertas de vencimiento).
     */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'expires_on' => $this->faker->dateTimeBetween('-6 months', '-1 day')->format('Y-m-d'),
        ]);
    }

    /**
     * Documento con archivo adjunto en el disco S3 (clave de ejemplo).
     */
    public function withFile(): self
    {
        return $this->state(fn (): array => [
            'file_path' => 'fleet-documents/'.Uuid::generate()->value.'.pdf',
        ]);
    }
}
