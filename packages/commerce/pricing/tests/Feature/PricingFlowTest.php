<?php

declare(strict_types=1);

use Commerce\Pricing\Application\Command\ActivatePriceList\ActivatePriceList;
use Commerce\Pricing\Application\Command\ArchivePriceList\ArchivePriceList;
use Commerce\Pricing\Application\Command\CreatePriceList\CreatePriceList;
use Commerce\Pricing\Application\Command\SetPrice\SetPrice;
use Commerce\Pricing\Application\Query\GetPrice\GetPrice;
use Commerce\Pricing\Application\Query\ListPriceLists\ListPriceLists;
use Commerce\Pricing\Application\Query\ListPrices\ListPrices;
use Commerce\Pricing\Application\ReadModel\PriceListView;
use Commerce\Pricing\Application\ReadModel\PriceQuote;
use Commerce\Pricing\Application\ReadModel\PriceView;
use Commerce\Pricing\Domain\Exception\PricingException;
use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model\PriceListRecord;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Stack completo del contexto Pricing contra logistics_test: command → handler → repositorio + outbox,
 * y las consultas CQRS (incluida la descomposición tributaria de GetPrice). Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function pricingCreateListViaBus(CommandBus $bus, string $name, string $channel = 'retail'): string
{
    $bus->dispatch(new CreatePriceList(name: $name, channel: $channel, currency: 'BOB'));

    /** @var PriceListRecord $record */
    $record = PriceListRecord::query()->where('name', $name)->firstOrFail();

    return (string) $record->getKey();
}

it('crea una lista de precios y encola el evento en el outbox', function (): void {
    $id = pricingCreateListViaBus($this->commands, 'Retail Bolivia');

    $this->assertDatabaseHas('commerce_price_lists', [
        'id' => $id,
        'name' => 'Retail Bolivia',
        'channel' => 'retail',
        'status' => 'draft',
    ]);

    expect(DB::table('outbox_messages')->where('event_name', 'pricing.price_list_created')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('fija precios idempotentes y los lista', function (): void {
    $id = pricingCreateListViaBus($this->commands, 'Retail Idempotente');

    $this->commands->dispatch(new SetPrice(priceListId: $id, sku: 'BEL-TV-55', grossAmountCents: 450000));
    // Segundo set del mismo SKU: actualiza, no duplica.
    $this->commands->dispatch(new SetPrice(priceListId: $id, sku: 'BEL-TV-55', grossAmountCents: 460000));

    expect(PriceListRecord::query()->findOrFail($id)->items()->count())->toBe(1);

    /** @var list<PriceView> $prices */
    $prices = $this->queries->ask(new ListPrices(priceListId: $id));
    expect($prices)->toHaveCount(1)
        ->and($prices[0]->toArray()['gross_amount_cents'])->toBe(460000);
});

it('resuelve GetPrice contra la lista activa del canal con impuestos de Bolivia', function (): void {
    $id = pricingCreateListViaBus($this->commands, 'Retail Quote');
    $this->commands->dispatch(new SetPrice(priceListId: $id, sku: 'BEL-TV-55', grossAmountCents: 450000));
    $this->commands->dispatch(new ActivatePriceList(priceListId: $id));

    /** @var PriceQuote|null $quote */
    $quote = $this->queries->ask(new GetPrice(sku: 'bel-tv-55', channel: 'retail'));

    expect($quote)->not->toBeNull();
    $data = $quote->toArray();
    // 4500,00 BOB con IVA 13 % incluido: IVA 585,00 / neto 3915,00 ; IT 3 % = 135,00.
    expect($data)->toBe([
        'sku' => 'BEL-TV-55',
        'channel' => 'retail',
        'net_cents' => 391500,
        'tax_cents' => 58500,
        'gross_cents' => 450000,
        'currency' => 'BOB',
        'tax_breakdown' => [
            'iva_cents' => 58500,
            'iva_basis_points' => 1300,
            'it_cents' => 13500,
            'it_basis_points' => 300,
        ],
    ]);
});

it('GetPrice devuelve null si no hay lista activa para el canal', function (): void {
    $id = pricingCreateListViaBus($this->commands, 'Retail Draft', 'retail');
    $this->commands->dispatch(new SetPrice(priceListId: $id, sku: 'ONLY-DRAFT', grossAmountCents: 10000));

    // La lista existe pero sigue en Draft: no resuelve.
    expect($this->queries->ask(new GetPrice(sku: 'ONLY-DRAFT', channel: 'retail')))->toBeNull();
});

it('lista las listas de precios con filtro por canal', function (): void {
    pricingCreateListViaBus($this->commands, 'Retail Filtro', 'retail');
    pricingCreateListViaBus($this->commands, 'B2B Filtro', 'b2b');

    /** @var list<PriceListView> $b2b */
    $b2b = $this->queries->ask(new ListPriceLists(channel: 'b2b'));
    $names = array_map(static fn (PriceListView $v): string => $v->name, $b2b);

    expect($names)->toContain('B2B Filtro')
        ->and($names)->not->toContain('Retail Filtro');
});

it('una lista archivada no admite nuevos precios', function (): void {
    $id = pricingCreateListViaBus($this->commands, 'Retail Archivada');
    $this->commands->dispatch(new ArchivePriceList(priceListId: $id));

    expect(fn () => $this->commands->dispatch(new SetPrice(priceListId: $id, sku: 'X-1', grossAmountCents: 1000)))
        ->toThrow(PricingException::class);
});

it('falla al operar sobre una lista inexistente', function (): void {
    expect(fn () => $this->commands->dispatch(new ActivatePriceList(priceListId: '01931100-0000-7000-8000-0000000fffff')))
        ->toThrow(PricingException::class);
});
