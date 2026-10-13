<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Logistics\Execution\Presentation\Http\Request\PreparationScanRequest;

/**
 * Contrato OFFLINE-FIRST (C-EXE-02): todo escaneo de escritura exige una clave de idempotencia, venga
 * por el header `Idempotency-Key` o por el cuerpo. Sin ella no hay forma de deduplicar el reenvío de la
 * cola offline, y una clave vacía colisionaría en el índice único de la base. Se prueba sobre la base de
 * validación común de los escaneos (ScanRequest), que comparten todos los endpoints de escritura.
 */
function validatePreparationScan(array $body, array $headers = []): array
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $request = PreparationScanRequest::create(
        '/api/v1/app/deliveries/scans/preparation',
        'POST',
        $body,
        [],
        [],
        $server,
    );
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->validateResolved();

    /** @var array<string, mixed> $validated */
    $validated = $request->validated();

    return $validated;
}

function validScanBody(): array
{
    return [
        'shipment_id' => 'd1111111-1111-7111-8111-111111111111',
        'vehicle_id' => 'a1111111-1111-7111-8111-111111111111',
        'unit_id' => 'U-1',
        'lat' => -16.5,
        'lng' => -68.15,
        'occurred_at' => '2026-10-13T09:00:00+00:00',
    ];
}

it('accepts the idempotency key from the Idempotency-Key header when absent in the body', function (): void {
    $validated = validatePreparationScan(validScanBody(), ['Idempotency-Key' => 'hdr-key-1']);

    expect($validated['idempotency_key'])->toBe('hdr-key-1');
});

it('accepts the idempotency key from the body', function (): void {
    $validated = validatePreparationScan(validScanBody() + ['idempotency_key' => 'body-key-1']);

    expect($validated['idempotency_key'])->toBe('body-key-1');
});

it('prefers the body idempotency key over the header when both are present', function (): void {
    $validated = validatePreparationScan(
        validScanBody() + ['idempotency_key' => 'body-wins'],
        ['Idempotency-Key' => 'hdr-loses'],
    );

    expect($validated['idempotency_key'])->toBe('body-wins');
});

it('rejects a write scan with no idempotency key at all', function (): void {
    expect(fn () => validatePreparationScan(validScanBody()))
        ->toThrow(ValidationException::class);
});

it('rejects a write scan with a blank idempotency key', function (): void {
    expect(fn () => validatePreparationScan(validScanBody() + ['idempotency_key' => '   ']))
        ->toThrow(ValidationException::class);
});
