<?php

declare(strict_types=1);

/**
 * Cobertura del endpoint de contraverificación de la firma de una etiqueta QR (puerta app):
 * POST /api/v1/app/labels/verify.
 *
 * Recorre el controller (VerifyLabelController), su FormRequest (VerifyLabelRequest), la consulta
 * VerifyLabel y su handler, el gateway por defecto (StubLabelVerificationGateway, sin claves cargadas) y
 * el ReadModel LabelVerificationView. La verificación es un HECHO DE NEGOCIO: una etiqueta inválida o con
 * clave desconocida responde 200 con valid=false, no un error. No caza bugs: ejercita el happy-path real.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('verifica una etiqueta con clave conocida como valida', function (): void {
    // El stub por defecto conoce el keyId 1; payload y firma presentes ⇒ válida.
    $this->postJson('/api/v1/app/labels/verify', [
        'payload' => '{"s":"SHP-1","u":"U-1","k":1}',
        'signature' => 'ZmFrZS1zaWduYXR1cmU',
        'key_id' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.result', 'valid');
});

it('marca como invalida una etiqueta con clave desconocida', function (): void {
    // keyId fuera de las claves conocidas ⇒ resultado de negocio unknown_key (200, no error).
    $this->postJson('/api/v1/app/labels/verify', [
        'payload' => '{"s":"SHP-2","u":"U-2","k":999}',
        'signature' => 'ZmFrZS1zaWduYXR1cmU',
        'key_id' => 999,
    ])
        ->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.result', 'unknown_key');
});

it('rechaza una verificacion sin los campos requeridos', function (): void {
    // Faltan payload/signature/key_id ⇒ 422 (reglas de VerifyLabelRequest).
    $this->postJson('/api/v1/app/labels/verify', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payload', 'signature', 'key_id']);
});
