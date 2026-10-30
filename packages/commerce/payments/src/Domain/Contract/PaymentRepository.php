<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\Contract;

use Commerce\Payments\Domain\Model\Payment;
use Commerce\Payments\Domain\ValueObject\PaymentId;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Puerto de persistencia de pagos. La implementación vive en Infrastructure.
 *
 * El pago referencia su orden por `Uuid` genérico (payments no depende del contexto orders): por eso
 * `findByOrderId` recibe un `Uuid` y no un id tipado de otro contexto.
 */
interface PaymentRepository
{
    public function nextIdentity(): PaymentId;

    /**
     * Upsert del pago por id.
     */
    public function save(Payment $payment): void;

    public function findById(PaymentId $id): ?Payment;

    /**
     * Devuelve el pago más reciente asociado a una orden, o null si no hay ninguno.
     */
    public function findByOrderId(Uuid $orderId): ?Payment;

    /**
     * @return list<Payment>
     */
    public function all(): array;
}
