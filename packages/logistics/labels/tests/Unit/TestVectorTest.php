<?php

declare(strict_types=1);

use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Labels\Domain\ValueObject\VerificationResult;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelVerifier;
use Logistics\Labels\Tests\Support\LabelFixtures;

/*
| El vector de prueba es el contrato con la app Flutter: logistics-app tiene una copia idéntica
| en test/fixtures/label_test_vector.json y verifica la misma firma con su propio código.
| Si este test falla, cambió el formato de firma: hay que subir `v` y actualizar ambos repos.
*/

it('reproduces the shared test vector byte for byte', function (): void {
    $vector = LabelFixtures::vector();
    $signer = LabelFixtures::signer($vector['key_id']);

    expect(base64_encode($signer->publicKey()))->toBe($vector['public_key_base64'])
        ->and(LabelFixtures::payload()->canonical())->toBe($vector['canonical'])
        ->and($signer->sign(LabelFixtures::payload())->toQrContent())->toBe($vector['qr_content']);
});

it('verifies the vector and rejects its tampered copy', function (): void {
    $vector = LabelFixtures::vector();
    $verifier = new SodiumLabelVerifier([$vector['key_id'] => base64_decode($vector['public_key_base64'], true)]);

    expect($verifier->verify(SignedLabel::fromQrContent($vector['qr_content'])))->toBe(VerificationResult::Valid)
        ->and($verifier->verify(SignedLabel::fromQrContent($vector['tampered_qr_content'])))->toBe(VerificationResult::InvalidSignature);
});
