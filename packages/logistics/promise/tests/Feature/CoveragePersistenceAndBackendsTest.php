<?php

declare(strict_types=1);

use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Promise\Domain\ValueObject\PromiseStatus;
use Logistics\Promise\Domain\ValueObject\ReservationWindow;
use Logistics\Promise\Domain\ValueObject\ZoneServiceProfile;
use Logistics\Promise\Infrastructure\Coverage\CoverageBackedProfileProvider;
use Logistics\Promise\Infrastructure\Network\NetworkBackedCorridorProvider;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\EloquentPromiseRepository;

/*
| Cobertura de la Infrastructure de promise que SÍ toca base: el repositorio Eloquent y su mapper
| (ida y vuelta agregado↔fila), y los puertos de solo-lectura que cruzan a coverage y network leyendo sus
| Records. Se ejercita el happy-path con datos sembrados y las ramas de "no hay cobertura/corredor".
|
| Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
*/

beforeEach(function (): void {
    //
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

// ---------------------------------------------------------------------------
// EloquentPromiseRepository + PromiseMapper
// ---------------------------------------------------------------------------

it('guarda y relee una promesa cotizada (ida y vuelta por el mapper)', function (): void {
    /** @var EloquentPromiseRepository $repo */
    $repo = app(EloquentPromiseRepository::class);

    $id = $repo->nextIdentity();
    $promise = DeliveryPromise::quote(
        $id,
        'SHIP-260000123',
        '0191c2a0-1111-7000-8000-000000000001',
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), 'AM'),
    );

    $repo->save($promise);

    $reloaded = $repo->findById($id);

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->status())->toBe(PromiseStatus::Quoted)
        ->and($reloaded->shipmentRef())->toBe('SHIP-260000123')
        ->and($reloaded->slot()->timeSlot)->toBe('AM');
});

it('devuelve null al releer una promesa inexistente', function (): void {
    /** @var EloquentPromiseRepository $repo */
    $repo = app(EloquentPromiseRepository::class);

    expect($repo->findById(PromiseId::generate()))->toBeNull();
});

it('lista las promesas reservadas cuya ventana ya venció', function (): void {
    /** @var EloquentPromiseRepository $repo */
    $repo = app(EloquentPromiseRepository::class);

    $id = $repo->nextIdentity();
    $reserved = DeliveryPromise::reconstitute(
        $id,
        'SHIP-260000124',
        '0191c2a0-1111-7000-8000-000000000001',
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), 'AM'),
        PromiseStatus::Reserved,
        ReservationWindow::until(new DateTimeImmutable('2026-01-01T00:00:00')),
    );
    $repo->save($reserved);

    $expired = $repo->reservedExpiredAt(new DateTimeImmutable('2026-10-15T08:00:00'));

    $ids = array_map(static fn (PromiseId $p): string => $p->toString(), $expired);

    expect($ids)->toContain($id->toString());
});

it('persiste la ventana de reserva al guardar una promesa reservada', function (): void {
    /** @var EloquentPromiseRepository $repo */
    $repo = app(EloquentPromiseRepository::class);

    $id = $repo->nextIdentity();
    $reserved = DeliveryPromise::reconstitute(
        $id,
        'SHIP-260000125',
        '0191c2a0-1111-7000-8000-000000000001',
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), 'PM'),
        PromiseStatus::Reserved,
        ReservationWindow::until(new DateTimeImmutable('2026-10-21T12:00:00')),
    );
    $repo->save($reserved);

    $reloaded = $repo->findById($id);

    expect($reloaded->status())->toBe(PromiseStatus::Reserved)
        ->and($reloaded->reservedUntil())->not->toBeNull();
});

// ---------------------------------------------------------------------------
// CoverageBackedProfileProvider
// ---------------------------------------------------------------------------

it('devuelve null como perfil cuando la zona de cobertura no existe', function (): void {
    $provider = app(CoverageBackedProfileProvider::class);

    expect($provider->profileFor('0191c2a0-1111-7000-8000-0000000000aa'))->toBeNull();
});

it('traduce las reglas de la zona a un perfil de servicio', function (): void {
    $zone = ZoneRecord::factory()->create();

    $provider = app(CoverageBackedProfileProvider::class);

    $profile = $provider->profileFor((string) $zone->id);

    expect($profile)->toBeInstanceOf(ZoneServiceProfile::class);
});

it('devuelve null como perfil cuando la zona está inactiva', function (): void {
    $zone = ZoneRecord::factory()->create(['active' => false]);

    $provider = app(CoverageBackedProfileProvider::class);

    expect($provider->profileFor((string) $zone->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// NetworkBackedCorridorProvider
// ---------------------------------------------------------------------------

it('no ve corredor cuando la zona no existe', function (): void {
    $provider = app(NetworkBackedCorridorProvider::class);

    expect($provider->servesZone('0191c2a0-1111-7000-8000-0000000000bb'))->toBeFalse();
});

it('no ve corredor cuando la zona no tiene nodo servidor configurado', function (): void {
    // La factory deja rules.nodeId en null por defecto: no hay nodo que sirva la zona.
    $zone = ZoneRecord::factory()->create();

    $provider = app(NetworkBackedCorridorProvider::class);

    expect($provider->servesZone((string) $zone->id))->toBeFalse();
});
