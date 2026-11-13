<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Promise\Application\ReadModel\DeliveryDateView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Controllers\ListDeliveryDatesController;
use Logistics\Promise\Presentation\Http\Controllers\ReserveDeliveryDateController;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;

/*
| Endpoints de la puerta channels del Módulo 1 (días ofrecibles + reserva por fecha), por rutas ad-hoc y
| con buses falsos: se valida el contrato (validación 422, mapeo de errores 409/422, happy-path) sin
| depender de cupo sembrado. Las rutas reales las integra el coordinador. Corre contra logistics_test.
*/

const ASE_UUID = '0192f0a0-0000-7000-8000-0000000000ae';
const ASE_DATE = '2026-10-20';

beforeEach(function (): void {
    $this->withoutMiddleware();

    Route::get('/_test/delivery-dates', ListDeliveryDatesController::class);
    Route::post('/_test/date-reservation', ReserveDeliveryDateController::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** QueryBus falso que devuelve una respuesta fija. */
function aseQueryBus(mixed $result): QueryBus
{
    app()->instance(QueryBus::class, new class($result) implements QueryBus
    {
        public function __construct(private readonly mixed $result) {}

        public function ask(Query $query): mixed
        {
            return $this->result;
        }
    });

    return app(QueryBus::class);
}

/** CommandBus falso que captura y, opcional, lanza una PromiseException. */
function aseCommandBus(?PromiseException $throw = null): CommandBus
{
    app()->instance(CommandBus::class, new class($throw) implements CommandBus
    {
        /** @var list<Command> */
        public array $dispatched = [];

        public function __construct(private readonly ?PromiseException $throw) {}

        public function dispatch(Command $command): void
        {
            $this->dispatched[] = $command;

            if ($this->throw !== null) {
                throw $this->throw;
            }
        }
    });

    return app(CommandBus::class);
}

// ---------------------------------------------------------------------------
// GET delivery-dates
// ---------------------------------------------------------------------------

it('rechaza listar días sin ningún destino (422)', function (): void {
    $this->getJson('/_test/delivery-dates')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lat']);
});

it('lista los días ofrecibles para una coordenada', function (): void {
    aseQueryBus([new DeliveryDateView('muni-1', ASE_DATE, 2)]);

    $this->getJson('/_test/delivery-dates?lat=-17.78&lng=-63.18&from='.ASE_DATE.'&to='.ASE_DATE)
        ->assertOk()
        ->assertJsonPath('data.0.date', ASE_DATE)
        ->assertJsonPath('data.0.window_count', 2)
        ->assertJsonPath('meta.count', 1);
});

// ---------------------------------------------------------------------------
// POST date-reservation
// ---------------------------------------------------------------------------

it('rechaza reservar por fecha sin cuerpo (422)', function (): void {
    aseCommandBus();

    $this->postJson('/_test/date-reservation', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['shipment_id', 'date']);
});

it('reserva por fecha y devuelve 201 con slot_source auto', function (): void {
    aseCommandBus();

    $this->postJson('/_test/date-reservation', ['shipment_id' => ASE_UUID, 'date' => ASE_DATE])
        ->assertStatus(201)
        ->assertJsonPath('data.slot_source', 'auto')
        ->assertJsonPath('data.status', 'reserved');
});

it('mapea franja tomada a 409', function (): void {
    aseCommandBus(PromiseException::slotTaken('sin cupo'));

    $this->postJson('/_test/date-reservation', ['shipment_id' => ASE_UUID, 'date' => ASE_DATE])
        ->assertStatus(409)
        ->assertJsonPath('error.type', 'slot_taken');
});

it('mapea día no despachable a 422', function (): void {
    aseCommandBus(PromiseException::dayUnavailable('día bloqueado'));

    $this->postJson('/_test/date-reservation', ['shipment_id' => ASE_UUID, 'date' => ASE_DATE])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'day_unavailable');
});

it('mapea sin ventana factible a 422 (cola Unslotted)', function (): void {
    aseCommandBus(PromiseException::noWindow('sin franja con cupo'));

    $this->postJson('/_test/date-reservation', ['shipment_id' => ASE_UUID, 'date' => ASE_DATE])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'no_window');
});
