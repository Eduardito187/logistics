<?php

declare(strict_types=1);

use Logistics\Labels\Application\Query\GetPublicKeysBundle\GetPublicKeysBundle;
use Logistics\Labels\Application\ReadModel\PublicKeyBundleView;
use Logistics\Labels\Tests\Support\LabelTestEnv;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Distribución del bundle de claves públicas que la app usa para verificar firmas de QR sin conexión.
 * Debe incluir la pública de la clave activa (derivada del firmante) y las anteriores aún aceptadas, y
 * nunca la clave privada.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
});

it('returns only the active key when no previous keys are configured', function (): void {
    LabelTestEnv::configureSigner(keyId: 1);

    /** @var PublicKeyBundleView $bundle */
    $bundle = app(QueryBus::class)->ask(new GetPublicKeysBundle);
    $data = $bundle->toArray();

    expect($data['active_key_id'])->toBe(1)
        ->and($data['keys'])->toHaveCount(1)
        ->and($data['keys'][0]['id'])->toBe(1)
        ->and($data['keys'][0]['public_key'])->toBe(LabelTestEnv::publicKeyBase64(LabelTestEnv::SEED));
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('includes the previous accepted keys alongside the active one, sorted by id', function (): void {
    $previousSeed = 'logistics-labels-previous-key-01';
    $previousPublic = LabelTestEnv::publicKeyBase64($previousSeed);

    LabelTestEnv::configureSigner(keyId: 2, previousPublicKeys: '1:'.$previousPublic);

    /** @var PublicKeyBundleView $bundle */
    $bundle = app(QueryBus::class)->ask(new GetPublicKeysBundle);
    $data = $bundle->toArray();

    expect($data['active_key_id'])->toBe(2)
        ->and(array_column($data['keys'], 'id'))->toBe([1, 2])
        ->and($data['keys'][0]['public_key'])->toBe($previousPublic)
        ->and($data['keys'][1]['public_key'])->toBe(LabelTestEnv::publicKeyBase64(LabelTestEnv::SEED));
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
