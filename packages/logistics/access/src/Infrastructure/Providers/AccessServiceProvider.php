<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Logistics\Access\Application\Command\AssignRoleToUser\AssignRoleToUser;
use Logistics\Access\Application\Command\AssignRoleToUser\AssignRoleToUserHandler;
use Logistics\Access\Application\Command\ChangeUserPassword\ChangeUserPassword;
use Logistics\Access\Application\Command\ChangeUserPassword\ChangeUserPasswordHandler;
use Logistics\Access\Application\Command\DefineRole\DefineRole;
use Logistics\Access\Application\Command\DefineRole\DefineRoleHandler;
use Logistics\Access\Application\Command\DeleteRole\DeleteRole;
use Logistics\Access\Application\Command\DeleteRole\DeleteRoleHandler;
use Logistics\Access\Application\Command\GrantPermissionToRole\GrantPermissionToRole;
use Logistics\Access\Application\Command\GrantPermissionToRole\GrantPermissionToRoleHandler;
use Logistics\Access\Application\Command\ReactivateUser\ReactivateUser;
use Logistics\Access\Application\Command\ReactivateUser\ReactivateUserHandler;
use Logistics\Access\Application\Command\RecordTokenUsage\RecordTokenUsage;
use Logistics\Access\Application\Command\RecordTokenUsage\RecordTokenUsageHandler;
use Logistics\Access\Application\Command\RecordUserLogin\RecordUserLogin;
use Logistics\Access\Application\Command\RecordUserLogin\RecordUserLoginHandler;
use Logistics\Access\Application\Command\RegisterPermission\RegisterPermission;
use Logistics\Access\Application\Command\RegisterPermission\RegisterPermissionHandler;
use Logistics\Access\Application\Command\RegisterUser\RegisterUser;
use Logistics\Access\Application\Command\RegisterUser\RegisterUserHandler;
use Logistics\Access\Application\Command\RemoveRoleFromUser\RemoveRoleFromUser;
use Logistics\Access\Application\Command\RemoveRoleFromUser\RemoveRoleFromUserHandler;
use Logistics\Access\Application\Command\RenameRole\RenameRole;
use Logistics\Access\Application\Command\RenameRole\RenameRoleHandler;
use Logistics\Access\Application\Command\RevokeIntegrationToken\RevokeIntegrationToken;
use Logistics\Access\Application\Command\RevokeIntegrationToken\RevokeIntegrationTokenHandler;
use Logistics\Access\Application\Command\RevokePermissionFromRole\RevokePermissionFromRole;
use Logistics\Access\Application\Command\RevokePermissionFromRole\RevokePermissionFromRoleHandler;
use Logistics\Access\Application\Command\SetRolePermissions\SetRolePermissions;
use Logistics\Access\Application\Command\SetRolePermissions\SetRolePermissionsHandler;
use Logistics\Access\Application\Command\SetTokenPermissions\SetTokenPermissions;
use Logistics\Access\Application\Command\SetTokenPermissions\SetTokenPermissionsHandler;
use Logistics\Access\Application\Command\SuspendUser\SuspendUser;
use Logistics\Access\Application\Command\SuspendUser\SuspendUserHandler;
use Logistics\Access\Application\Query\AuthenticateIntegrationToken\AuthenticateIntegrationToken;
use Logistics\Access\Application\Query\AuthenticateIntegrationToken\AuthenticateIntegrationTokenHandler;
use Logistics\Access\Application\Query\AuthenticateUser\AuthenticateUser;
use Logistics\Access\Application\Query\AuthenticateUser\AuthenticateUserHandler;
use Logistics\Access\Application\Query\GetIntegrationToken\GetIntegrationToken;
use Logistics\Access\Application\Query\GetIntegrationToken\GetIntegrationTokenHandler;
use Logistics\Access\Application\Query\GetRole\GetRole;
use Logistics\Access\Application\Query\GetRole\GetRoleHandler;
use Logistics\Access\Application\Query\GetUser\GetUser;
use Logistics\Access\Application\Query\GetUser\GetUserHandler;
use Logistics\Access\Application\Query\ListIntegrationTokens\ListIntegrationTokens;
use Logistics\Access\Application\Query\ListIntegrationTokens\ListIntegrationTokensHandler;
use Logistics\Access\Application\Query\ListPermissions\ListPermissions;
use Logistics\Access\Application\Query\ListPermissions\ListPermissionsHandler;
use Logistics\Access\Application\Query\ListRoles\ListRoles;
use Logistics\Access\Application\Query\ListRoles\ListRolesHandler;
use Logistics\Access\Application\Query\ListUsers\ListUsers;
use Logistics\Access\Application\Query\ListUsers\ListUsersHandler;
use Logistics\Access\Domain\Contract\IntegrationTokenRepository;
use Logistics\Access\Domain\Contract\PasswordHasher;
use Logistics\Access\Domain\Contract\PermissionRepository;
use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\Contract\SecretGenerator;
use Logistics\Access\Domain\Contract\TokenHasher;
use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Repository\EloquentIntegrationTokenRepository;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Repository\EloquentPermissionRepository;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Repository\EloquentRoleRepository;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Repository\EloquentUserRepository;
use Logistics\Access\Infrastructure\Security\BcryptPasswordHasher;
use Logistics\Access\Infrastructure\Security\RandomSecretGenerator;
use Logistics\Access\Infrastructure\Security\Sha256TokenHasher;
use Logistics\Access\Presentation\Http\Middleware\AuthenticateIntegrationToken as AuthenticateIntegrationTokenMiddleware;
use Logistics\Access\Presentation\Http\Middleware\EnsurePermission;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Access (identidad, RBAC dinámico y tokens de integración).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de carga lo controle el proyecto. Es la autoridad de autorización de la plataforma, pero convive
 * de forma ADITIVA con el auth estático heredado (app/Enums/Role, Sanctum): no lo reemplaza.
 */
final class AccessServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        // Catálogo de permisos y roles dinámicos (RBAC).
        RegisterPermission::class => RegisterPermissionHandler::class,
        DefineRole::class => DefineRoleHandler::class,
        RenameRole::class => RenameRoleHandler::class,
        SetRolePermissions::class => SetRolePermissionsHandler::class,
        GrantPermissionToRole::class => GrantPermissionToRoleHandler::class,
        RevokePermissionFromRole::class => RevokePermissionFromRoleHandler::class,
        DeleteRole::class => DeleteRoleHandler::class,
        ListPermissions::class => ListPermissionsHandler::class,
        ListRoles::class => ListRolesHandler::class,
        GetRole::class => GetRoleHandler::class,

        // Usuarios y autenticación.
        RegisterUser::class => RegisterUserHandler::class,
        ChangeUserPassword::class => ChangeUserPasswordHandler::class,
        SuspendUser::class => SuspendUserHandler::class,
        ReactivateUser::class => ReactivateUserHandler::class,
        AssignRoleToUser::class => AssignRoleToUserHandler::class,
        RemoveRoleFromUser::class => RemoveRoleFromUserHandler::class,
        RecordUserLogin::class => RecordUserLoginHandler::class,
        ListUsers::class => ListUsersHandler::class,
        GetUser::class => GetUserHandler::class,
        AuthenticateUser::class => AuthenticateUserHandler::class,

        // Tokens de integración (permisos seleccionables).
        RevokeIntegrationToken::class => RevokeIntegrationTokenHandler::class,
        SetTokenPermissions::class => SetTokenPermissionsHandler::class,
        RecordTokenUsage::class => RecordTokenUsageHandler::class,
        ListIntegrationTokens::class => ListIntegrationTokensHandler::class,
        GetIntegrationToken::class => GetIntegrationTokenHandler::class,
        AuthenticateIntegrationToken::class => AuthenticateIntegrationTokenHandler::class,
    ];

    public function register(): void
    {
        // Repositorios: puerto de dominio → implementación Eloquent.
        $this->app->bind(PermissionRepository::class, EloquentPermissionRepository::class);
        $this->app->bind(RoleRepository::class, EloquentRoleRepository::class);
        $this->app->bind(UserRepository::class, EloquentUserRepository::class);
        $this->app->bind(IntegrationTokenRepository::class, EloquentIntegrationTokenRepository::class);

        // Puertos de seguridad: hashing y generación de secretos (detalle de infraestructura).
        $this->app->bind(
            PasswordHasher::class,
            static fn ($app): BcryptPasswordHasher => new BcryptPasswordHasher($app->make('hash')),
        );
        $this->app->bind(TokenHasher::class, Sha256TokenHasher::class);
        $this->app->bind(SecretGenerator::class, RandomSecretGenerator::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        // Alias de middleware del contexto: autenticación por token de integración y guardia de permiso.
        // Carril ADITIVO: no pisa los alias heredados (service.auth, require.ability). Se usan así:
        //   ->middleware(['access.token', 'access.permission:shipments.read'])
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('access.token', AuthenticateIntegrationTokenMiddleware::class);
        $router->aliasMiddleware('access.permission', EnsurePermission::class);
    }
}
