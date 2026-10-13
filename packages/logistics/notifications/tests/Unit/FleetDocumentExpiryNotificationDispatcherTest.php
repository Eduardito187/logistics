<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\FleetDocumentsExpiryAlerted;
use Logistics\Notifications\Infrastructure\Dispatch\FleetDocumentExpiryNotificationDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Event\BaseDomainEvent;
use Psr\Log\AbstractLogger;

/**
 * Logger en memoria que solo captura lo registrado (PHP puro, sin Laravel).
 */
function capturingLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
        public array $records = [];

        public function log($level, string | Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }
    };
}

function alertMessage(): OutboxMessage
{
    $event = new FleetDocumentsExpiryAlerted(
        'fleet-document-expiry-2026-10-13',
        '2026-10-13',
        30,
        2,
        1,
        [
            ['owner_type' => 'vehicle', 'owner_id' => 'veh-1', 'owner_label' => 'ABC-123', 'document_type' => 'soat', 'expires_on' => '2026-10-01', 'expired' => true],
        ],
    );

    return OutboxMessage::fromDomainEvent($event, 'fleet.document_expiry', new DateTimeImmutable('2026-10-13'));
}

it('ignora eventos que no son la alerta de vencimientos de flota', function (): void {
    $other = new readonly class('agg-1') extends BaseDomainEvent
    {
        public function eventName(): string
        {
            return 'shipments.shipment_received';
        }

        public function payload(): array
        {
            return [];
        }
    };

    $logger = capturingLogger();
    $dispatcher = new FleetDocumentExpiryNotificationDispatcher($logger, null, 'from@dismac.test', 'Dismac');

    $dispatcher->dispatch(OutboxMessage::fromDomainEvent($other, 'shipments.shipment', new DateTimeImmutable('2026-10-13')));

    expect($logger->records)->toHaveCount(0);
});

it('registra la alerta en el log y no envía correo si no hay email de supervisor', function (): void {
    $logger = capturingLogger();
    $dispatcher = new FleetDocumentExpiryNotificationDispatcher($logger, null, 'from@dismac.test', 'Dismac');

    // Sin email de supervisor: no toca el mailer (no se dispara la fachada Mail), solo loguea.
    $dispatcher->dispatch(alertMessage());

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toBe('Fleet document expiry alert')
        ->and($logger->records[0]['context']['total'])->toBe(3)
        ->and($logger->records[0]['context']['expired'])->toBe(2)
        ->and($logger->records[0]['context']['expiring'])->toBe(1)
        ->and($logger->records[0]['context']['reference_date'])->toBe('2026-10-13');
});
