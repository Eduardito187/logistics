<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Logistics\Execution\Application\Command\CaptureDeliveryEvidence\CaptureDeliveryEvidence;
use Logistics\Execution\Application\Command\RecordEvidenceValidation\RecordEvidenceValidation;
use Logistics\Execution\Application\Query\GetDeliveryEvidence\GetDeliveryEvidence;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryEvidenceRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Flujo de evidencia anti-fraude ligada al cliente: captura (hash + auditoría + outbox, estado pending),
 * consulta del estado, y write-back de validación (estado explícito o cruce on-premise del número de
 * documento). La validación es señal, nunca bloquea.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function captureCmd(string $shipmentId, array $overrides = []): CaptureDeliveryEvidence
{
    return new CaptureDeliveryEvidence(
        shipmentId: $shipmentId,
        kind: $overrides['kind'] ?? 'id_document',
        storageDisk: 'id_document',
        storagePath: 'id_document/'.$shipmentId.'/x.jpg',
        sha256: $overrides['sha256'] ?? hash('sha256', 'bytes-'.$shipmentId),
        customerId: 'CUST-1',
        documentId: $overrides['documentId'] ?? '1234567',
        customerName: 'Ana Cliente',
        customerPhone: '700',
        lat: -17.8,
        lng: -63.2,
        deviceId: 'dev-1',
        userId: 'user-1',
        capturedAt: '2026-11-05T12:00:00+00:00',
        idempotencyKey: $overrides['idempotencyKey'] ?? ('idem-'.$shipmentId),
    );
}

it('captura la evidencia en estado pending con hash y auditoría', function (): void {
    $shipmentId = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(captureCmd($shipmentId));

    $record = DeliveryEvidenceRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();
    expect($record->validation_status)->toBe('pending')
        ->and($record->kind)->toBe('id_document')
        ->and($record->customer_document_id)->toBe('1234567')
        ->and(strlen($record->sha256))->toBe(64);

    // La consulta de estado devuelve la evidencia pending.
    $views = app(QueryBus::class)->ask(new GetDeliveryEvidence($shipmentId));
    expect($views)->toHaveCount(1)
        ->and($views[0]->validationStatus)->toBe('pending');
});

it('es idempotente por clave: el reenvío no duplica la evidencia', function (): void {
    $shipmentId = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(captureCmd($shipmentId));
    app(CommandBus::class)->dispatch(captureCmd($shipmentId));

    expect(DeliveryEvidenceRecord::query()->where('shipment_id', $shipmentId)->count())->toBe(1);
});

it('valida ok por cruce on-premise del número de documento contra el cliente', function (): void {
    $shipmentId = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(captureCmd($shipmentId, ['documentId' => '1234567']));
    $id = DeliveryEvidenceRecord::query()->where('shipment_id', $shipmentId)->value('id');

    app(CommandBus::class)->dispatch(new RecordEvidenceValidation(evidenceId: $id, ocrDocumentNumber: '1.234.567'));

    expect(DeliveryEvidenceRecord::query()->whereKey($id)->value('validation_status'))->toBe('ok');
});

it('marca mismatch cuando el número leído no coincide con el del cliente', function (): void {
    $shipmentId = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(captureCmd($shipmentId, ['documentId' => '1234567']));
    $id = DeliveryEvidenceRecord::query()->where('shipment_id', $shipmentId)->value('id');

    app(CommandBus::class)->dispatch(new RecordEvidenceValidation(evidenceId: $id, ocrDocumentNumber: '9999999'));

    expect(DeliveryEvidenceRecord::query()->whereKey($id)->value('validation_status'))->toBe('mismatch');
});

it('acepta un estado de validación explícito (lo decide el validador)', function (): void {
    $shipmentId = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(captureCmd($shipmentId, ['kind' => 'signature']));
    $id = DeliveryEvidenceRecord::query()->where('shipment_id', $shipmentId)->value('id');

    app(CommandBus::class)->dispatch(new RecordEvidenceValidation(evidenceId: $id, status: 'review', detail: 'firma dudosa'));

    $record = DeliveryEvidenceRecord::query()->whereKey($id)->firstOrFail();
    expect($record->validation_status)->toBe('review')
        ->and($record->validation_detail)->toBe('firma dudosa');
});

it('capta la evidencia por el endpoint app (multipart) y la devuelve', function (): void {
    Storage::fake('id_document');
    $shipmentId = Uuid::generate()->value;

    $response = $this->post('/api/v1/app/deliveries/evidence', [
        'shipment_id' => $shipmentId,
        'kind' => 'id_document',
        'photo' => UploadedFile::fake()->image('carnet.jpg'),
        'document_id' => '1234567',
        'customer_name' => 'Ana',
    ]);

    $response->assertCreated();
    expect(DeliveryEvidenceRecord::query()->where('shipment_id', $shipmentId)->count())->toBe(1);

    $this->getJson('/api/v1/app/deliveries/'.$shipmentId.'/evidence')
        ->assertOk()
        ->assertJsonPath('data.0.validation_status', 'pending');
});

it('registra la validación por el endpoint de integraciones', function (): void {
    $shipmentId = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(captureCmd($shipmentId));
    $id = DeliveryEvidenceRecord::query()->where('shipment_id', $shipmentId)->value('id');

    $this->postJson('/api/v1/integrations/evidence/'.$id.'/validation', ['status' => 'ok'])
        ->assertOk();

    expect(DeliveryEvidenceRecord::query()->whereKey($id)->value('validation_status'))->toBe('ok');
});

it('encola execution.delivery_evidence_validated al outbox al validar, sin duplicar en reenvíos', function (): void {
    $shipmentId = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(captureCmd($shipmentId, ['kind' => 'damage']));
    $id = DeliveryEvidenceRecord::query()->where('shipment_id', $shipmentId)->value('id');

    app(CommandBus::class)->dispatch(new RecordEvidenceValidation(
        evidenceId: $id,
        status: 'ok',
        validatedAt: '2026-11-05T13:00:00+00:00',
        validatedBy: 'evidence-sidecar',
    ));

    $rows = DB::table('outbox_messages')
        ->where('event_name', 'execution.delivery_evidence_validated')
        ->where('aggregate_id', $id)
        ->get();

    expect($rows)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $rows[0]->payload, true);
    expect($rows[0]->aggregate_type)->toBe('execution.delivery_evidence')
        ->and($payload['shipment_id'])->toBe($shipmentId)
        ->and($payload['kind'])->toBe('damage')
        ->and($payload['validation_status'])->toBe('ok')
        ->and($payload['previous_status'])->toBe('pending')
        ->and($payload['validated_by'])->toBe('evidence-sidecar')
        ->and($payload['occurred_at'])->toBe('2026-11-05T13:00:00+00:00');

    // Reenvío del mismo resultado (reintento del validador): no graba un segundo hecho.
    app(CommandBus::class)->dispatch(new RecordEvidenceValidation(evidenceId: $id, status: 'ok'));

    expect(DB::table('outbox_messages')
        ->where('event_name', 'execution.delivery_evidence_validated')
        ->where('aggregate_id', $id)
        ->count())->toBe(1);
});
