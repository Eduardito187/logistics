<?php

declare(strict_types=1);

use Logistics\Execution\Application\Command\CreateFailureReason\CreateFailureReason;
use Logistics\Execution\Application\Command\DeactivateFailureReason\DeactivateFailureReason;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Application\Command\UpdateFailureReason\UpdateFailureReason;
use Logistics\Execution\Application\Query\ListActiveFailureReasons\ListActiveFailureReasons;
use Logistics\Execution\Application\Query\ListFailureReasons\ListFailureReasons;
use Logistics\Execution\Application\ReadModel\FailureReasonView;
use Logistics\Execution\Database\Seeders\FailureReasonSeeder;
use Logistics\Execution\Domain\Contract\FailureReasonRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\FailureReasonRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Stack real del catálogo configurable de motivos de entrega fallida contra PostgreSQL (logistics_test):
 * alta/listado/desactivado por el bus CQRS, idempotencia del seeder y validación del flujo de entrega
 * fallida contra el catálogo activo.
 */
beforeEach(function (): void {
    FailureReasonRecord::query()->delete();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('crea un motivo derivando el code de la etiqueta y lo lista', function (): void {
    app(CommandBus::class)->dispatch(new CreateFailureReason(label: 'Customer absent'));

    /** @var list<FailureReasonView> $all */
    $all = app(QueryBus::class)->ask(new ListFailureReasons);

    expect($all)->toHaveCount(1)
        ->and($all[0]->code)->toBe('CUSTOMER_ABSENT')
        ->and($all[0]->label)->toBe('Customer absent')
        ->and($all[0]->active)->toBeTrue();
});

it('rechaza un code repetido', function (): void {
    $bus = app(CommandBus::class);
    $bus->dispatch(new CreateFailureReason(label: 'Other', code: 'OTHER'));

    expect(fn () => $bus->dispatch(new CreateFailureReason(label: 'Another', code: 'OTHER')))
        ->toThrow(ExecutionException::class);
});

it('edita y desactiva un motivo (soft delete), y el listado activo lo excluye', function (): void {
    $bus = app(CommandBus::class);
    $bus->dispatch(new CreateFailureReason(label: 'Damaged', code: 'DAMAGED'));
    $bus->dispatch(new UpdateFailureReason(code: 'DAMAGED', label: 'Damaged goods', requiresPhoto: true));
    $bus->dispatch(new DeactivateFailureReason('DAMAGED'));

    /** @var list<FailureReasonView> $active */
    $active = app(QueryBus::class)->ask(new ListActiveFailureReasons);
    /** @var list<FailureReasonView> $all */
    $all = app(QueryBus::class)->ask(new ListFailureReasons);

    expect($active)->toHaveCount(0)
        ->and($all)->toHaveCount(1)
        ->and($all[0]->label)->toBe('Damaged goods')
        ->and($all[0]->requiresPhoto)->toBeTrue()
        ->and($all[0]->active)->toBeFalse();
});

it('siembra los 5 motivos de fabrica de forma idempotente', function (): void {
    $seeder = app(FailureReasonSeeder::class);
    $seeder->run();
    $seeder->run();

    expect(FailureReasonRecord::query()->count())->toBe(5)
        ->and(FailureReasonRecord::query()->where('active', true)->count())->toBe(5);
});

it('rechaza un motivo inexistente o inactivo en el flujo de entrega fallida', function (): void {
    app(FailureReasonSeeder::class)->run();
    app(CommandBus::class)->dispatch(new DeactivateFailureReason('OTHER'));

    $bus = app(CommandBus::class);

    // Code desconocido → regla de negocio (no hay delivery, pero el catálogo corta antes).
    expect(fn () => $bus->dispatch(failureReasonFailedDelivery('NOT_IN_CATALOG')))
        ->toThrow(ExecutionException::class, 'no válido o inactivo');

    // Code desactivado → también se rechaza.
    expect(fn () => $bus->dispatch(failureReasonFailedDelivery('OTHER')))
        ->toThrow(ExecutionException::class, 'no válido o inactivo');
});

it('un motivo activo pasa el catalogo (falla despues por falta de entrega abierta)', function (): void {
    app(FailureReasonSeeder::class)->run();

    // CUSTOMER_ABSENT está activo: el catálogo lo acepta y el flujo avanza hasta el opener, que sin entrega
    // abierta lanza su propio error. Ese mensaje distinto prueba que el catálogo NO lo rechazó.
    expect(fn () => app(CommandBus::class)->dispatch(failureReasonFailedDelivery('CUSTOMER_ABSENT')))
        ->toThrow(ExecutionException::class, 'no hay entrega abierta');
});

it('exige la nota cuando el motivo la requiere', function (): void {
    $bus = app(CommandBus::class);
    $bus->dispatch(new CreateFailureReason(label: 'Needs note', code: 'NEEDS_NOTE', requiresNote: true));

    expect(fn () => $bus->dispatch(failureReasonFailedDelivery('NEEDS_NOTE')))
        ->toThrow(ExecutionException::class, 'exige una nota');
});

it('acepta la forma primitiva del repositorio', function (): void {
    app(CommandBus::class)->dispatch(new CreateFailureReason(label: 'Other', code: 'OTHER'));

    $reason = app(FailureReasonRepository::class)->findByCode(
        FailureReasonCode::fromString('OTHER'),
    );

    expect($reason)->not->toBeNull()
        ->and($reason->code()->value)->toBe('OTHER');
});

/**
 * Comando de entrega fallida mínimo para probar la validación del catálogo (GPS/ids de relleno).
 */
function failureReasonFailedDelivery(string $reason): RecordFailedDelivery
{
    return new RecordFailedDelivery(
        shipmentId: '00000000-0000-7000-8000-000000000001',
        unitId: 'u-1',
        reason: $reason,
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-1',
        userId: 'user-1',
        occurredAt: '2026-11-17T12:00:00+00:00',
        idempotencyKey: 'k-'.$reason,
    );
}
