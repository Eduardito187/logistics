<?php

declare(strict_types=1);

use Logistics\Labels\Domain\Exception\InvalidLabelException;
use Logistics\Labels\Domain\ValueObject\Fraction;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Domain\ValueObject\LabelType;
use Logistics\Labels\Tests\Support\LabelFixtures;

it('builds the exact canonical message that gets signed', function (): void {
    expect(LabelFixtures::payload()->canonical())
        ->toBe('LGL|1|PKG|1|01JB8ZQ4X7K2M9N5P6R7S8T9VW|DM-260012345|3|LG-GT32WPP|1/2|1/1|CD-SCZ|C|1759312800');
});

it('marks package labels with the current version and type', function (): void {
    $payload = LabelFixtures::payload();

    expect($payload->version)->toBe(LabelPayload::CURRENT_VERSION)
        ->and($payload->type)->toBe(LabelType::Package)
        ->and($payload->isForCustomer())->toBeTrue();
});

it('accepts a node as destination', function (): void {
    $payload = LabelPayload::forPackage(1, '01JB8ZQ4X7K2M9N5P6R7S8T9VW', '000123456', 1, 'abc/123', Fraction::single(), Fraction::single(), 'CD-SCZ', 'T-SCZ-03', 0);

    expect($payload->destination)->toBe('T-SCZ-03')
        ->and($payload->isForCustomer())->toBeFalse();
});

it('rejects malformed fields', function (callable $build, string $reason): void {
    expect($build)->toThrow(InvalidLabelException::class, $reason);
})->with([
    'id that is not a ULID' => [
        fn () => LabelPayload::forPackage(1, 'not-a-ulid', 'DM-1', 1, 'SKU', Fraction::single(), Fraction::single(), 'CD-SCZ', 'C', 0),
        'ULID',
    ],
    'pipe in SKU (canonical separator)' => [
        fn () => LabelPayload::forPackage(1, '01JB8ZQ4X7K2M9N5P6R7S8T9VW', 'DM-1', 1, 'A|B', Fraction::single(), Fraction::single(), 'CD-SCZ', 'C', 0),
        'SKU',
    ],
    'lowercase node code' => [
        fn () => LabelPayload::forPackage(1, '01JB8ZQ4X7K2M9N5P6R7S8T9VW', 'DM-1', 1, 'SKU', Fraction::single(), Fraction::single(), 'cd-scz', 'C', 0),
        'origen',
    ],
    'invalid destination' => [
        fn () => LabelPayload::forPackage(1, '01JB8ZQ4X7K2M9N5P6R7S8T9VW', 'DM-1', 1, 'SKU', Fraction::single(), Fraction::single(), 'CD-SCZ', 'x y', 0),
        'destino',
    ],
    'zero line number' => [
        fn () => LabelPayload::forPackage(1, '01JB8ZQ4X7K2M9N5P6R7S8T9VW', 'DM-1', 0, 'SKU', Fraction::single(), Fraction::single(), 'CD-SCZ', 'C', 0),
        'línea',
    ],
    'zero key id' => [
        fn () => LabelPayload::forPackage(0, '01JB8ZQ4X7K2M9N5P6R7S8T9VW', 'DM-1', 1, 'SKU', Fraction::single(), Fraction::single(), 'CD-SCZ', 'C', 0),
        'keyId',
    ],
]);

it('validates fractions', function (): void {
    expect(Fraction::of(2, 3)->toCanonical())->toBe('2/3')
        ->and(fn () => Fraction::of(4, 3))->toThrow(InvalidLabelException::class)
        ->and(fn () => Fraction::of(0, 3))->toThrow(InvalidLabelException::class)
        ->and(fn () => Fraction::of(1, 0))->toThrow(InvalidLabelException::class);
});
