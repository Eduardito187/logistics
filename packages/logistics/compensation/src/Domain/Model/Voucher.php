<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Model;

use DateTimeImmutable;
use Logistics\Compensation\Domain\Event\VoucherExpired;
use Logistics\Compensation\Domain\Event\VoucherIssued;
use Logistics\Compensation\Domain\Event\VoucherRedeemed;
use Logistics\Compensation\Domain\Event\VoucherRevoked;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Compensation\Domain\ValueObject\VoucherId;
use Logistics\Compensation\Domain\ValueObject\VoucherStatus;

/**
 * Agregado raíz VALE: el crédito de un solo uso, nominativo, con código único y vencimiento.
 *
 * Custodia la invariante CENTRAL del canje: un vale se canjea UNA sola vez. El canje es idempotente por
 * `saleRef` (reintento del POS con la misma referencia ⇒ no vuelve a descontar); un segundo canje con otra
 * referencia falla. El anular/vencer solo aplica sobre un vale aún emitido (no canjeado).
 */
final class Voucher
{
    public const string SYNC_PENDING = 'pending';

    public const string SYNC_SYNCED = 'synced';

    public const string SYNC_FAILED = 'failed';

    /** @var list<\Logistics\Shared\Domain\Event\DomainEvent> */
    private array $events = [];

    private function __construct(
        private readonly VoucherId $id,
        private readonly CompensationCaseId $caseId,
        private readonly VoucherCode $code,
        private readonly Money $amount,
        private readonly ?string $holderEmail,
        private readonly ?string $holderEmailHash,
        private readonly DateTimeImmutable $expiresAt,
        private VoucherStatus $status,
        private ?string $magentoRuleId,
        private string $magentoSyncStatus,
        private ?DateTimeImmutable $heldUntil,
        private ?string $heldByChannel,
        private ?DateTimeImmutable $redeemedAt,
        private ?string $redeemedChannel,
        private ?string $redeemedSaleRef,
    ) {}

    /**
     * Emite un vale a partir de un caso aprobado. Arranca Issued y con la sincronización a Magento pendiente.
     */
    public static function issue(
        VoucherId $id,
        CompensationCaseId $caseId,
        VoucherCode $code,
        Money $amount,
        ?string $holderEmail,
        DateTimeImmutable $expiresAt,
        ?string $orderReference,
        string $trigger,
    ): self {
        if ($amount->isZero()) {
            throw CompensationException::because('No se puede emitir un vale de monto cero.');
        }

        $holderEmail = $holderEmail !== null ? strtolower(trim($holderEmail)) : null;
        $hash = $holderEmail !== null && $holderEmail !== '' ? hash('sha256', $holderEmail) : null;

        $voucher = new self(
            id: $id,
            caseId: $caseId,
            code: $code,
            amount: $amount,
            holderEmail: $holderEmail,
            holderEmailHash: $hash,
            expiresAt: $expiresAt,
            status: VoucherStatus::Issued,
            magentoRuleId: null,
            magentoSyncStatus: self::SYNC_PENDING,
            heldUntil: null,
            heldByChannel: null,
            redeemedAt: null,
            redeemedChannel: null,
            redeemedSaleRef: null,
        );

        $voucher->events[] = new VoucherIssued(
            $id->toString(),
            $caseId->toString(),
            $code->value,
            $amount->cents,
            $amount->currency,
            $holderEmail,
            $expiresAt->format(DateTimeImmutable::ATOM),
            $orderReference,
            $trigger,
        );

        return $voucher;
    }

    /**
     * Reconstituye un vale desde persistencia, sin grabar eventos.
     */
    public static function reconstitute(
        VoucherId $id,
        CompensationCaseId $caseId,
        VoucherCode $code,
        Money $amount,
        ?string $holderEmail,
        ?string $holderEmailHash,
        DateTimeImmutable $expiresAt,
        VoucherStatus $status,
        ?string $magentoRuleId,
        string $magentoSyncStatus,
        ?DateTimeImmutable $heldUntil,
        ?string $heldByChannel,
        ?DateTimeImmutable $redeemedAt,
        ?string $redeemedChannel,
        ?string $redeemedSaleRef,
    ): self {
        return new self(
            $id,
            $caseId,
            $code,
            $amount,
            $holderEmail,
            $holderEmailHash,
            $expiresAt,
            $status,
            $magentoRuleId,
            $magentoSyncStatus,
            $heldUntil,
            $heldByChannel,
            $redeemedAt,
            $redeemedChannel,
            $redeemedSaleRef,
        );
    }

    /**
     * Reserva temporal del vale para una venta del POS (hold). Solo sobre un vale emitido y no reservado por
     * otra venta vigente. No cambia el estado: evita que otro canal lo tome mientras dure el ttl.
     */
    public function hold(string $channel, DateTimeImmutable $until, DateTimeImmutable $now): void
    {
        $this->assertRedeemable($now);
        if ($this->heldUntil !== null && $this->heldUntil > $now) {
            throw CompensationException::because('El vale ya está reservado por otra venta.');
        }
        $this->heldUntil = $until;
        $this->heldByChannel = trim($channel);
    }

    /**
     * Libera la reserva (release) sin canjear.
     */
    public function releaseHold(): void
    {
        $this->heldUntil = null;
        $this->heldByChannel = null;
    }

    /**
     * Canjea el vale (un solo uso). Idempotente por `saleRef`: reintento con la misma referencia ⇒ no-op.
     * Un segundo canje con OTRA referencia falla (el vale ya se usó).
     */
    public function redeem(string $channel, string $saleRef, Money $amountUsed, DateTimeImmutable $now): void
    {
        $saleRef = trim($saleRef);
        if ($saleRef === '') {
            throw CompensationException::because('La referencia de venta no puede estar vacía.');
        }

        // Idempotencia: el mismo canje reintentado no vuelve a descontar.
        if ($this->status === VoucherStatus::Redeemed) {
            if ($this->redeemedSaleRef === $saleRef) {
                return;
            }
            throw CompensationException::because('El vale ya fue canjeado.');
        }

        $this->assertRedeemable($now);
        if ($amountUsed->cents > $this->amount->cents) {
            throw CompensationException::because('El monto canjeado no puede superar el monto del vale.');
        }

        $this->status = VoucherStatus::Redeemed;
        $this->redeemedAt = $now;
        $this->redeemedChannel = trim($channel);
        $this->redeemedSaleRef = $saleRef;
        $this->heldUntil = null;
        $this->heldByChannel = null;

        $this->events[] = new VoucherRedeemed(
            $this->id->toString(),
            trim($channel),
            $saleRef,
            $amountUsed->cents,
            $amountUsed->currency,
        );
    }

    /**
     * Anula (revoca) el vale. Solo antes del canje.
     */
    public function revoke(string $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($this->status === VoucherStatus::Redeemed) {
            throw CompensationException::because('No se puede anular un vale ya canjeado.');
        }
        if ($this->status === VoucherStatus::Revoked) {
            return;
        }
        if ($reason === '') {
            throw CompensationException::because('El motivo de la anulación no puede estar vacío.');
        }

        $this->status = VoucherStatus::Revoked;
        $this->events[] = new VoucherRevoked(
            $this->id->toString(),
            trim($actor),
            $reason,
            $this->amount->cents,
            $this->amount->currency,
        );
    }

    /**
     * Vence el vale si corresponde (emitido y pasada la fecha). Devuelve true si cambió de estado.
     */
    public function expire(DateTimeImmutable $now): bool
    {
        if ($this->status !== VoucherStatus::Issued || $now < $this->expiresAt) {
            return false;
        }

        $this->status = VoucherStatus::Expired;
        $this->events[] = new VoucherExpired(
            $this->id->toString(),
            $this->amount->cents,
            $this->amount->currency,
        );

        return true;
    }

    /**
     * Registra el resultado de la sincronización con Magento (lo llama el dispatcher del sync).
     */
    public function recordSync(string $result, ?string $magentoRuleId = null): void
    {
        if (! in_array($result, [self::SYNC_PENDING, self::SYNC_SYNCED, self::SYNC_FAILED], true)) {
            throw CompensationException::because("Resultado de sincronización inválido: {$result}.");
        }
        $this->magentoSyncStatus = $result;
        if ($magentoRuleId !== null) {
            $this->magentoRuleId = $magentoRuleId;
        }
    }

    public function isValidAt(DateTimeImmutable $now): bool
    {
        return $this->status === VoucherStatus::Issued && $now < $this->expiresAt;
    }

    /**
     * Comprueba que el titular coincide (vale nominativo): compara el hash del email. Sin titular ⇒ true.
     */
    public function belongsTo(string $email): bool
    {
        if ($this->holderEmailHash === null) {
            return true;
        }

        return hash_equals($this->holderEmailHash, hash('sha256', strtolower(trim($email))));
    }

    /**
     * Libera los eventos acumulados y vacía el buffer (idempotente).
     *
     * @return list<\Logistics\Shared\Domain\Event\DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    private function assertRedeemable(DateTimeImmutable $now): void
    {
        match ($this->status) {
            VoucherStatus::Revoked => throw CompensationException::because('El vale está anulado.'),
            VoucherStatus::Expired => throw CompensationException::because('El vale está vencido.'),
            default => null,
        };
        if ($now >= $this->expiresAt) {
            throw CompensationException::because('El vale está vencido.');
        }
    }

    public function id(): VoucherId
    {
        return $this->id;
    }

    public function caseId(): CompensationCaseId
    {
        return $this->caseId;
    }

    public function code(): VoucherCode
    {
        return $this->code;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function holderEmail(): ?string
    {
        return $this->holderEmail;
    }

    public function holderEmailHash(): ?string
    {
        return $this->holderEmailHash;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function status(): VoucherStatus
    {
        return $this->status;
    }

    public function magentoRuleId(): ?string
    {
        return $this->magentoRuleId;
    }

    public function magentoSyncStatus(): string
    {
        return $this->magentoSyncStatus;
    }

    public function heldUntil(): ?DateTimeImmutable
    {
        return $this->heldUntil;
    }

    public function heldByChannel(): ?string
    {
        return $this->heldByChannel;
    }

    public function redeemedAt(): ?DateTimeImmutable
    {
        return $this->redeemedAt;
    }

    public function redeemedChannel(): ?string
    {
        return $this->redeemedChannel;
    }

    public function redeemedSaleRef(): ?string
    {
        return $this->redeemedSaleRef;
    }
}
