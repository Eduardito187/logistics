<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Query\CheckDispatchDay\CheckDispatchDayQuery;
use Logistics\Capacity\Application\ReadModel\DispatchDayAvailabilityView;
use Logistics\Coverage\Domain\Contract\MunicipalityLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Coverage\Domain\ValueObject\MunicipalityId;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Infrastructure\Adapter\BlackoutAwarePendingDeliveryProvider;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Proveedor interno falso que devuelve una lista fija de candidatos.
 *
 * @param list<DeliveryCandidate> $candidates
 */
function innerPending(array $candidates): PendingDeliveryProvider
{
    return new class($candidates) implements PendingDeliveryProvider
    {
        /** @param list<DeliveryCandidate> $candidates */
        public function __construct(private readonly array $candidates) {}

        public function pendingFor(PlanDate $date): array
        {
            return $this->candidates;
        }
    };
}

/**
 * Localizador falso que resuelve el municipio por la latitud del punto (mapa lat => municipio).
 *
 * @param array<string, string|null> $byLatitude
 */
function fakeMunicipalityLocator(array $byLatitude): MunicipalityLocator
{
    return new class($byLatitude) implements MunicipalityLocator
    {
        /** @param array<string, string|null> $byLatitude */
        public function __construct(private readonly array $byLatitude) {}

        public function locate(GeoPoint $point): ?MunicipalityId
        {
            $id = $this->byLatitude[(string) $point->lat] ?? null;

            return $id === null ? null : MunicipalityId::fromString($id);
        }
    };
}

/**
 * QueryBus falso que devuelve el veredicto de bloqueo por municipio.
 *
 * @param array<string, bool> $blockedByMunicipality
 */
function blockQueryBus(array $blockedByMunicipality): QueryBus
{
    return new class($blockedByMunicipality) implements QueryBus
    {
        /** @param array<string, bool> $blocked */
        public function __construct(private readonly array $blocked) {}

        public function ask(Query $query): mixed
        {
            assert($query instanceof CheckDispatchDayQuery);
            $key = $query->municipalityId ?? '';
            $blocked = $this->blocked[$key] ?? false;

            return new DispatchDayAvailabilityView(
                $query->date,
                $query->municipalityId,
                $blocked,
                $blocked ? 'feriado' : null,
                true,
                null,
            );
        }
    };
}

function candidateAt(string $shipmentId, float $lat): DeliveryCandidate
{
    return DeliveryCandidate::of(
        shipmentId: $shipmentId,
        latitude: $lat,
        longitude: -68.1,
        weightKg: 10.0,
        volumeM3: 0.1,
        departmentId: 'dep-1',
    );
}

it('drops candidates whose municipality has the plan date blocked', function (): void {
    $candidates = [
        candidateAt('ship-a', -16.5), // muni-a (bloqueado)
        candidateAt('ship-b', -17.4), // muni-b (abierto)
    ];
    $provider = new BlackoutAwarePendingDeliveryProvider(
        innerPending($candidates),
        fakeMunicipalityLocator(['-16.5' => 'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', '-17.4' => 'bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb']),
        blockQueryBus(['aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa' => true]),
    );

    $kept = $provider->pendingFor(PlanDate::fromString('2026-11-20'));

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->shipmentId)->toBe('ship-b');
});

it('keeps every candidate when no municipality is blocked', function (): void {
    $candidates = [candidateAt('ship-a', -16.5), candidateAt('ship-b', -17.4)];
    $provider = new BlackoutAwarePendingDeliveryProvider(
        innerPending($candidates),
        fakeMunicipalityLocator(['-16.5' => 'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', '-17.4' => 'bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb']),
        blockQueryBus([]),
    );

    expect($provider->pendingFor(PlanDate::fromString('2026-11-20')))->toHaveCount(2);
});

it('drops candidates with unknown municipality when the day is globally blocked', function (): void {
    $candidates = [candidateAt('ship-a', -16.5)];
    $provider = new BlackoutAwarePendingDeliveryProvider(
        innerPending($candidates),
        fakeMunicipalityLocator([]), // ningún municipio resuelto => alcance global
        blockQueryBus(['' => true]), // bloqueo global
    );

    expect($provider->pendingFor(PlanDate::fromString('2026-11-20')))->toBe([]);
});

it('keeps candidates with unknown municipality when there is no global block', function (): void {
    $candidates = [candidateAt('ship-a', -16.5)];
    $provider = new BlackoutAwarePendingDeliveryProvider(
        innerPending($candidates),
        fakeMunicipalityLocator([]),
        blockQueryBus([]),
    );

    expect($provider->pendingFor(PlanDate::fromString('2026-11-20')))->toHaveCount(1);
});
