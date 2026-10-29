<?php

declare(strict_types=1);

use Commerce\Accounts\Domain\Event\AccountActivated;
use Commerce\Accounts\Domain\Event\AccountRegistered;
use Commerce\Accounts\Domain\Event\AccountSuspended;
use Commerce\Accounts\Domain\Event\AddressAdded;
use Commerce\Accounts\Domain\Exception\AccountsException;
use Commerce\Accounts\Domain\Model\Account;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Commerce\Accounts\Domain\ValueObject\AccountStatus;
use Commerce\Accounts\Domain\ValueObject\AccountType;
use Commerce\Accounts\Domain\ValueObject\Address;
use Logistics\Shared\Domain\ValueObject\Uuid;

function accountsRegisterPerson(): Account
{
    return Account::register(
        AccountId::generate(),
        AccountType::Person,
        Uuid::generate(),
        'Juan Pérez',
    );
}

it('register crea una cuenta activa y graba AccountRegistered', function (): void {
    $account = accountsRegisterPerson();

    expect($account->status())->toBe(AccountStatus::Active);

    $events = $account->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AccountRegistered::class);
    expect($events[0]->payload()['type'])->toBe('person');
});

it('register de empresa sin NIT falla', function (): void {
    expect(fn () => Account::register(
        AccountId::generate(),
        AccountType::Company,
        Uuid::generate(),
        'Dismac SRL',
    ))->toThrow(AccountsException::class);
});

it('register de empresa con NIT funciona', function (): void {
    $account = Account::register(
        AccountId::generate(),
        AccountType::Company,
        Uuid::generate(),
        'Dismac SRL',
        '1023456789',
    );

    expect($account->taxId())->toBe('1023456789')
        ->and($account->type())->toBe(AccountType::Company);
});

it('register rechaza nombre vacío', function (): void {
    expect(fn () => Account::register(AccountId::generate(), AccountType::Person, Uuid::generate(), '   '))
        ->toThrow(AccountsException::class);
});

it('addAddress con isDefault desmarca a las demás', function (): void {
    $account = accountsRegisterPerson();
    $account->releaseEvents();

    $account->addAddress(Address::of('Casa', 'Juan', 'Calle 1', null, 'La Paz', null, null, true));
    $account->addAddress(Address::of('Oficina', 'Juan', 'Calle 2', null, 'La Paz', null, null, true));

    $addresses = $account->addresses();
    expect($addresses)->toHaveCount(2)
        ->and($addresses[0]->isDefault)->toBeFalse()
        ->and($addresses[1]->isDefault)->toBeTrue()
        ->and($account->defaultAddress()?->label)->toBe('Oficina');

    $events = $account->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(AddressAdded::class);
});

it('addAddress rechaza etiqueta duplicada', function (): void {
    $account = accountsRegisterPerson();
    $account->addAddress(Address::of('Casa', 'Juan', 'Calle 1', null, 'La Paz', null, null));

    expect(fn () => $account->addAddress(Address::of('Casa', 'Juan', 'Calle 9', null, 'La Paz', null, null)))
        ->toThrow(AccountsException::class);

    expect($account->addresses())->toHaveCount(1);
});

it('removeAddress quita por etiqueta y es idempotente', function (): void {
    $account = accountsRegisterPerson();
    $account->addAddress(Address::of('Casa', 'Juan', 'Calle 1', null, 'La Paz', null, null));
    $account->releaseEvents();

    $account->removeAddress('Casa');
    expect($account->addresses())->toHaveCount(0)
        ->and($account->releaseEvents())->toHaveCount(1);

    // Quitar algo inexistente no graba evento.
    $account->removeAddress('Casa');
    expect($account->releaseEvents())->toHaveCount(0);
});

it('suspend es idempotente y graba AccountSuspended una sola vez', function (): void {
    $account = accountsRegisterPerson();
    $account->releaseEvents();

    $account->suspend();
    expect($account->status())->toBe(AccountStatus::Suspended);

    $events = $account->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AccountSuspended::class);

    $account->suspend();
    expect($account->releaseEvents())->toHaveCount(0);
});

it('activate es idempotente y graba AccountActivated una sola vez', function (): void {
    $account = accountsRegisterPerson();
    $account->suspend();
    $account->releaseEvents();

    $account->activate();
    expect($account->status())->toBe(AccountStatus::Active);

    $events = $account->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AccountActivated::class);

    $account->activate();
    expect($account->releaseEvents())->toHaveCount(0);
});
