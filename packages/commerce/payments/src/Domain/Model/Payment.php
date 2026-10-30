<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\Model;

use Commerce\Payments\Domain\Event\PaymentCaptured;
use Commerce\Payments\Domain\Event\PaymentFailed;
use Commerce\Payments\Domain\Event\PaymentInitiated;
use Commerce\Payments\Domain\Event\PaymentRefunded;
use Commerce\Payments\Domain\Exception\PaymentsException;
use Commerce\Payments\Domain\ValueObject\Money;
use Commerce\Payments\Domain\ValueObject\PaymentId;
use Commerce\Payments\Domain\ValueObject\PaymentMethod;
use Commerce\Payments\Domain\ValueObject\PaymentStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Pago: agregado que cobra una orden a través de una pasarela (EC-F0-07, ADR 018).
 *
 * Referencia la orden por un `Uuid` genérico (payments NO depende del contexto orders): el acoplamiento
 * se resuelve por evento/puerto, no por `use` cruzado entre familias. Custodia las invariantes de
 * transición de estado (Pending → Authorized/Captured/Failed; Captured → Refunded) y emite los hechos
 * de negocio al outbox. El cobro real lo hace la pasarela detrás del puerto `PaymentGateway`.
 */
final class Payment extends AggregateRoot
{
    private function __construct(
        private readonly PaymentId $id,
        private readonly Uuid $orderId,
        private readonly Money $amount,
        private readonly PaymentMethod $method,
        private PaymentStatus $status,
        private ?string $gatewayRef,
    ) {}

    /**
     * Inicia un pago nuevo contra una orden. Arranca en Pending y graba `PaymentInitiated`.
     */
    public static function initiate(
        PaymentId $id,
        Uuid $orderId,
        Money $amount,
        PaymentMethod $method,
    ): self {
        $payment = new self($id, $orderId, $amount, $method, PaymentStatus::Pending, null);
        $payment->recordEvent(new PaymentInitiated(
            $id->toString(),
            $orderId->value,
            $amount->cents,
            $amount->currency,
            $method->value,
            PaymentStatus::Pending->value,
        ));

        return $payment;
    }

    /**
     * Reconstituye un pago desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        PaymentId $id,
        Uuid $orderId,
        Money $amount,
        PaymentMethod $method,
        PaymentStatus $status,
        ?string $gatewayRef,
    ): self {
        return new self($id, $orderId, $amount, $method, $status, $gatewayRef);
    }

    /**
     * Marca el pago como autorizado tras la aprobación online de la pasarela.
     *
     * No es un hecho de negocio publicable por sí mismo (la captura es la que confirma la orden): es una
     * transición interna y síncrona, así que guarda la referencia del proveedor pero no graba evento.
     * Solo es válida desde Pending.
     */
    public function markAuthorized(string $gatewayRef): void
    {
        if ($this->status !== PaymentStatus::Pending) {
            throw PaymentsException::because(
                "No se puede autorizar un pago en estado {$this->status->value}.",
            );
        }
        $this->status = PaymentStatus::Authorized;
        $this->gatewayRef = $gatewayRef;
    }

    /**
     * Captura (confirma) el cobro. Válido desde Pending o Authorized → Captured. Graba `PaymentCaptured`.
     *
     * Idempotente: si ya está Captured no vuelve a grabar el evento (el outbox reentrega at-least-once y
     * el webhook puede llegar repetido). Desde Failed o Refunded es una transición inválida.
     */
    public function capture(string $gatewayRef): void
    {
        if ($this->status === PaymentStatus::Captured) {
            return;
        }
        if ($this->status !== PaymentStatus::Pending && $this->status !== PaymentStatus::Authorized) {
            throw PaymentsException::because(
                "No se puede capturar un pago en estado {$this->status->value}.",
            );
        }

        $this->status = PaymentStatus::Captured;
        $this->gatewayRef = $gatewayRef;
        $this->recordEvent(new PaymentCaptured(
            $this->id->toString(),
            $this->orderId->value,
            $this->amount->cents,
            $this->amount->currency,
            $gatewayRef,
        ));
    }

    /**
     * Marca el pago como fallido. Válido desde Pending o Authorized → Failed. Graba `PaymentFailed`.
     *
     * Idempotente: si ya está Failed no vuelve a grabar. No se puede fallar un pago capturado o reembolsado.
     */
    public function fail(string $reason): void
    {
        if ($this->status === PaymentStatus::Failed) {
            return;
        }
        if ($this->status !== PaymentStatus::Pending && $this->status !== PaymentStatus::Authorized) {
            throw PaymentsException::because(
                "No se puede fallar un pago en estado {$this->status->value}.",
            );
        }

        $this->status = PaymentStatus::Failed;
        $this->recordEvent(new PaymentFailed(
            $this->id->toString(),
            $this->orderId->value,
            $reason,
        ));
    }

    /**
     * Reembolsa el pago. Solo válido desde Captured → Refunded. Graba `PaymentRefunded`.
     *
     * Idempotente: si ya está Refunded no vuelve a grabar.
     */
    public function refund(): void
    {
        if ($this->status === PaymentStatus::Refunded) {
            return;
        }
        if ($this->status !== PaymentStatus::Captured) {
            throw PaymentsException::because(
                "Solo se puede reembolsar un pago capturado; estado actual: {$this->status->value}.",
            );
        }

        $this->status = PaymentStatus::Refunded;
        $this->recordEvent(new PaymentRefunded(
            $this->id->toString(),
            $this->orderId->value,
            $this->amount->cents,
            $this->amount->currency,
        ));
    }

    public function id(): PaymentId
    {
        return $this->id;
    }

    public function orderId(): Uuid
    {
        return $this->orderId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function method(): PaymentMethod
    {
        return $this->method;
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function gatewayRef(): ?string
    {
        return $this->gatewayRef;
    }
}
