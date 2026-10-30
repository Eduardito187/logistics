<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Infrastructure\Providers;

use Commerce\Invoicing\Application\Command\CancelInvoice\CancelInvoice;
use Commerce\Invoicing\Application\Command\CancelInvoice\CancelInvoiceHandler;
use Commerce\Invoicing\Application\Command\IssueInvoiceForOrder\IssueInvoiceForOrder;
use Commerce\Invoicing\Application\Command\IssueInvoiceForOrder\IssueInvoiceForOrderHandler;
use Commerce\Invoicing\Application\Query\GetInvoice\GetInvoice;
use Commerce\Invoicing\Application\Query\GetInvoice\GetInvoiceHandler;
use Commerce\Invoicing\Application\Query\GetInvoiceByOrder\GetInvoiceByOrder;
use Commerce\Invoicing\Application\Query\GetInvoiceByOrder\GetInvoiceByOrderHandler;
use Commerce\Invoicing\Application\Query\ListInvoices\ListInvoices;
use Commerce\Invoicing\Application\Query\ListInvoices\ListInvoicesHandler;
use Commerce\Invoicing\Domain\Contract\InvoiceRepository;
use Commerce\Invoicing\Domain\Contract\TaxDocumentGateway;
use Commerce\Invoicing\Infrastructure\Gateway\StubTaxDocumentGateway;
use Commerce\Invoicing\Infrastructure\Persistence\Eloquent\Repository\EloquentInvoiceRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Invoicing (frente comercial, EC-F0-10 / ADR 018).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto.
 */
final class InvoicingServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        IssueInvoiceForOrder::class => IssueInvoiceForOrderHandler::class,
        CancelInvoice::class => CancelInvoiceHandler::class,
        GetInvoice::class => GetInvoiceHandler::class,
        GetInvoiceByOrder::class => GetInvoiceByOrderHandler::class,
        ListInvoices::class => ListInvoicesHandler::class,
    ];

    /**
     * Adaptadores del sistema tributario por clave de env `INVOICING_GATEWAY`. El default (stub) genera
     * un número sintético.
     *
     * @var array<string, class-string<TaxDocumentGateway>>
     */
    private const GATEWAYS = [
        'stub' => StubTaxDocumentGateway::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/invoicing.php', 'commerce_invoicing');

        $this->app->bind(InvoiceRepository::class, EloquentInvoiceRepository::class);

        // El binding del gateway se elige por config (que lee INVOICING_GATEWAY); default: Stub
        // (open-source, sin servicios de pago). env() solo vive en el archivo de config (regla del proyecto).
        $key = is_string($gateway = config('commerce_invoicing.gateway', 'stub')) ? $gateway : 'stub';
        $this->app->bind(TaxDocumentGateway::class, self::GATEWAYS[$key] ?? StubTaxDocumentGateway::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Vistas del documento fiscal interno (render HTML imprimible, EC-F0-10). Namespace propio del
        // contexto para no colisionar con otros packages.
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'commerce-invoicing');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
