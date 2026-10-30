<?php

declare(strict_types=1);

use Commerce\Accounts\Application\Command\AddAddress\AddAddress;
use Commerce\Accounts\Application\Command\RegisterAccount\RegisterAccount;
use Commerce\Accounts\Application\Query\GetAccount\GetAccount;
use Commerce\Accounts\Application\Query\GetAccountByUser\GetAccountByUser;
use Commerce\Accounts\Application\Query\ListAccounts\ListAccounts;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Nombre del titular reutilizado en el circuito; nombre único para evitar colisión global entre tests Pest.
const ACCOUNTS_FLOW_HOLDER = 'Juan Pérez';

/**
 * Circuito de cuentas contra PostgreSQL real (logistics_test, RefreshDatabase): registrar una cuenta,
 * agregarle dos direcciones y consultarla por las queries. Atraviesa CommandBus → handler → repositorio
 * → mapper → persistencia → QueryBus → ReadModel.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('registra una cuenta, agrega dos direcciones y la consulta', function (): void {
    $userId = Uuid::generate()->value;

    $this->commands->dispatch(new RegisterAccount('person', $userId, ACCOUNTS_FLOW_HOLDER));

    $accounts = $this->queries->ask(new ListAccounts('person'));
    expect($accounts)->toHaveCount(1);
    $accountId = $accounts[0]->id;

    $this->commands->dispatch(new AddAddress($accountId, 'Casa', ACCOUNTS_FLOW_HOLDER, 'Av. Siempre Viva 123', 'La Paz', isDefault: true));
    $this->commands->dispatch(new AddAddress($accountId, 'Oficina', ACCOUNTS_FLOW_HOLDER, 'Calle Comercio 50', 'La Paz', isDefault: true));

    $view = $this->queries->ask(new GetAccount($accountId));
    expect($view)->not->toBeNull()
        ->and($view->type)->toBe('person')
        ->and($view->status)->toBe('active')
        ->and($view->userId)->toBe($userId)
        ->and($view->addresses)->toHaveCount(2);

    // A lo sumo una dirección predeterminada: la última marcada.
    $defaults = array_values(array_filter($view->addresses, static fn (array $a): bool => $a['is_default']));
    expect($defaults)->toHaveCount(1)
        ->and($defaults[0]['label'])->toBe('Oficina');

    $byUser = $this->queries->ask(new GetAccountByUser($userId));
    expect($byUser)->not->toBeNull()
        ->and($byUser->id)->toBe($accountId);
});

it('registra una cuenta de empresa con NIT', function (): void {
    $this->commands->dispatch(new RegisterAccount('company', Uuid::generate()->value, 'Dismac SRL', '1023456789'));

    $companies = $this->queries->ask(new ListAccounts('company'));
    expect($companies)->toHaveCount(1)
        ->and($companies[0]->taxId)->toBe('1023456789');
});
