<?php

declare(strict_types=1);

namespace Commerce\Accounts\Infrastructure\Providers;

use Commerce\Accounts\Application\Command\ActivateAccount\ActivateAccount;
use Commerce\Accounts\Application\Command\ActivateAccount\ActivateAccountHandler;
use Commerce\Accounts\Application\Command\AddAddress\AddAddress;
use Commerce\Accounts\Application\Command\AddAddress\AddAddressHandler;
use Commerce\Accounts\Application\Command\RegisterAccount\RegisterAccount;
use Commerce\Accounts\Application\Command\RegisterAccount\RegisterAccountHandler;
use Commerce\Accounts\Application\Command\RemoveAddress\RemoveAddress;
use Commerce\Accounts\Application\Command\RemoveAddress\RemoveAddressHandler;
use Commerce\Accounts\Application\Command\SuspendAccount\SuspendAccount;
use Commerce\Accounts\Application\Command\SuspendAccount\SuspendAccountHandler;
use Commerce\Accounts\Application\Query\GetAccount\GetAccount;
use Commerce\Accounts\Application\Query\GetAccount\GetAccountHandler;
use Commerce\Accounts\Application\Query\GetAccountByUser\GetAccountByUser;
use Commerce\Accounts\Application\Query\GetAccountByUser\GetAccountByUserHandler;
use Commerce\Accounts\Application\Query\ListAccounts\ListAccounts;
use Commerce\Accounts\Application\Query\ListAccounts\ListAccountsHandler;
use Commerce\Accounts\Domain\Contract\AccountRepository;
use Commerce\Accounts\Infrastructure\Persistence\Eloquent\Repository\EloquentAccountRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Accounts (cuentas comerciales: tipo, direcciones, estado comercial).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle el proyecto. El núcleo SharedServiceProvider va primero.
 */
final class AccountsServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        RegisterAccount::class => RegisterAccountHandler::class,
        AddAddress::class => AddAddressHandler::class,
        RemoveAddress::class => RemoveAddressHandler::class,
        SuspendAccount::class => SuspendAccountHandler::class,
        ActivateAccount::class => ActivateAccountHandler::class,
        GetAccount::class => GetAccountHandler::class,
        GetAccountByUser::class => GetAccountByUserHandler::class,
        ListAccounts::class => ListAccountsHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(AccountRepository::class, EloquentAccountRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
