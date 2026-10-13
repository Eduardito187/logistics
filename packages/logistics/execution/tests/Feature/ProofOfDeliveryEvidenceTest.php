<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Storage\FilesystemEvidenceStorage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * POD a S3 (C-EXE-03): la foto de evidencia se sube al disco de evidencia y su referencia durable
 * (path) queda guardada en la prueba de entrega. Usa discos falsos en memoria (Storage::fake), así que
 * no necesita S3 real ni PostgreSQL para la parte de almacenamiento.
 */
$shipment = 'e1111111-1111-7111-8111-111111111111';
$vehicle = 'f2222222-2222-7222-8222-222222222222';

it('stores a proof-of-delivery photo on the pod disk and returns a durable reference', function (): void {
    Storage::fake('pod');

    /** @var EvidenceStorage $storage */
    $storage = app(EvidenceStorage::class);
    $file = UploadedFile::fake()->image('pod.jpg');

    $stored = $storage->store(EvidenceKind::ProofOfDelivery, 'SHP-1', $file);

    expect($stored->disk)->toBe('pod')
        ->and($stored->kind)->toBe('pod')
        ->and($stored->path)->toStartWith('pod/SHP-1/');
    Storage::disk('pod')->assertExists($stored->path);
});

it('stores a damage photo on the damage disk, separate from proof of delivery', function (): void {
    Storage::fake('damage');

    /** @var EvidenceStorage $storage */
    $storage = app(EvidenceStorage::class);
    $stored = $storage->store(EvidenceKind::Damage, 'SHP-2', UploadedFile::fake()->image('damage.jpg'));

    expect($stored->disk)->toBe('damage')
        ->and($stored->kind)->toBe('damage')
        ->and($stored->path)->toStartWith('damage/SHP-2/');
    Storage::disk('damage')->assertExists($stored->path);
});

it('fails with a domain exception after retrying when the disk cannot be written', function (): void {
    // Un disco inexistente hace fallar cada intento; tras los reintentos se reempaqueta como
    // ExecutionException, sin filtrar el error del driver.
    $storage = new FilesystemEvidenceStorage(
        ['pod' => 'disco-que-no-existe'],
        2,
    );

    expect(fn () => $storage->store(EvidenceKind::ProofOfDelivery, 'SHP-3', UploadedFile::fake()->image('x.jpg')))
        ->toThrow(ExecutionException::class);
});

it('persists the photo reference in the proof of delivery through the command bus', function () use ($shipment, $vehicle): void {
    Storage::fake('pod');

    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);

    $bus->dispatch(new RecordPreparationScan(
        shipmentId: $shipment,
        vehicleId: $vehicle,
        unitId: 'U-1',
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-13T08:00:00+00:00',
        idempotencyKey: 'prep-1',
    ));

    $bus->dispatch(new RecordLoadScan(
        shipmentId: $shipment,
        unitId: 'U-1',
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-13T08:30:00+00:00',
        idempotencyKey: 'load-1',
    ));

    /** @var EvidenceStorage $storage */
    $storage = app(EvidenceStorage::class);
    $stored = $storage->store(EvidenceKind::ProofOfDelivery, $shipment, UploadedFile::fake()->image('pod.jpg'));

    $bus->dispatch(new RecordProofOfDelivery(
        shipmentId: $shipment,
        unitId: 'U-1',
        confirmation: 'QR-OK-1',
        photoUrl: $stored->url,
        photoPath: $stored->path,
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-13T11:00:00+00:00',
        idempotencyKey: 'pod-1',
    ));

    $record = DeliveryRecord::query()->where('shipment_id', $shipment)->firstOrFail();

    expect($record->status)->toBe('DELIVERED')
        ->and($record->pod_photo_path)->toBe($stored->path)
        ->and($record->pod_photo_path)->toStartWith('pod/'.$shipment.'/');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
