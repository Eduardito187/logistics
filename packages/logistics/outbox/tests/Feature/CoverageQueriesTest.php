<?php

declare(strict_types=1);

use Logistics\Outbox\Application\Query\GetOutboxMessage\GetOutboxMessageQuery;
use Logistics\Outbox\Application\Query\ListOutboxMessages\ListOutboxMessagesQuery;
use Logistics\Outbox\Application\ReadModel\OutboxMessagePage;
use Logistics\Outbox\Application\ReadModel\OutboxMessageView;
use Logistics\Outbox\Domain\Exception\OutboxException;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cobertura del lado de lectura CQRS del outbox: ListOutboxMessages (paginado + filtro por estado) y
 * GetOutboxMessage, resueltos por el QueryBus sobre mensajes reales. Patrón C del brief de cobertura.
 */
function outboxQueryBus(): QueryBus
{
    /** @var QueryBus $bus */
    $bus = app(QueryBus::class);

    return $bus;
}

it('lista una página de mensajes del outbox con metadatos de paginación', function (): void {
    OutboxMessageRecord::factory()->count(3)->create();
    OutboxMessageRecord::factory()->count(2)->dispatched()->create();

    /** @var OutboxMessagePage $page */
    $page = outboxQueryBus()->ask(new ListOutboxMessagesQuery(null, 1, 2));

    expect($page)->toBeInstanceOf(OutboxMessagePage::class)
        ->and($page->total)->toBe(5)
        ->and($page->items)->toHaveCount(2)
        ->and($page->items[0])->toBeInstanceOf(OutboxMessageView::class)
        ->and($page->lastPage())->toBe(3)
        ->and($page->toArray())->toHaveKeys(['data', 'meta']);
});

it('filtra la lista del outbox por estado', function (): void {
    OutboxMessageRecord::factory()->count(2)->create();
    OutboxMessageRecord::factory()->count(4)->dispatched()->create();

    /** @var OutboxMessagePage $page */
    $page = outboxQueryBus()->ask(new ListOutboxMessagesQuery(OutboxStatus::Dispatched->value, 1, 50));

    expect($page->total)->toBe(4);
});

it('rechaza un estado de outbox desconocido al listar', function (): void {
    expect(fn () => outboxQueryBus()->ask(new ListOutboxMessagesQuery('inexistente', 1, 50)))
        ->toThrow(OutboxException::class);
});

it('obtiene el detalle de un mensaje por id y devuelve null si no existe', function (): void {
    $record = OutboxMessageRecord::factory()->create();

    /** @var OutboxMessageView|null $found */
    $found = outboxQueryBus()->ask(new GetOutboxMessageQuery((string) $record->getKey()));
    /** @var OutboxMessageView|null $missing */
    $missing = outboxQueryBus()->ask(new GetOutboxMessageQuery('0192ffff-ffff-7fff-8fff-ffffffffffff'));

    expect($found)->toBeInstanceOf(OutboxMessageView::class)
        ->and($found->toArray())->toHaveKey('event_name')
        ->and($missing)->toBeNull();
});
