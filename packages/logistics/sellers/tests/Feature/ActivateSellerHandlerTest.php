<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Sellers\Application\Command\ActivateSeller\ActivateSeller;
use Logistics\Sellers\Database\Factories\SellerFactory;
use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura del handler ActivateSeller contra logistics_test: la rama de "no existe el seller" (falla) y
 * la activación efectiva que encola el evento en el outbox. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('falla al activar un seller inexistente', function (): void {
    expect(fn () => $this->commands->dispatch(new ActivateSeller(
        sellerId: SellerId::generate()->toString(),
    )))->toThrow(SellersException::class);
});

it('activa un seller en onboarding y encola el evento en el outbox', function (): void {
    $seller = SellerFactory::new()->create(['tax_id' => 'NIT-ACT-0001']);

    $this->commands->dispatch(new ActivateSeller(sellerId: (string) $seller->getKey()));

    expect(DB::table('sellers')->where('id', $seller->getKey())->value('status'))->toBe('active')
        ->and(DB::table('outbox_messages')->where('event_name', 'sellers.seller_activated')->count())
        ->toBeGreaterThanOrEqual(1);
});
