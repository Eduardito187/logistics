<?php

declare(strict_types=1);

use Logistics\Execution\Domain\ValueObject\LabelVerification;
use Logistics\Execution\Infrastructure\Labels\StubLabelVerificationGateway;

it('verifies a well-formed label signed with a known key as valid', function (): void {
    $gateway = new StubLabelVerificationGateway([1, 2]);

    $result = $gateway->verify('{"s":"abc","k":1}', 'c2lnbmF0dXJl', 1);

    expect($result)->toBe(LabelVerification::Valid)
        ->and($result->isValid())->toBeTrue();
});

it('reports an unknown key when the key id is not among the known public keys', function (): void {
    $gateway = new StubLabelVerificationGateway([1]);

    expect($gateway->verify('{"s":"abc"}', 'c2ln', 9)->isValid())->toBeFalse()
        ->and($gateway->verify('{"s":"abc"}', 'c2ln', 9))->toBe(LabelVerification::UnknownKey);
});

it('reports an invalid signature when payload or signature are empty', function (): void {
    $gateway = new StubLabelVerificationGateway([1]);

    expect($gateway->verify('', 'c2ln', 1))->toBe(LabelVerification::InvalidSignature)
        ->and($gateway->verify('{"s":"abc"}', '', 1))->toBe(LabelVerification::InvalidSignature);
});
