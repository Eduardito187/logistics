<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Logistics\Execution\Application\Command\AttachDeliveryPhotos\AttachDeliveryPhotos;
use Logistics\Execution\Application\Command\CaptureDeliveryEvidence\CaptureDeliveryEvidence;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Application\Contract\DeliveryEvidenceReader;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryState;
use Logistics\Execution\Presentation\Http\Request\ProofOfDeliveryRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Foto del POD que NO salió de la subida (auditoría app #3): el mostrador mandaba `photo_path: "pendiente"`.
 * El servidor exige que cada referencia exista en su disco de evidencia (S3 o el respaldo local), o que
 * `evidence_ids` apunte a evidencia ya capturada del envío. La foto sigue siendo obligatoria.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
    Storage::fake('pod');
    podxActingAsDriver();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const PODX_AT = '2026-11-12T11:00:00-04:00';

/**
 * Prepara y carga un producto para dejar la entrega lista para el POD.
 */
function podxLoaded(string $shipment, string $unit = 'U-1'): void
{
    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $vehicle = Uuid::generate()->value;

    $bus->dispatch(new RecordPreparationScan($shipment, $vehicle, $unit, -16.5, -68.15, 'dev', 'usr', '2026-11-12T08:00:00-04:00', 'podx-prep-'.$shipment.$unit));
    $bus->dispatch(new RecordLoadScan($shipment, $unit, -16.5, -68.15, 'dev', 'usr', '2026-11-12T08:30:00-04:00', 'podx-load-'.$shipment.$unit));
}

/**
 * Cuerpo del POD con los campos de foto indicados.
 *
 * @param  array<string, mixed> $photo
 * @return array<string, mixed>
 */
function podxBody(string $shipment, array $photo): array
{
    return array_merge([
        'shipment_id' => $shipment,
        'unit_id' => 'U-1',
        'confirmation' => 'FIRMA_DIGITAL',
        'occurred_at' => PODX_AT,
        'idempotency_key' => 'podx-'.$shipment,
    ], $photo);
}

function podxActingAsDriver(): void
{
    $user = User::factory()->create();
    $user->assignRole(Role::Chofer);
    test()->actingAs($user, 'sanctum');
}

it('rechaza con 422 una foto que no se subió ("pendiente")', function (): void {
    $shipment = Uuid::generate()->value;
    podxLoaded($shipment);

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podxBody($shipment, ['photo_path' => 'pendiente']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.photo_path.0', ProofOfDeliveryRequest::PHOTO_NOT_UPLOADED);
});

it('rechaza el lote de fotos si una sola no existe', function (): void {
    $shipment = Uuid::generate()->value;
    podxLoaded($shipment);
    Storage::disk('pod')->put('pod/'.$shipment.'/a.jpg', 'jpg');

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podxBody($shipment, [
        'photo_paths' => ['pod/'.$shipment.'/a.jpg', 'pod/'.$shipment.'/fantasma.jpg'],
    ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.photo_path.0', ProofOfDeliveryRequest::PHOTO_NOT_UPLOADED);
});

it('acepta la referencia que devolvió POST /app/deliveries/photos', function (): void {
    $shipment = Uuid::generate()->value;
    podxLoaded($shipment);

    $path = $this->post('/api/v1/app/deliveries/photos', [
        'shipment_id' => $shipment,
        'photo' => UploadedFile::fake()->image('pod.jpg'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('data.path');

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podxBody($shipment, ['photo_path' => $path]))
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED')
        ->assertJsonPath('data.pod_photo_path', $path);
});

it('sin foto responde 200 y queda pendiente de foto (opción A, POD asíncrono)', function (): void {
    $shipment = Uuid::generate()->value;
    podxLoaded($shipment);

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podxBody($shipment, []))
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED')
        ->assertJsonPath('data.pod_photo_pending', true);
});

it('evidence_ids de evidencia ya capturada del envío cubren la foto', function (): void {
    $shipment = Uuid::generate()->value;
    podxLoaded($shipment);

    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $bus->dispatch(new CaptureDeliveryEvidence(
        shipmentId: $shipment,
        kind: 'pod',
        storageDisk: 'pod',
        storagePath: 'pod/'.$shipment.'/evidencia.jpg',
        sha256: hash('sha256', 'evidencia'),
    ));
    $evidenceId = app(DeliveryEvidenceReader::class)->forShipment($shipment)[0]->id;

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podxBody($shipment, ['evidence_ids' => [$evidenceId]]))
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED');
});

it('un POD sin foto queda pendiente; la foto subida después se liga sola (opción A)', function (): void {
    $shipment = Uuid::generate()->value;
    podxLoaded($shipment);

    // POD sin foto: entregado, pendiente de foto.
    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podxBody($shipment, []))
        ->assertOk()
        ->assertJsonPath('data.pod_photo_pending', true);

    // La foto llega después por la subida: se liga al envío y deja de estar pendiente.
    $path = 'pod/'.$shipment.'/tarde.jpg';
    Storage::disk('pod')->put($path, 'jpg');
    app(CommandBus::class)->dispatch(new AttachDeliveryPhotos($shipment, [$path]));

    expect(app(QueryBus::class)->ask(new GetDeliveryState($shipment))?->podPhotoPending)->toBeFalse();
});

it('el lote offline rechaza el ítem POD con una foto no subida', function (): void {
    $shipment = Uuid::generate()->value;
    podxLoaded($shipment);

    $this->postJson('/api/v1/app/deliveries/scans/batch', [
        'items' => [[
            'idempotency_key' => 'podx-batch-'.$shipment,
            'kind' => 'proof_of_delivery',
            'occurred_at' => PODX_AT,
            'payload' => [
                'shipment_id' => $shipment,
                'unit_id' => 'U-1',
                'confirmation' => 'FIRMA_DIGITAL',
                'photo_path' => 'pendiente',
            ],
        ]],
    ])
        ->assertStatus(207)
        ->assertJsonPath('data.0.result', 'rejected')
        ->assertJsonPath('data.0.problem.errors.photo_path.0', ProofOfDeliveryRequest::PHOTO_NOT_UPLOADED);
});
