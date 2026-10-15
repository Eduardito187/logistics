<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;

/**
 * Factory de traspasos de stock, para tests y seeders. Nace Pending (como una recepción recién
 * encolada); hay estados para reproducir el resto del ciclo de despacho hacia SAP.
 *
 * @extends Factory<StockTransferRecord>
 */
final class StockTransferRecordFactory extends Factory
{
    protected $model = StockTransferRecord::class;

    public function definition(): array
    {
        return [
            'id' => StockTransferId::generate()->toString(),
            'event_id' => StockTransferId::generate()->toString(),
            'shipment_ref' => 'SHP-'.$this->faker->numberBetween(10000, 99999),
            'material' => (string) $this->faker->numberBetween(100000, 999999),
            'center' => 'C'.$this->faker->numberBetween(1000, 9999),
            'warehouse' => 'W'.$this->faker->numberBetween(10, 99),
            'quantity' => $this->faker->numberBetween(1, 20),
            'status' => TransferStatus::Pending->value,
            'last_error' => null,
        ];
    }

    /**
     * Pendiente de empuje a SAP (estado inicial).
     */
    public function pending(): self
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Pending->value,
            'last_error' => null,
        ]);
    }

    /**
     * Empujado a SAP, a la espera de confirmación.
     */
    public function sent(): self
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Sent->value,
            'last_error' => null,
        ]);
    }

    /**
     * Confirmado por SAP (estado terminal feliz).
     */
    public function acknowledged(): self
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Acknowledged->value,
            'last_error' => null,
        ]);
    }

    /**
     * Fallido: visible en el panel de reconciliación y reintentable.
     */
    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Failed->value,
            'last_error' => 'SAP respondió 503 al empujar el traspaso',
        ]);
    }
}
