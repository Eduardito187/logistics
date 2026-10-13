<?php

declare(strict_types=1);

use Logistics\Labels\Domain\Exception\InvalidLabelException;
use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Labels\Tests\Support\LabelFixtures;

it('round-trips through the QR content', function (): void {
    $label = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $parsed = SignedLabel::fromQrContent($label->toQrContent());

    expect($parsed->payload)->toEqual($label->payload)
        ->and($parsed->signature)->toBe($label->signature);
});

it('keeps the QR content compact and free of personal data', function (): void {
    $content = LabelFixtures::signer()->sign(LabelFixtures::payload())->toQrContent();

    // Con un ULID real y pedido/SKU típicos queda en ~257 caracteres (verificado en el E2E).
    expect(strlen($content))->toBeLessThanOrEqual(280)
        ->and(array_keys(json_decode($content, true)))
        ->toBe(['v', 't', 'k', 'id', 'o', 'ln', 'sku', 'u', 'pc', 'org', 'dst', 'ts', 's']);
});

it('rejects unreadable QR content', function (string $content, string $reason): void {
    expect(fn () => SignedLabel::fromQrContent($content))->toThrow(InvalidLabelException::class, $reason);
})->with([
    'not json' => ['hello', 'JSON'],
    'json scalar' => ['42', 'objeto'],
    'unsupported version' => ['{"v":2,"t":"PKG"}', 'versión'],
    'type not implemented yet' => ['{"v":1,"t":"VEH"}', 'PKG'],
    'missing fields' => ['{"v":1,"t":"PKG","k":1}', "'id'"],
]);

it('rejects a signature that is not valid base64url or not 64 bytes', function (): void {
    $data = json_decode(LabelFixtures::signer()->sign(LabelFixtures::payload())->toQrContent(), true);

    $data['s'] = 'not base64!';
    expect(fn () => SignedLabel::fromQrContent(json_encode($data)))->toThrow(InvalidLabelException::class, 'base64url');

    $data['s'] = 'AAAA';
    expect(fn () => SignedLabel::fromQrContent(json_encode($data)))->toThrow(InvalidLabelException::class, '64 bytes');
});
