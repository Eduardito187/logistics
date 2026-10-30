<?php

declare(strict_types=1);

namespace Commerce\Payments\Infrastructure\Providers;

use Commerce\Payments\Application\Command\CapturePayment\CapturePayment;
use Commerce\Payments\Application\Command\CapturePayment\CapturePaymentHandler;
use Commerce\Payments\Application\Command\FailPayment\FailPayment;
use Commerce\Payments\Application\Command\FailPayment\FailPaymentHandler;
use Commerce\Payments\Application\Command\InitiatePayment\InitiatePayment;
use Commerce\Payments\Application\Command\InitiatePayment\InitiatePaymentHandler;
use Commerce\Payments\Application\Command\RefundPayment\RefundPayment;
use Commerce\Payments\Application\Command\RefundPayment\RefundPaymentHandler;
use Commerce\Payments\Application\Query\GetPayment\GetPayment;
use Commerce\Payments\Application\Query\GetPayment\GetPaymentHandler;
use Commerce\Payments\Application\Query\GetPaymentByOrder\GetPaymentByOrder;
use Commerce\Payments\Application\Query\GetPaymentByOrder\GetPaymentByOrderHandler;
use Commerce\Payments\Application\Query\ListPayments\ListPayments;
use Commerce\Payments\Application\Query\ListPayments\ListPaymentsHandler;
use Commerce\Payments\Domain\Contract\PaymentGateway;
use Commerce\Payments\Domain\Contract\PaymentRepository;
use Commerce\Payments\Infrastructure\Gateway\QrBcbPaymentGateway;
use Commerce\Payments\Infrastructure\Gateway\StubPaymentGateway;
use Commerce\Payments\Infrastructure\Persistence\Eloquent\Repository\EloquentPaymentRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Payments (frente comercial, EC-F0-07 / ADR 018).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle siempre el proyecto.
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        InitiatePayment::class => InitiatePaymentHandler::class,
        CapturePayment::class => CapturePaymentHandler::class,
        FailPayment::class => FailPaymentHandler::class,
        RefundPayment::class => RefundPaymentHandler::class,
        GetPayment::class => GetPaymentHandler::class,
        GetPaymentByOrder::class => GetPaymentByOrderHandler::class,
        ListPayments::class => ListPaymentsHandler::class,
    ];

    /**
     * Pasarelas disponibles por clave de env `PAYMENTS_GATEWAY`. El default (stub) aprueba siempre.
     *
     * @var array<string, class-string<PaymentGateway>>
     */
    private const GATEWAYS = [
        'stub' => StubPaymentGateway::class,
        'qr_bcb' => QrBcbPaymentGateway::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/payments.php', 'commerce_payments');

        $this->app->bind(PaymentRepository::class, EloquentPaymentRepository::class);

        // El binding de la pasarela se elige por config (que lee PAYMENTS_GATEWAY); default: Stub
        // (open-source, sin servicios de pago). env() solo vive en el archivo de config (regla del proyecto).
        $key = is_string($gateway = config('commerce_payments.gateway', 'stub')) ? $gateway : 'stub';
        $this->app->bind(PaymentGateway::class, self::GATEWAYS[$key] ?? StubPaymentGateway::class);
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
