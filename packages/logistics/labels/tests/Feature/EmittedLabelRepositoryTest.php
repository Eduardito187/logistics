<?php

declare(strict_types=1);

use Logistics\Labels\Application\Query\GetShipmentLabels\GetShipmentLabels;
use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Exception\DuplicateLabelException;
use Logistics\Labels\Domain\Model\EmittedLabel;
use Logistics\Labels\Domain\Service\LabelBatchFactory;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Infrastructure\Zpl\ZplLabelRenderer;
use Logistics\Labels\Tests\Support\LabelFixtures;
use Logistics\Shared\Application\Query\QueryBus;

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

/**
 * @return list<LabelPayload>
 */
function batchPayloadsFor(string $orderNumber, int $quantity, int $issuedAt): array
{
    return (new LabelBatchFactory)->build(
        keyId: 1,
        orderNumber: $orderNumber,
        origin: 'CD-SCZ',
        destination: LabelPayload::CUSTOMER_DESTINATION,
        issuedAt: $issuedAt,
        lines: [['sku' => 'LG-GT32WPP', 'quantity' => $quantity, 'piecesPerUnit' => 1]],
    );
}

it('el listado deduplica por bulto físico tras una re-emisión: una etiqueta vigente (la última) por unidad', function (): void {
    /** @var EmittedLabelRepository $repository */
    $repository = $this->repository;
    $order = 'DM-260040002';

    // Emisión original (2 unidades legítimas) y una RE-EMISIÓN posterior (ids nuevos, mismo bulto físico),
    // como dejó el incidente de rotación de clave: el crudo acumula 4 filas sin borrar las viejas.
    foreach (batchPayloadsFor($order, 2, 1759312800) as $payload) {
        $repository->add(emittedLabelFrom($this, $payload, $order));
    }
    foreach (batchPayloadsFor($order, 2, 1759399200) as $payload) {
        $repository->add(emittedLabelFrom($this, $payload, $order));
    }
    expect($repository->forOrder($order))->toHaveCount(4);

    // El listado de la app debe devolver una por bulto físico (la más reciente), no las 4 crudas.
    /** @var list<EmittedLabelView> $views */
    $views = app(QueryBus::class)->ask(new GetShipmentLabels($order));
    expect($views)->toHaveCount(2)
        ->and(collect($views)->every(fn (EmittedLabelView $v): bool => $v->emittedAt === 1759399200))->toBeTrue()
        ->and(collect($views)->pluck('unitIndex')->sort()->values()->all())->toBe([1, 2]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('rejects a second label with the same label id', function (): void {
    /** @var EmittedLabelRepository $repository */
    $repository = $this->repository;

    $label = emittedLabelFrom($this, LabelFixtures::payload(), 'DM-260040000');
    $repository->add($label);

    expect(fn () => $repository->add($label))->toThrow(DuplicateLabelException::class);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('treats all emitted labels as permanent: activeForOrder == forOrder', function (): void {
    /** @var EmittedLabelRepository $repository */
    $repository = $this->repository;

    foreach (batchPayloads(2) as $payload) {
        $repository->add(emittedLabelFrom($this, $payload, 'DM-260040001'));
    }

    expect($repository->activeForOrder('DM-260040001'))->toHaveCount(2)
        ->and($repository->forOrder('DM-260040001'))->toHaveCount(2);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
