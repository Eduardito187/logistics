<?php

declare(strict_types=1);

use Logistics\Labels\Application\Command\EmitOrderLabels\EmitOrderLabels;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Model\EmittedLabel;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Labels\Tests\Support\LabelTestEnv;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Emisión POR ORDEN (body vacío): el backend deriva origen (CD del departamento / más cercano), destino y
 * líneas de los envíos del pedido y del plan publicado, y emite solo lo AUTORIZADO y aún PENDING. Es
 * idempotente/parcial y resuelve el multishipping (un lote por envío hermano, distinguidos por `dst`).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    LabelTestEnv::configureSigner();

    // CDs de la red con departamento y coordenadas fijas (deterministas para la regla de cercanía).
    NodeRecord::factory()->distributionCenter()->inDepartment(Department::LaPaz)->create(['code' => 'CD-EMO-LP', 'lat' => -16.5000, 'lng' => -68.1500]);
    NodeRecord::factory()->distributionCenter()->inDepartment(Department::SantaCruz)->create(['code' => 'CD-EMO-SCZ', 'lat' => -17.7833, 'lng' => -63.1821]);
    NodeRecord::factory()->distributionCenter()->inDepartment(Department::Cochabamba)->create(['code' => 'CD-EMO-CBBA', 'lat' => -17.3895, 'lng' => -66.1568]);
});

/** @return list<EmittedLabel> */
function emittedFor(string $orderNumber): array
{
    return app(EmittedLabelRepository::class)->forOrder($orderNumber);
}

it('emite por orden derivando origen/destino/líneas del envío y del plan publicado', function (): void {
    $store = NodeRecord::factory()->asStore()->inDepartment(Department::Cochabamba)->create(['code' => 'T-EMO-CBBA', 'name' => 'Tienda Cochabamba']);

    // Hermano a domicilio en Santa Cruz (coords cerca de CD-EMO-SCZ).
    $home = ShipmentRecord::factory()->create([
        'external_id' => 'SCZ-EMO-01',
        'order_reference' => 'SCZ-EMO-01',
        'destination_node_id' => null,
        'lat' => -17.8000,
        'lng' => -63.2000,
    ]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $home->id, 'sku' => 'SKU-HOME', 'product_name' => 'Sofá', 'quantity' => 2]);

    // Hermano a retiro en tienda (Cochabamba).
    $pickup = ShipmentRecord::factory()->create([
        'external_id' => 'SCZ-EMO-01#n:'.$store->id,
        'order_reference' => 'SCZ-EMO-01',
        'destination_node_id' => $store->id,
    ]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $pickup->id, 'sku' => 'SKU-PICK', 'product_name' => 'Taladro', 'quantity' => 1]);

    // Ambos envíos autorizados (parada en plan publicado).
    foreach ([$home, $pickup] as $shipment) {
        $plan = PlanRecord::factory()->published()->create();
        PlannedStopRecord::factory()->create(['plan_id' => $plan->id, 'shipment_id' => $shipment->id]);
    }

    app(CommandBus::class)->dispatch(new EmitOrderLabels('SCZ-EMO-01'));

    $labels = emittedFor('SCZ-EMO-01');
    // 2 bultos del domicilio + 1 del retiro = 3 etiquetas.
    expect($labels)->toHaveCount(3);

    $byDestination = [];
    foreach ($labels as $label) {
        $byDestination[$label->destinationCode()][] = $label;
    }

    // Domicilio: dst='C', origen = CD más cercano (Santa Cruz).
    expect($byDestination['C'])->toHaveCount(2)
        ->and($byDestination['C'][0]->originCode())->toBe('CD-EMO-SCZ')
        ->and($byDestination['C'][0]->sku())->toBe('SKU-HOME');

    // Retiro: dst = código de la tienda, origen = CD del departamento del nodo (Cochabamba).
    expect($byDestination['T-EMO-CBBA'])->toHaveCount(1)
        ->and($byDestination['T-EMO-CBBA'][0]->originCode())->toBe('CD-EMO-CBBA')
        ->and($byDestination['T-EMO-CBBA'][0]->sku())->toBe('SKU-PICK');
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('es idempotente/parcial: reejecutar no duplica y emite solo lo que falta', function (): void {
    $home = ShipmentRecord::factory()->create(['external_id' => 'SCZ-EMO-02', 'order_reference' => 'SCZ-EMO-02', 'destination_node_id' => null, 'lat' => -17.80, 'lng' => -63.20]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $home->id, 'sku' => 'SKU-A', 'quantity' => 1]);
    $plan = PlanRecord::factory()->published()->create();
    PlannedStopRecord::factory()->create(['plan_id' => $plan->id, 'shipment_id' => $home->id]);

    app(CommandBus::class)->dispatch(new EmitOrderLabels('SCZ-EMO-02'));
    app(CommandBus::class)->dispatch(new EmitOrderLabels('SCZ-EMO-02'));

    expect(EmittedLabelRecord::query()->where('order_id', 'SCZ-EMO-02')->count())->toBe(1);
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('no emite envíos sin plan publicado (no autorizados)', function (): void {
    $home = ShipmentRecord::factory()->create(['external_id' => 'SCZ-EMO-03', 'order_reference' => 'SCZ-EMO-03', 'destination_node_id' => null, 'lat' => -17.80, 'lng' => -63.20]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $home->id, 'sku' => 'SKU-A', 'quantity' => 1]);
    // Sin plan publicado: no autorizado.

    app(CommandBus::class)->dispatch(new EmitOrderLabels('SCZ-EMO-03'));

    expect(EmittedLabelRecord::query()->where('order_id', 'SCZ-EMO-03')->count())->toBe(0);
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('respeta el origin override (escape hatch) cuando se pasa', function (): void {
    $home = ShipmentRecord::factory()->create(['external_id' => 'SCZ-EMO-04', 'order_reference' => 'SCZ-EMO-04', 'destination_node_id' => null, 'lat' => -17.80, 'lng' => -63.20]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $home->id, 'sku' => 'SKU-A', 'quantity' => 1]);
    $plan = PlanRecord::factory()->published()->create();
    PlannedStopRecord::factory()->create(['plan_id' => $plan->id, 'shipment_id' => $home->id]);

    app(CommandBus::class)->dispatch(new EmitOrderLabels('SCZ-EMO-04', 'CD-EMO-LP'));

    $labels = emittedFor('SCZ-EMO-04');
    expect($labels)->toHaveCount(1)
        ->and($labels[0]->originCode())->toBe('CD-EMO-LP');
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');
