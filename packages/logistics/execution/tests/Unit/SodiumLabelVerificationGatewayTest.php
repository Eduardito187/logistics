<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\LabelVerification;
use Logistics\Execution\Infrastructure\Labels\SodiumLabelVerificationGateway;

/**
 * Verificación REAL Ed25519 de la firma de una etiqueta (contraverificación del servidor, offline).
 * Firma un payload canónico con una clave privada de prueba y comprueba el gateway contra la pública.
 */
function signBase64Url(string $payload, string $secretKey): string
{
    $raw = sodium_crypto_sign_detached($payload, $secretKey);

    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

it('verifies a genuine Ed25519 signature against the matching public key as valid', function (): void {
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $secret = sodium_crypto_sign_secretkey($pair);

    $payload = '{"k":1,"s":"SHP-1","u":"U-1"}';
    $gateway = new SodiumLabelVerificationGateway([1 => $public]);

    $result = $gateway->verify($payload, signBase64Url($payload, $secret), 1);

    expect($result)->toBe(LabelVerification::Valid)
        ->and($result->isValid())->toBeTrue();
});

it('reports an invalid signature when the payload was edited after signing', function (): void {
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $secret = sodium_crypto_sign_secretkey($pair);

    $signature = signBase64Url('{"k":1,"s":"SHP-1"}', $secret);
    $gateway = new SodiumLabelVerificationGateway([1 => $public]);

    // El payload cambió (etiqueta editada): la firma ya no corresponde.
    expect($gateway->verify('{"k":1,"s":"SHP-9"}', $signature, 1))->toBe(LabelVerification::InvalidSignature);
});

it('reports an unknown key when the key id is not among the loaded public keys', function (): void {
    $public = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    $gateway = new SodiumLabelVerificationGateway([1 => $public]);

    expect($gateway->verify('{"k":7}', 'c2ln', 7))->toBe(LabelVerification::UnknownKey);
});

it('reports an invalid signature when the signature is not valid base64url or has the wrong length', function (): void {
    $public = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    $gateway = new SodiumLabelVerificationGateway([1 => $public]);

    expect($gateway->verify('{"k":1}', '', 1))->toBe(LabelVerification::InvalidSignature)
        ->and($gateway->verify('{"k":1}', 'too-short', 1))->toBe(LabelVerification::InvalidSignature);
});

it('rejects a public key that is not 32 bytes', function (): void {
    expect(fn () => new SodiumLabelVerificationGateway([1 => 'short-key']))
        ->toThrow(ExecutionException::class, '32 bytes');
});
