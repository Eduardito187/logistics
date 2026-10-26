<?php

declare(strict_types=1);

use Logistics\Fleet\Application\Command\AlertExpiringDocuments\AlertExpiringDocuments;
use Logistics\Fleet\Application\Command\AlertExpiringDocuments\AlertExpiringDocumentsHandler;
use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\Model\FleetDocument;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentId;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;

// --- Fakes en memoria (PHP puro, sin Laravel) -------------------------------------------------

function fakeDocument(string $expiresOn, string $ownerId = 'veh-1'): FleetDocument
{
    return FleetDocument::register(
        FleetDocumentId::generate(),
        DocumentOwnerType::Vehicle,
        $ownerId,
        DocumentExpiry::of('soat', new DateTimeImmutable($expiresOn)),
    );
}

/**
 * @param list<FleetDocument> $documents
 */
function makeAlertHandler(array $documents, int $policyDays, OutboxStore $store): AlertExpiringDocumentsHandler
{
    $emptyVehicles = new class implements VehicleRepository
    {
        public function nextIdentity(): VehicleId
        {
            throw new LogicException('no usado');
        }

        public function save(Vehicle $vehicle): void
        {
            throw new LogicException('no usado');
        }

        public function findById(VehicleId $id): ?Vehicle
        {
            return null;
        }

        public function all(): array
        {
            return [];
        }
    };

    $emptyDrivers = new class implements DriverRepository
    {
        public function nextIdentity(): DriverId
        {
            throw new LogicException('no usado');
        }

        public function save(Driver $driver): void
        {
            throw new LogicException('no usado');
        }

        public function findById(DriverId $id): ?Driver
        {
            return null;
        }

        public function all(): array
        {
            return [];
        }
    };

    $emptyHelpers = new class implements HelperRepository
    {
        public function nextIdentity(): HelperId
        {
            throw new LogicException('no usado');
        }

        public function save(Helper $helper): void
        {
            throw new LogicException('no usado');
        }

        public function findById(HelperId $id): ?Helper
        {
            return null;
        }

        public function findByIds(array $ids): array
        {
            return [];
        }

        public function all(): array
        {
            return [];
        }
    };

    $docs = new class($documents) implements DocumentRepository
    {
        /** @param list<FleetDocument> $documents */
        public function __construct(private array $documents) {}

        public function nextIdentity(): FleetDocumentId
        {
            throw new LogicException('no usado');
        }

        public function save(FleetDocument $document): void
        {
            throw new LogicException('no usado');
        }

        public function findById(FleetDocumentId $id): ?FleetDocument
        {
            return null;
        }

        public function all(): array
        {
            return $this->documents;
        }
    };

    $policy = new class($policyDays) implements DocumentExpiryPolicy
    {
        public function __construct(private int $days) {}

        public function alertWindowDays(): int
        {
            return $this->days;
        }
    };

    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13');
        }
    };

    return new AlertExpiringDocumentsHandler(
        $emptyVehicles,
        $emptyDrivers,
        $emptyHelpers,
        $docs,
        $policy,
        new RecordDomainEvents($store, $clock),
        $clock,
    );
}

/**
 * OutboxStore que solo acumula lo que se le agrega (los otros métodos no se usan en estos tests).
 */
function collectingStore(): OutboxStore
{
    return new class implements OutboxStore
    {
        /** @var list<OutboxMessage> */
        public array $appended = [];

        public function append(OutboxMessage $message): void
        {
            $this->appended[] = $message;
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };
}

// --- Tests ------------------------------------------------------------------------------------

it('emite el evento de alerta con los documentos dentro de la ventana y cuenta vencidos / por vencer', function (): void {
    $store = collectingStore();
    $handler = makeAlertHandler(
        [
            fakeDocument('2026-09-01'), // vencido
            fakeDocument('2026-10-20'), // por vencer (dentro de 30 días)
            fakeDocument('2027-06-01'), // fuera de la ventana
        ],
        30,
        $store,
    );

    $handler->handle(new AlertExpiringDocuments('2026-10-13'));

    expect($handler->lastExpired())->toBe(1)
        ->and($handler->lastExpiring())->toBe(1)
        ->and($handler->lastTotal())->toBe(2)
        ->and($handler->lastEmitted())->toBeTrue()
        ->and($store->appended)->toHaveCount(1);

    $message = $store->appended[0];
    expect($message->eventName())->toBe('fleet.documents_expiry_alerted');

    $payload = $message->payload();
    expect($payload['expired_count'])->toBe(1)
        ->and($payload['expiring_count'])->toBe(1)
        ->and($payload['total_count'])->toBe(2)
        ->and($payload['threshold_days'])->toBe(30)
        ->and($payload['reference_date'])->toBe('2026-10-13')
        ->and($payload['documents'])->toHaveCount(2);
});

it('no emite ningún evento cuando no hay documentos dentro de la ventana', function (): void {
    $store = collectingStore();
    $handler = makeAlertHandler([fakeDocument('2027-06-01')], 30, $store);

    $handler->handle(new AlertExpiringDocuments('2026-10-13'));

    expect($handler->lastTotal())->toBe(0)
        ->and($handler->lastEmitted())->toBeFalse()
        ->and($store->appended)->toHaveCount(0);
});

it('usa la ventana de la política cuando el comando no fuerza los días', function (): void {
    $store = collectingStore();
    // Política de 7 días: el documento que vence en 10 días queda FUERA.
    $handler = makeAlertHandler([fakeDocument('2026-10-23')], 7, $store);

    $handler->handle(new AlertExpiringDocuments('2026-10-13'));

    expect($handler->lastTotal())->toBe(0)
        ->and($store->appended)->toHaveCount(0);

    // Con 30 días (forzados por el comando) el mismo documento entra.
    $store2 = collectingStore();
    $handler2 = makeAlertHandler([fakeDocument('2026-10-23')], 7, $store2);
    $handler2->handle(new AlertExpiringDocuments('2026-10-13', 30));

    expect($handler2->lastTotal())->toBe(1)
        ->and($store2->appended)->toHaveCount(1);
});

it('clasifica los documentos por umbral: vencido, ≤7 días y ≤30 días', function (): void {
    $store = collectingStore();
    $handler = makeAlertHandler(
        [
            fakeDocument('2026-09-01'), // vencido (ref 2026-10-13)
            fakeDocument('2026-10-18'), // vence en 5 días → ≤7
            fakeDocument('2026-11-05'), // vence en 23 días → ≤30
            fakeDocument('2027-06-01'), // fuera de la ventana
        ],
        30,
        $store,
    );

    $handler->handle(new AlertExpiringDocuments('2026-10-13'));

    expect($handler->lastExpired())->toBe(1)
        ->and($handler->lastDueSoon())->toBe(1)
        ->and($handler->lastUpcoming())->toBe(1)
        ->and($handler->lastExpiring())->toBe(2)
        ->and($handler->lastTotal())->toBe(3);

    $payload = $store->appended[0]->payload();
    expect($payload['expired_count'])->toBe(1)
        ->and($payload['due_soon_count'])->toBe(1)
        ->and($payload['upcoming_count'])->toBe(1)
        ->and($payload['total_count'])->toBe(3);

    // Cada documento del resumen lleva su clasificación de umbral.
    $thresholds = array_map(static fn (array $d): string => $d['threshold'], $payload['documents']);
    expect($thresholds)->toContain('expired')
        ->and($thresholds)->toContain('due_soon')
        ->and($thresholds)->toContain('upcoming');
});

it('es idempotente: el mismo día produce el mismo eventId y distinto día uno diferente', function (): void {
    // Dos corridas del MISMO día de referencia → mismo eventId (el outbox no re-encola la alerta).
    $storeA = collectingStore();
    makeAlertHandler([fakeDocument('2026-09-01')], 30, $storeA)->handle(new AlertExpiringDocuments('2026-10-13'));

    $storeB = collectingStore();
    makeAlertHandler([fakeDocument('2026-09-01')], 30, $storeB)->handle(new AlertExpiringDocuments('2026-10-13'));

    $idDay1RunA = $storeA->appended[0]->eventId()->value;
    $idDay1RunB = $storeB->appended[0]->eventId()->value;

    // Otro día de referencia → eventId distinto (es una alerta nueva).
    $storeC = collectingStore();
    makeAlertHandler([fakeDocument('2026-09-01')], 30, $storeC)->handle(new AlertExpiringDocuments('2026-10-14'));
    $idDay2 = $storeC->appended[0]->eventId()->value;

    expect($idDay1RunA)->toBe($idDay1RunB)
        ->and($idDay1RunA)->not->toBe($idDay2);
});
