<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\Scope\FleetLiveScope;
use Logistics\Telemetry\Presentation\Broadcasting\FleetVehicleRemoved;

/**
 * Alcance de la flota en vivo (regla por rol y ciudad, la misma de los canales `fleet.all` /
 * `fleet.city.<departamento>`) y evento `fleet.removed`. PHP puro: sin base ni contenedor.
 */
const FLS_VEHICLE = '0192aaaa-bbbb-7ccc-8ddd-0000000000f1';

it('admin y supervisor ven todas las ciudades, tengan o no ciudad asignada', function (): void {
    foreach ([['admin'], ['supervisor'], ['planificador', 'admin'], ['Supervisor']] as $roles) {
        $scope = FleetLiveScope::fromIdentity($roles, 'santa_cruz', true);

        expect($scope->seesAllCities())->toBeTrue()
            ->and($scope->seesNothing())->toBeFalse()
            ->and($scope->city())->toBeNull()
            ->and($scope->key())->toBe('all');
    }
});

it('el planificador con ciudad queda acotado a su departamento', function (): void {
    $scope = FleetLiveScope::fromIdentity(['planificador'], ' Santa_Cruz ');

    expect($scope->seesAllCities())->toBeFalse()
        ->and($scope->seesNothing())->toBeFalse()
        ->and($scope->city())->toBe('santa_cruz')
        ->and($scope->key())->toBe('city:santa_cruz');
});

it('el planificador sin ciudad ve todas, salvo en modo estricto', function (): void {
    expect(FleetLiveScope::fromIdentity(['planificador'], null)->seesAllCities())->toBeTrue()
        ->and(FleetLiveScope::fromIdentity(['planificador'], '', false)->key())->toBe('all')
        ->and(FleetLiveScope::fromIdentity(['planificador'], null, true)->seesNothing())->toBeTrue()
        ->and(FleetLiveScope::fromIdentity(['planificador'], null, true)->key())->toBe('none');
});

it('cualquier otro rol no ve ningún camión', function (): void {
    foreach ([['chofer'], ['tienda'], ['preparador', 'transportista'], []] as $roles) {
        expect(FleetLiveScope::fromIdentity($roles, 'santa_cruz')->seesNothing())->toBeTrue();
    }
});

it('un código de ciudad inválido no abre el alcance', function (): void {
    expect(FleetLiveScope::forCity('santa cruz; drop')->seesNothing())->toBeTrue()
        ->and(FleetLiveScope::forCity('la_paz')->city())->toBe('la_paz');
});

it('la consulta viaja sin restricción por defecto y con el alcance que se le pase', function (): void {
    expect((new GetLiveFleetPositions)->scope->key())->toBe('all')
        ->and((new GetLiveFleetPositions(null, true, FleetLiveScope::forCity('la_paz')))->scope->key())
        ->toBe('city:la_paz');
});

it('fleet.removed se llama así, va por la cola y lleva solo el id del camión', function (): void {
    $event = new FleetVehicleRemoved(FLS_VEHICLE, 'santa_cruz');

    expect($event->broadcastAs())->toBe('fleet.removed')
        ->and(FleetVehicleRemoved::NAME)->toBe('fleet.removed')
        ->and($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->tries)->toBe(1)
        ->and($event->broadcastWith())->toBe(['vehicle_id' => FLS_VEHICLE]);
});

it('fleet.removed difunde por fleet.all y por la ciudad del camión; sin ciudad, solo por fleet.all', function (): void {
    $names = static fn (FleetVehicleRemoved $event): array => array_map(
        static fn (Channel $channel): string => $channel->name,
        $event->broadcastOn(),
    );

    expect((new FleetVehicleRemoved(FLS_VEHICLE, 'santa_cruz'))->broadcastOn())->each->toBeInstanceOf(PrivateChannel::class)
        ->and($names(new FleetVehicleRemoved(FLS_VEHICLE, 'santa_cruz')))
        ->toBe(['private-fleet.all', 'private-fleet.city.santa_cruz'])
        ->and($names(new FleetVehicleRemoved(FLS_VEHICLE)))->toBe(['private-fleet.all']);
});
