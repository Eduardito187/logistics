<?php

declare(strict_types=1);

use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Exception\DuplicateLabelException;
use Logistics\Labels\Domain\Model\EmittedLabel;
use Logistics\Labels\Domain\Service\LabelBatchFactory;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Infrastructure\Zpl\ZplLabelRenderer;
use Logistics\Labels\Tests\Support\LabelFixtures;

/**
 * Persistencia Eloquent de las etiquetas emitidas contra PostgreSQL (logistics_test): el índice único
 * sobre label_id es la detección dura de duplicados, y la revocación marca filas sin borrarlas.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->repository = app(EmittedLabelRepository::class);
    $this->renderer = new ZplLabelRenderer;
    $this->signer = LabelFixtures::signer();
});

function emittedLabelFrom(object $test, LabelPayload $payload, string $orderNumber): EmittedLabel
{
    $signed = $test->signer->sign($payload);

    return EmittedLabel::of($orderNumber, $signed, $test->renderer->render($signed), $payload->issuedAt);
}

/**
 * @return list<LabelPayload>
 */
function batchPayloads(int $quantity): array
{
    return (new LabelBatchFactory)->build(
        keyId: 1,
        orderNumber: 'DM-260040001',
        origin: 'CD-SCZ',
        destination: LabelPayload::CUSTOMER_DESTINATION,
        issuedAt: 1759312800,
        lines: [['sku' => 'LG-GT32WPP', 'quantity' => $quantity, 'piecesPerUnit' => 1]],
    );
}

it('rejects a second label with the same label id', function (): void {
    /** @var EmittedLabelRepository $repository */
    $repository = $this->repository;

    $label = emittedLabelFrom($this, LabelFixtures::payload(), 'DM-260040000');
    $repository->add($label);

    expect(fn () => $repository->add($label))->toThrow(DuplicateLabelException::class);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('revokes the active labels of an order, keeping the rows for audit', function (): void {
    /** @var EmittedLabelRepository $repository */
    $repository = $this->repository;

    foreach (batchPayloads(2) as $payload) {
        $repository->add(emittedLabelFrom($this, $payload, 'DM-260040001'));
    }

    $revoked = $repository->revokeForOrder('DM-260040001', 1759316400, 'repacked');

    expect($revoked)->toHaveCount(2)
        ->and($repository->activeForOrder('DM-260040001'))->toBe([])
        ->and($repository->forOrder('DM-260040001'))->toHaveCount(2);

    $first = $repository->forOrder('DM-260040001')[0];
    expect($first->isRevoked())->toBeTrue()
        ->and($first->revokedReason)->toBe('repacked');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('is a no-op when revoking an order that has no active labels', function (): void {
    /** @var EmittedLabelRepository $repository */
    $repository = $this->repository;

    foreach (batchPayloads(1) as $payload) {
        $repository->add(emittedLabelFrom($this, $payload, 'DM-260040001'));
    }

    $repository->revokeForOrder('DM-260040001', 1759316400, 'repacked');

    expect($repository->revokeForOrder('DM-260040001', 1759320000, 'again'))->toBe([]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
