<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Logistics\Labels\Domain\ValueObject\VerificationResult;
use Logistics\Labels\Infrastructure\Crypto\SigningKeyRegistry;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelSigner;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelVerifier;
use Logistics\Labels\Tests\Support\LabelFixtures;

/**
 * Registro append-only de claves de firma + red de seguridad de verificación.
 *
 * Invariante: una etiqueta válida NUNCA se rechaza. Si un key_id tuvo varias públicas históricas, el
 * verificador prueba todas; y el registro impide reusar un id con otra clave (guardia al firmar).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        $this->markTestSkipped('Requiere PostgreSQL (base logistics_test).');
    }
    $this->registry = new SigningKeyRegistry(app(ConnectionInterface::class));
});

it('registra append-only y detecta la colisión de un id reusado con otra clave', function (): void {
    $keyA = base64_encode(LabelFixtures::signer(1)->publicKey());
    $keyB = base64_encode(LabelFixtures::signer(1, str_repeat('b', 32))->publicKey());

    $this->registry->register(1, $keyA);
    $this->registry->register(1, $keyA); // idempotente

    expect($this->registry->all()[1])->toBe([$keyA])
        ->and($this->registry->conflicts(1, $keyA))->toBeFalse()
        ->and($this->registry->conflicts(1, $keyB))->toBeTrue()  // otra clave bajo el MISMO id = colisión
        ->and($this->registry->conflicts(2, $keyB))->toBeFalse(); // id nuevo = sin colisión
});

it('el verificador valida una etiqueta aunque su id tenga varias públicas (vieja + nueva)', function (): void {
    $old = LabelFixtures::signer(1);
    $new = LabelFixtures::signer(1, str_repeat('n', 32)); // mismo id 1, otra clave

    // El id 1 sirve AMBAS públicas: una etiqueta firmada por la vieja sigue validando.
    $verifier = new SodiumLabelVerifier([1 => [$old->publicKey(), $new->publicKey()]]);

    expect($verifier->verify($old->sign(LabelFixtures::payload(1))))->toBe(VerificationResult::Valid)
        ->and($verifier->verify($new->sign(LabelFixtures::payload(1))))->toBe(VerificationResult::Valid);
});

it('el firmante aborta (guardia al boot) si el key_id ya firmó con otra clave', function (): void {
    $original = LabelFixtures::signer(1);
    $this->registry->register(1, base64_encode($original->publicKey()));

    // Se configura OTRA clave privada bajo el MISMO key_id 1: la guardia debe impedir firmar.
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('z', 32)));
    config()->set('logistics-labels.signing_key', base64_encode($other));
    config()->set('logistics-labels.active_key_id', 1);
    app()->forgetInstance(SodiumLabelSigner::class);

    expect(fn () => app(SodiumLabelSigner::class))
        ->toThrow(RuntimeException::class);
});
