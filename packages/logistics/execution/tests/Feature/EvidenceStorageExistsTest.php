<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Logistics\Execution\Infrastructure\Storage\FilesystemEvidenceStorage;

/**
 * Verificación de que una referencia de foto existe en su disco de evidencia (auditoría app #3). Discos
 * falsos en memoria: no necesita S3 real ni PostgreSQL.
 */
it('confirma una referencia subida y rechaza una que no existe', function (): void {
    Storage::fake('pod');
    Storage::disk('pod')->put('pod/SHP-1/foto.jpg', 'jpg');
    $storage = new FilesystemEvidenceStorage(['pod' => 'pod', 'damage' => 'damage'], 1);

    expect($storage->exists('pod/SHP-1/foto.jpg'))->toBeTrue()
        ->and($storage->exists('pod/SHP-1/otra.jpg'))->toBeFalse()
        ->and($storage->exists('pendiente'))->toBeFalse()
        ->and($storage->exists(''))->toBeFalse()
        ->and($storage->exists('pod/../secreto.jpg'))->toBeFalse()
        ->and($storage->exists('/etc/passwd'))->toBeFalse();
});

it('busca en todos los discos de evidencia si el path no empieza con un tipo conocido', function (): void {
    Storage::fake('pod');
    Storage::fake('damage');
    Storage::disk('damage')->put('legacy/foto.jpg', 'jpg');
    $storage = new FilesystemEvidenceStorage(['pod' => 'pod', 'damage' => 'damage'], 1);

    expect($storage->exists('legacy/foto.jpg'))->toBeTrue();
});

it('responde null (no verificable) si el disco no responde, nunca un falso "no existe"', function (): void {
    $storage = new FilesystemEvidenceStorage(['pod' => 'disco-que-no-existe'], 1);

    expect($storage->exists('pod/SHP-1/foto.jpg'))->toBeNull();
});
