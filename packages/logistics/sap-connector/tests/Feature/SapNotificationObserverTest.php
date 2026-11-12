<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Logistics\Outbox\Application\RelayOutbox\Outcome;
use Logistics\Outbox\Application\RelayOutbox\ProcessOutboxMessage;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\SapConnector\Application\Notification\RecordSapNotification;
use Logistics\SapConnector\Infrastructure\Outbox\SapNotificationRecorder;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\SapNotificationRecord;
use Logistics\SapConnector\Infrastructure\Queue\SendSapNotificationJob;
use Logistics\Shared\Domain\Event\BaseDomainEvent;
use Logistics\Shared\Domain\Event\DomainEvent;

/**
 * Fallo simulado del caso de uso del observador SAP (p. ej. la tabla de avisos no disponible).
 */
final class SapObserverRecorderUnavailable extends RuntimeException {}

/**
 * Avisos fire-and-forget a SAP de punta a punta contra PostgreSQL (logistics_test): el observador del
 * outbox registra el aviso (idempotente por event_id), el job de la cola `sap` lo envía por el cliente
 * HTTP real contra Http::fake, guarda la respuesta cruda y, si SAP falla, reintenta hasta 3 veces y se
 * detiene. Nada de esto altera el procesamiento del mensaje del outbox.
 *
 * Se usa `invoicing.invoice_issued` (en el catálogo, sin reactor propio: cae al dispatcher Null) para
 * no disparar reactores de otros contextos.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    config()->set('logistics-sap-connector.sap.enabled', true);
    config()->set('logistics-sap-connector.sap.base_url', 'https://sap.example.test');
    config()->set('logistics-sap-connector.sap.events_path', '/events');
    config()->set('logistics-sap-connector.notifications.events', ['invoicing.invoice_issued']);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Mensaje de outbox de un hecho avisable, persistido en el outbox como lo dejaría RecordDomainEvents.
 */
function sapObservedMessage(string $eventName = 'invoicing.invoice_issued'): OutboxMessage
{
    $event = new readonly class('INV-AGG-1', $eventName) extends BaseDomainEvent
    {
        public function __construct(string $aggregateId, private string $name)
        {
            parent::__construct($aggregateId);
        }

        public function eventName(): string
        {
            return $this->name;
        }

        public function payload(): array
        {
            return ['invoice_number' => 'F-0001', 'total' => '150.00'];
        }
    };
    assert($event instanceof DomainEvent);

    $message = OutboxMessage::fromDomainEvent($event, 'invoicing.invoice', new DateTimeImmutable);
    app(OutboxStore::class)->append($message);

    return $message;
}

it('el observador registra el aviso UNA vez por event_id y encola el envío en la cola sap', function (): void {
    Queue::fake();
    $message = sapObservedMessage();

    $recorder = app(SapNotificationRecorder::class);
    $recorder->observe($message);
    $recorder->observe($message); // reentrega at-least-once del outbox

    expect(SapNotificationRecord::query()->where('event_id', $message->eventId()->value)->count())->toBe(1)
        ->and(SapNotificationRecord::query()->value('status'))->toBe('pending');
    Queue::assertPushedOn('sap', SendSapNotificationJob::class);
    Queue::assertPushed(SendSapNotificationJob::class, 1);
});

it('ignora los eventos que no están en la lista configurada', function (): void {
    Queue::fake();
    $message = sapObservedMessage('invoicing.invoice_voided');

    app(SapNotificationRecorder::class)->observe($message);

    expect(SapNotificationRecord::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('con el conector apagado registra skipped y no hace HTTP', function (): void {
    Http::fake();
    config()->set('logistics-sap-connector.sap.enabled', false);
    $message = sapObservedMessage();

    app(SapNotificationRecorder::class)->observe($message);
    $this->artisan('logistics:sap:retry-notifications')->assertSuccessful();

    expect(SapNotificationRecord::query()->value('status'))->toBe('skipped');
    Http::assertNothingSent();
});

it('sin base_url registra skipped y no hace HTTP', function (): void {
    Http::fake();
    config()->set('logistics-sap-connector.sap.base_url', '');
    $message = sapObservedMessage();

    app(SapNotificationRecorder::class)->observe($message);

    expect(SapNotificationRecord::query()->value('status'))->toBe('skipped');
    Http::assertNothingSent();
});

it('envía el aviso con el contrato acordado, marca sent y guarda la respuesta cruda de SAP', function (): void {
    Http::fake(['https://sap.example.test/events' => Http::response(['received' => true], 202)]);
    $message = sapObservedMessage();

    // Cola sync en tests: el job de la cola `sap` corre en línea tras registrar el aviso.
    app(SapNotificationRecorder::class)->observe($message);

    $row = SapNotificationRecord::query()->where('event_id', $message->eventId()->value)->firstOrFail();
    expect($row->status)->toBe('sent')
        ->and($row->attempts)->toBe(1)
        ->and($row->response_status)->toBe(202)
        ->and($row->response_body)->toContain('received')
        ->and($row->sent_at)->not->toBeNull();

    Http::assertSent(static function (Request $request) use ($message): bool {
        /** @var array<string, mixed> $body */
        $body = $request->data();
        // El cliente manda el payload como `(object)` y `data()` devuelve ese stdClass tal cual: se castea a
        // array y se ordena por clave, porque el orden es irrelevante en el contrato con SAP.
        /** @var array<string, mixed> $payload */
        $payload = (array) ($body['payload'] ?? []);
        ksort($payload);

        return $request->method() === 'POST'
            && $request->url() === 'https://sap.example.test/events'
            && $request->header('Idempotency-Key') === [$message->eventId()->value]
            && $body['event_id'] === $message->eventId()->value
            && $body['event_name'] === 'invoicing.invoice_issued'
            && $body['aggregate_id'] === 'INV-AGG-1'
            && is_string($body['occurred_at'])
            && $payload === ['invoice_number' => 'F-0001', 'total' => '150.00'];
    });
});

it('si SAP falla nunca lanza, reintenta con backoff y se detiene a los 3 intentos', function (): void {
    Http::fake(['https://sap.example.test/events' => Http::response('SAP down', 500)]);
    $message = sapObservedMessage();

    // Intento 1: el job del registro.
    app(SapNotificationRecorder::class)->observe($message);

    // Intentos 2 y 3: el barrido del scheduler, con el backoff ya vencido (se adelanta en la fila).
    for ($retry = 1; $retry <= 2; $retry++) {
        SapNotificationRecord::query()->update(['next_attempt_at' => (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:sP')]);
        $this->artisan('logistics:sap:retry-notifications')->assertSuccessful();
    }

    // Agotado: otra corrida del barrido ya no lo toma.
    SapNotificationRecord::query()->update(['next_attempt_at' => (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:sP')]);
    $this->artisan('logistics:sap:retry-notifications')->assertSuccessful();

    $row = SapNotificationRecord::query()->firstOrFail();
    expect($row->status)->toBe('failed')
        ->and($row->attempts)->toBe(3)
        ->and($row->response_status)->toBe(500)
        ->and($row->response_body)->toBe('SAP down')
        ->and($row->last_error)->toContain('500');
    Http::assertSentCount(3);
});

it('una excepción del observador SAP no altera el procesamiento del mensaje del outbox', function (): void {
    // El caso de uso del observador explota (p. ej. base caída para esa tabla).
    app()->bind(RecordSapNotification::class, static function (): never {
        throw new SapObserverRecorderUnavailable('sap_notifications unavailable');
    });
    $message = sapObservedMessage();

    $outcome = app(ProcessOutboxMessage::class)->process($message, new DateTimeImmutable);

    expect($outcome)->toBe(Outcome::Dispatched)
        ->and(OutboxMessageRecord::query()->whereKey($message->id()->toString())->value('status'))->toBe('dispatched')
        ->and(SapNotificationRecord::query()->count())->toBe(0);
});
