<?php

declare(strict_types=1);

use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Backfill de `tracking_shipments.customer_email` (comando logistics:tracking:backfill-customer-email): copia
 * el email normalizado desde `shipments` solo en filas DELIVERED con email vacío, en lotes por id, sin pisar
 * valores existentes y de forma idempotente. `--dry-run` no escribe. Corre contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Seguimiento con su envío en shipments (email del envío dado) y estado/email del seguimiento dados. */
function backfillEmailRow(?string $shipmentEmail, VisibleStatus $status, ?string $trackingEmail = null): TrackingRecord
{
    $shipment = ShipmentRecord::factory()->create(['customer_email' => $shipmentEmail]);

    return TrackingRecord::factory()->withStatus($status)->create([
        'shipment_id' => $shipment->id,
        'customer_email' => $trackingEmail,
    ]);
}

it('fills the email of old deliveries in chunks without touching anything else, idempotently', function (): void {
    $first = backfillEmailRow('  Ana.Perez@Example.COM ', VisibleStatus::Delivered);
    $second = backfillEmailRow('luis@example.com', VisibleStatus::Delivered);
    $invalid = backfillEmailRow('no-es-un-email', VisibleStatus::Delivered);
    $withoutSource = backfillEmailRow(null, VisibleStatus::Delivered);
    $kept = backfillEmailRow('nuevo@example.com', VisibleStatus::Delivered, 'original@example.com');
    $onTheWay = backfillEmailRow('camino@example.com', VisibleStatus::OnTheWay);

    $this->artisan('logistics:tracking:backfill-customer-email', ['--chunk' => 1])
        ->expectsOutputToContain('Candidatos (entregados sin email y con email en el envío): 3')
        ->expectsOutputToContain('Completados: 2')
        ->expectsOutputToContain('Omitidos por email inválido: 1')
        ->assertSuccessful();

    $email = static fn (TrackingRecord $row): ?string => TrackingRecord::query()->findOrFail($row->id)->customer_email;

    expect($email($first))->toBe('ana.perez@example.com')
        ->and($email($second))->toBe('luis@example.com')
        ->and($email($invalid))->toBeNull()
        ->and($email($withoutSource))->toBeNull()
        ->and($email($kept))->toBe('original@example.com')
        ->and($email($onTheWay))->toBeNull();

    // Segunda corrida: solo queda el inválido como candidato, nada que completar.
    $this->artisan('logistics:tracking:backfill-customer-email')
        ->expectsOutputToContain('Completados: 0')
        ->expectsOutputToContain('Omitidos por email inválido: 1')
        ->assertSuccessful();
});

it('only counts in dry-run mode', function (): void {
    $row = backfillEmailRow('ana@example.com', VisibleStatus::Delivered);

    $this->artisan('logistics:tracking:backfill-customer-email', ['--dry-run' => true])
        ->expectsOutputToContain('Simulación (--dry-run): no se escribió nada.')
        ->expectsOutputToContain('Se completarían: 1')
        ->assertSuccessful();

    expect(TrackingRecord::query()->findOrFail($row->id)->customer_email)->toBeNull();
});

it('rejects a non positive chunk size', function (): void {
    $this->artisan('logistics:tracking:backfill-customer-email', ['--chunk' => 0])
        ->assertFailed();
});
