<?php

declare(strict_types=1);

namespace Logistics\Labels\Database\Factories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Labels\Domain\Service\LabelBatchFactory;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelSigner;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Labels\Infrastructure\Zpl\ZplLabelRenderer;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `labels_emitted`. Produce etiquetas de demo REALES: el `signed_json` es un QR
 * firmado Ed25519 válido (con una clave demo determinista) y el `zpl` es el ZPL que generaría la
 * impresora, de modo que el admin, la API y los tests vean material coherente y verificable.
 *
 * La clave demo NO es la de producción: sirve solo para que los datos sembrados sean autoconsistentes
 * (la firma valida contra la pública demo). El id de la fila es UUID v7; el `label_id` es el ULID del
 * payload, único por bulto.
 *
 * @extends Factory<EmittedLabelRecord>
 */
final class EmittedLabelRecordFactory extends Factory
{
    /** Semilla Ed25519 demo de 32 bytes (determinista, fuera de producción). */
    private const string DEMO_SEED = 'logistics-labels-demo-seed-0001!';

    /** Id de la clave demo (campo `k` del QR en datos sembrados). */
    private const int DEMO_KEY_ID = 1;

    protected $model = EmittedLabelRecord::class;

    public function definition(): array
    {
        $signer = SodiumLabelSigner::fromSeed(self::DEMO_KEY_ID, self::DEMO_SEED);
        $renderer = new ZplLabelRenderer;
        $batch = new LabelBatchFactory;

        $issuedAt = $this->faker->dateTimeBetween('-7 days', 'now')->getTimestamp();
        $orderNumber = 'DM-'.$this->faker->numberBetween(260000000, 260999999);
        $sku = $this->faker->randomElement(['LG-GT32WPP', 'SAMS-QN55', 'WHP-REF420', 'MAB-LAV18', 'BOSCH-TAL']);

        // Un payload de un bulto de una línea: una unidad, una pieza (caso común en demo).
        $payload = $batch->build(
            keyId: self::DEMO_KEY_ID,
            orderNumber: $orderNumber,
            origin: $this->faker->randomElement(['CD-SCZ', 'CD-LPZ', 'CD-CBB']),
            destination: LabelPayload::CUSTOMER_DESTINATION,
            issuedAt: $issuedAt,
            lines: [['sku' => $sku, 'quantity' => 1, 'piecesPerUnit' => 1]],
        )[0];

        $signed = $signer->sign($payload);

        return [
            'id' => Uuid::generate()->value,
            'order_id' => $orderNumber,
            'label_id' => $payload->id,
            'key_id' => self::DEMO_KEY_ID,
            'zpl' => $renderer->render($signed),
            'signed_json' => $signed->toQrContent(),
            'created_at' => (new DateTimeImmutable)->setTimestamp($issuedAt)->format('Y-m-d H:i:sP'),
            'revoked_at' => null,
            'revoked_reason' => null,
        ];
    }

    /**
     * Fija el pedido (útil para agrupar varias etiquetas de demo del mismo pedido).
     */
    public function forOrder(string $orderNumber): self
    {
        return $this->state(fn (): array => [
            'order_id' => $orderNumber,
        ]);
    }

    /**
     * Etiqueta revocada (reimpresión): conserva la fila pero marcada como inválida.
     */
    public function revoked(string $reason = 'reprinted'): self
    {
        return $this->state(fn (): array => [
            'revoked_at' => now()->toIso8601String(),
            'revoked_reason' => $reason,
        ]);
    }
}
