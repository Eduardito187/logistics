<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Logistics\Compensation\Application\Command\ApproveCompensationCase\ApproveCompensationCase;
use Logistics\Compensation\Application\Command\ApproveCompensationCase\ApproveCompensationCaseHandler;
use Logistics\Compensation\Application\Command\ExpireVouchers\ExpireVouchers;
use Logistics\Compensation\Application\Command\ExpireVouchers\ExpireVouchersHandler;
use Logistics\Compensation\Application\Command\HoldVoucher\HoldVoucher;
use Logistics\Compensation\Application\Command\HoldVoucher\HoldVoucherHandler;
use Logistics\Compensation\Application\Command\IssueVoucher\IssueVoucher;
use Logistics\Compensation\Application\Command\IssueVoucher\IssueVoucherHandler;
use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCaseHandler;
use Logistics\Compensation\Application\Command\RedeemVoucher\RedeemVoucher;
use Logistics\Compensation\Application\Command\RedeemVoucher\RedeemVoucherHandler;
use Logistics\Compensation\Application\Command\RejectCompensationCase\RejectCompensationCase;
use Logistics\Compensation\Application\Command\RejectCompensationCase\RejectCompensationCaseHandler;
use Logistics\Compensation\Application\Command\ReleaseVoucherHold\ReleaseVoucherHold;
use Logistics\Compensation\Application\Command\ReleaseVoucherHold\ReleaseVoucherHoldHandler;
use Logistics\Compensation\Application\Command\RevokeVoucher\RevokeVoucher;
use Logistics\Compensation\Application\Command\RevokeVoucher\RevokeVoucherHandler;
use Logistics\Compensation\Application\Contract\AffectedValueReader;
use Logistics\Compensation\Application\Contract\CustomerContactReader;
use Logistics\Compensation\Application\Contract\PromisedWindowReader;
use Logistics\Compensation\Application\Query\GetCompensationCase\GetCompensationCase;
use Logistics\Compensation\Application\Query\GetCompensationCase\GetCompensationCaseHandler;
use Logistics\Compensation\Application\Query\ValidateVoucher\ValidateVoucher;
use Logistics\Compensation\Application\Query\ValidateVoucher\ValidateVoucherHandler;
use Logistics\Compensation\Domain\Contract\CaseAuditLog;
use Logistics\Compensation\Domain\Contract\CompensationCaseRepository;
use Logistics\Compensation\Domain\Contract\CompensationPolicyProvider;
use Logistics\Compensation\Domain\Contract\VoucherRepository;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\EloquentCaseAuditLog;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Repository\EloquentCompensationCaseRepository;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Repository\EloquentVoucherRepository;
use Logistics\Compensation\Infrastructure\Read\DbAffectedValueReader;
use Logistics\Compensation\Infrastructure\Read\DbCustomerContactReader;
use Logistics\Compensation\Infrastructure\Read\DbPromisedWindowReader;
use Logistics\Compensation\Infrastructure\Settings\SettingsCompensationPolicyProvider;
use Logistics\Compensation\Presentation\Console\ExpireVouchersCommand;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Compensation (vales/cupones por problemas de entrega).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el orden
 * de registro lo controle el proyecto. Depende solo del núcleo compartido, el outbox y settings; se integra
 * con ejecución/tracking por eventos del outbox (reactores), nunca por llamadas directas entre contextos.
 */
final class CompensationServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        OpenCompensationCase::class => OpenCompensationCaseHandler::class,
        ApproveCompensationCase::class => ApproveCompensationCaseHandler::class,
        RejectCompensationCase::class => RejectCompensationCaseHandler::class,
        IssueVoucher::class => IssueVoucherHandler::class,
        RedeemVoucher::class => RedeemVoucherHandler::class,
        HoldVoucher::class => HoldVoucherHandler::class,
        ReleaseVoucherHold::class => ReleaseVoucherHoldHandler::class,
        RevokeVoucher::class => RevokeVoucherHandler::class,
        ExpireVouchers::class => ExpireVouchersHandler::class,
        GetCompensationCase::class => GetCompensationCaseHandler::class,
        ValidateVoucher::class => ValidateVoucherHandler::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-compensation.php', 'logistics-compensation');

        $this->app->bind(CompensationCaseRepository::class, EloquentCompensationCaseRepository::class);
        $this->app->bind(VoucherRepository::class, EloquentVoucherRepository::class);
        $this->app->bind(CompensationPolicyProvider::class, SettingsCompensationPolicyProvider::class);
        $this->app->bind(CaseAuditLog::class, EloquentCaseAuditLog::class);

        // Puertos de lectura cross-context: SQL de solo lectura sobre las proyecciones de shipments.
        $this->app->bind(AffectedValueReader::class, static fn (): DbAffectedValueReader => new DbAffectedValueReader(DB::connection()));
        $this->app->bind(PromisedWindowReader::class, static fn (): DbPromisedWindowReader => new DbPromisedWindowReader(DB::connection()));
        $this->app->bind(CustomerContactReader::class, static fn (): DbCustomerContactReader => new DbCustomerContactReader(DB::connection()));

        $this->app->bind(Repository::class, 'config');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireVouchersCommand::class]);
        }

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
