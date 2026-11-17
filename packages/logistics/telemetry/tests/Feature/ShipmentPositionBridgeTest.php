<?php

declare(strict_types=1);

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;
use Logistics\Telemetry\Application\Contract\ShipmentCustodyReader;
use Logistics\Telemetry\Application\Ingest\ShipmentPositionBridge;
use Tests\TestCase;

// El puente mete la posición por envío en la ingesta de telemetría del vehículo que custodia el envío, para
// que el motor de desvío/reruteo funcione aunque la app mande el GPS por envío. Se verifica contra un
// CommandBus que registra lo despachado (modo sync), sin tocar la base.
uses(TestCase::class);

/** Vehículo y envío de prueba (ids con forma de uuid, no se consultan contra la base). */
const SPB_VEHICLE = '01a137b3-049a-756f-bfae-d3aa1be6d47c';
const SPB_SHIPMENT = '01a1c719-a05e-77f6-b193-ac6875c5f256';
const SPB_AT = '2026-11-17T12:00:00-04:00';

/**
 * CommandBus de prueba que registra los comandos despachados (no ejecuta handlers).
 *
 * @return object{commands: list<Command>}
 */
function spbRecordingBus(): object
{
    $bus = new class implements CommandBus
    {
        /** @var list<Command> */
        public array $commands = [];

        public function dispatch(Command $command): void
        {
            $this->commands[] = $command;
        }
    };

    app()->instance(CommandBus::class, $bus);

    return $bus;
}

/** Enlaza una custodia de prueba con el estado dado (null = sin entrega). */
function spbCustody(?string $status, string $vehicleId = SPB_VEHICLE): void
{
    app()->bind(ShipmentCustodyReader::class, fn (): ShipmentCustodyReader => new class($status, $vehicleId) implements ShipmentCustodyReader
    {
        public function __construct(private ?string $status, private string $vehicleId) {}

        public function custodyOf(string $shipmentId): ?array
        {
            if ($this->status === null) {
                return null;
            }

            return [
                'shipment_id' => $shipmentId,
                'vehicle_id' => $this->vehicleId,
                'status' => $this->status,
                'loaded_at' => null,
                'closed_at' => null,
            ];
        }

        public function onboardShipmentIds(string $vehicleId): array
        {
            return [];
        }
    });
}

beforeEach(function (): void {
    config()->set('logistics-telemetry.ingest.mode', 'sync');
    config()->set('logistics-telemetry.ingest.bridge_shipment_position', true);
});

it('puentea la posición de un envío a bordo a un ping de telemetría de su vehículo', function (): void {
    $bus = spbRecordingBus();
    spbCustody('IN_TRANSIT');

    app(ShipmentPositionBridge::class)->bridge(SPB_SHIPMENT, -17.7465, -63.1712, SPB_AT, 8.0, 120.0, 5.0);

    expect($bus->commands)->toHaveCount(1);
    $command = $bus->commands[0];
    expect($command)->toBeInstanceOf(RecordVehiclePings::class);
    expect($command->vehicleId)->toBe(SPB_VEHICLE);
    expect($command->driverId)->toBeNull();
    expect($command->source)->toBe('app');
    expect($command->pings)->toHaveCount(1);

    $ping = $command->pings[0];
    expect($ping['lat'])->toBe(-17.7465);
    expect($ping['lng'])->toBe(-63.1712);
    expect($ping['recorded_at'])->toBe(SPB_AT);
    expect($ping['fix_id'])->toBe(SPB_VEHICLE.':'.SPB_AT);
    expect($ping['heading'])->toBe(120);
    expect($ping['speed_kph'])->toEqualWithDelta(28.8, 0.0001);
    expect($ping['accuracy_m'])->toBe(5.0);
});

it('no puentea un envío que no está a bordo (ya entregado)', function (): void {
    $bus = spbRecordingBus();
    spbCustody('DELIVERED');

    app(ShipmentPositionBridge::class)->bridge(SPB_SHIPMENT, -17.7465, -63.1712, SPB_AT);

    expect($bus->commands)->toHaveCount(0);
});

it('no puentea cuando el envío no tiene custodia (sin entrega/sin vehículo)', function (): void {
    $bus = spbRecordingBus();
    spbCustody(null);

    app(ShipmentPositionBridge::class)->bridge(SPB_SHIPMENT, -17.7465, -63.1712, SPB_AT);

    expect($bus->commands)->toHaveCount(0);
});

it('no puentea si el interruptor está apagado', function (): void {
    config()->set('logistics-telemetry.ingest.bridge_shipment_position', false);
    $bus = spbRecordingBus();
    spbCustody('IN_TRANSIT');

    app(ShipmentPositionBridge::class)->bridge(SPB_SHIPMENT, -17.7465, -63.1712, SPB_AT);

    expect($bus->commands)->toHaveCount(0);
});
