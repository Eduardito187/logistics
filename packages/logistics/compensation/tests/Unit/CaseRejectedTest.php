<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Event\CaseRejected;

// Evento de dominio: un caso fue rechazado por un aprobador.

it('expone el nombre del evento y su carga', function (): void {
    $event = new CaseRejected('case-1', 'supervisor-1', 'Pedido cancelado por el cliente.');

    expect($event->eventName())->toBe('compensation.case_rejected')
        ->and($event->aggregateId())->toBe('case-1')
        ->and($event->rejectedBy)->toBe('supervisor-1')
        ->and($event->payload())->toBe([
            'rejected_by' => 'supervisor-1',
            'reason' => 'Pedido cancelado por el cliente.',
        ]);
});
