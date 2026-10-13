<?php

declare(strict_types=1);

use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Labels\Domain\ValueObject\VerificationResult;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelVerifier;
use Logistics\Labels\Tests\Support\LabelFixtures;

it('verifies a label signed with the active key', function (): void {
    $signer = LabelFixtures::signer();
    $verifier = new SodiumLabelVerifier([1 => $signer->publicKey()]);

    expect($verifier->verify($signer->sign(LabelFixtures::payload())))->toBe(VerificationResult::Valid);
});

it('detects a label whose content was edited after signing', function (): void {
    $signer = LabelFixtures::signer();
    $verifier = new SodiumLabelVerifier([1 => $signer->publicKey()]);

    $data = json_decode($signer->sign(LabelFixtures::payload())->toQrContent(), true);
    $data['o'] = 'DM-260099999';
    $tampered = SignedLabel::fromQrContent(json_encode($data));

    expect($verifier->verify($tampered))->toBe(VerificationResult::InvalidSignature);
});

it('rejects a label signed with an unknown key', function (): void {
    $verifier = new SodiumLabelVerifier([1 => LabelFixtures::signer()->publicKey()]);
    $label = LabelFixtures::signer(2, str_repeat('x', 32))->sign(LabelFixtures::payload(2));

    expect($verifier->verify($label))->toBe(VerificationResult::UnknownKey);
});

it('keeps verifying labels from the previous key after a rotation', function (): void {
    $old = LabelFixtures::signer(1);
    $new = LabelFixtures::signer(2, str_repeat('n', 32));
    $verifier = new SodiumLabelVerifier([1 => $old->publicKey(), 2 => $new->publicKey()]);

    expect($verifier->verify($old->sign(LabelFixtures::payload(1))))->toBe(VerificationResult::Valid)
        ->and($verifier->verify($new->sign(LabelFixtures::payload(2))))->toBe(VerificationResult::Valid);
});

it('refuses to sign a payload that declares another key', function (): void {
    expect(fn () => LabelFixtures::signer(1)->sign(LabelFixtures::payload(2)))
        ->toThrow(InvalidArgumentException::class);
});
