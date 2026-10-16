<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\Model;

use Logistics\Settlement\Domain\Event\SettlementConfirmed;
use Logistics\Settlement\Domain\Event\SettlementDrafted;
use Logistics\Settlement\Domain\Event\SettlementPaid;
use Logistics\Settlement\Domain\Exception\SettlementException;
use Logistics\Settlement\Domain\ValueObject\AppliedFees;
use Logistics\Settlement\Domain\ValueObject\Money;
use Logistics\Settlement\Domain\ValueObject\SettlementId;
use Logistics\Settlement\Domain\ValueObject\SettlementLine;
use Logistics\Settlement\Domain\ValueObject\SettlementPeriod;
use Logistics\Settlement\Domain\ValueObject\SettlementStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Liquidación de un seller de marketplace por los despachos de un periodo (Fase 4, ADR 017).
 *
 * Clona el patrón `carriers/Settlement`: ciclo de vida Draft → Confirmed → Paid protegido por el agregado.
 * Al armar el borrador agrega las líneas (un despacho = una línea con su monto base), aplica la comisión
 * (en basis points sobre el bruto) y el fee fijo (por despacho), descuenta los fees diferidos (hoy 0) y
 * calcula el neto a pagar al seller. El seller se referencia por su id como texto para no acoplarse al
 * contexto `sellers`.
 */
final class SellerSettlement extends AggregateRoot
{
    /**
     * @param list<SettlementLine> $lines
     */
    private function __construct(
        private readonly SettlementId $id,
        private readonly string $sellerId,
        private readonly SettlementPeriod $period,
        private readonly array $lines,
        private readonly Money $gross,
        private readonly AppliedFees $fees,
        private readonly Money $net,
        private SettlementStatus $status,
        private ?string $paymentReference,
    ) {}

    /**
     * Arma el borrador de una liquidación agregando los despachos del seller del periodo.
     *
     * @param list<SettlementLine> $lines un despacho = una línea; no puede estar vacío
     */
    public static function draft(
        SettlementId $id,
        string $sellerId,
        SettlementPeriod $period,
        array $lines,
        int $commissionBasisPoints,
        Money $fixedFeePerDispatch,
    ): self {
        $sellerId = trim($sellerId);
        if ($sellerId === '') {
            throw SettlementException::because('La liquidación requiere un seller.');
        }
        $lines = array_values($lines);
        if ($lines === []) {
            throw SettlementException::because('No hay despachos del seller en el periodo para liquidar.');
        }

        $currency = $lines[0]->baseAmount->currency;
        $gross = Money::zero($currency);
        foreach ($lines as $line) {
            // add() valida que todas las líneas compartan moneda (invariante del Money).
            $gross = $gross->add($line->baseAmount);
        }

        $commission = $gross->percentageByBasisPoints($commissionBasisPoints);
        $fixedFeeTotal = $fixedFeePerDispatch->multiply(count($lines));
        // Huecos tipados de almacenaje/fulfillment/última milla: hoy 0 (ver AppliedFees).
        $fees = AppliedFees::of($commissionBasisPoints, $commission, $fixedFeeTotal);

        $feesTotal = $fees->total();
        if ($feesTotal->isGreaterThan($gross)) {
            throw SettlementException::because('Los fees aplicados no pueden superar el bruto del periodo.');
        }
        $net = $gross->subtract($feesTotal);

        $settlement = new self(
            $id,
            $sellerId,
            $period,
            $lines,
            $gross,
            $fees,
            $net,
            SettlementStatus::Draft,
            null,
        );
        $settlement->recordEvent(new SettlementDrafted(
            $id->toString(),
            $sellerId,
            $period->fromString(),
            $period->toStringEnd(),
            count($lines),
            $gross->cents,
            $feesTotal->cents,
            $net->cents,
            $currency,
        ));

        return $settlement;
    }

    /**
     * Reconstituye una liquidación desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<SettlementLine> $lines
     */
    public static function reconstitute(
        SettlementId $id,
        string $sellerId,
        SettlementPeriod $period,
        array $lines,
        Money $gross,
        AppliedFees $fees,
        Money $net,
        SettlementStatus $status,
        ?string $paymentReference,
    ): self {
        return new self(
            $id,
            $sellerId,
            $period,
            array_values($lines),
            $gross,
            $fees,
            $net,
            $status,
            $paymentReference,
        );
    }

    /**
     * Confirma la liquidación. Solo desde Draft.
     */
    public function confirm(): void
    {
        if ($this->status !== SettlementStatus::Draft) {
            throw SettlementException::because(
                "Solo se puede confirmar una liquidación en borrador; está en {$this->status->value}.",
            );
        }
        $this->status = SettlementStatus::Confirmed;
        $this->recordEvent(new SettlementConfirmed($this->id->toString()));
    }

    /**
     * Marca la liquidación como pagada al seller. Solo desde Confirmed.
     */
    public function markPaid(string $paymentReference): void
    {
        if ($this->status !== SettlementStatus::Confirmed) {
            throw SettlementException::because(
                "Solo se puede pagar una liquidación confirmada; está en {$this->status->value}.",
            );
        }
        $paymentReference = trim($paymentReference);
        if ($paymentReference === '') {
            throw SettlementException::because('El pago requiere una referencia (p. ej. número de transferencia).');
        }
        $this->status = SettlementStatus::Paid;
        $this->paymentReference = $paymentReference;
        $this->recordEvent(new SettlementPaid($this->id->toString(), $paymentReference));
    }

    public function id(): SettlementId
    {
        return $this->id;
    }

    public function sellerId(): string
    {
        return $this->sellerId;
    }

    public function period(): SettlementPeriod
    {
        return $this->period;
    }

    /**
     * @return list<SettlementLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function gross(): Money
    {
        return $this->gross;
    }

    public function fees(): AppliedFees
    {
        return $this->fees;
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function status(): SettlementStatus
    {
        return $this->status;
    }

    public function paymentReference(): ?string
    {
        return $this->paymentReference;
    }
}
