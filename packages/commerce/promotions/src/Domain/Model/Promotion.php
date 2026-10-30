<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\Model;

use Commerce\Promotions\Domain\Event\PromotionActivated;
use Commerce\Promotions\Domain\Event\PromotionCreated;
use Commerce\Promotions\Domain\Event\PromotionExpired;
use Commerce\Promotions\Domain\Event\PromotionRulesChanged;
use Commerce\Promotions\Domain\Exception\PromotionsException;
use Commerce\Promotions\Domain\ValueObject\PromotionConditions;
use Commerce\Promotions\Domain\ValueObject\PromotionId;
use Commerce\Promotions\Domain\ValueObject\PromotionStatus;
use Commerce\Promotions\Domain\ValueObject\PromotionType;
use DateTimeImmutable;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Promoción del motor comercial (ADR 018, EC-F0-09).
 *
 * Custodia su identidad, nombre, código (null = promoción automática; no-null = cupón), tipo de
 * beneficio, valor (según el tipo: basis points para porcentaje, centavos para monto fijo, 0 para envío
 * gratis), moneda, condiciones de aplicación, estado de ciclo de vida y la versión de sus reglas.
 *
 * El cálculo del descuento (`discountFor`) es dominio puro: no depende de Laravel ni de la hora global;
 * recibe el instante actual como argumento (lo provee la capa de aplicación vía el puerto Clock).
 */
final class Promotion extends AggregateRoot
{
    private function __construct(
        private readonly PromotionId $id,
        private string $name,
        private readonly ?string $code,
        private PromotionType $type,
        private int $value,
        private string $currency,
        private PromotionConditions $conditions,
        private PromotionStatus $status,
        private int $version,
    ) {}

    /**
     * Da de alta una promoción nueva en borrador (versión 1). El código es opcional: null = promoción
     * automática; no-null = cupón (se normaliza a mayúsculas sin espacios externos).
     */
    public static function create(
        PromotionId $id,
        string $name,
        ?string $code,
        PromotionType $type,
        int $value,
        string $currency,
        PromotionConditions $conditions,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw PromotionsException::because('El nombre de la promoción no puede estar vacío.');
        }

        $code = self::normalizeCode($code);
        self::assertValueForType($type, $value);

        $promotion = new self(
            $id,
            $name,
            $code,
            $type,
            $value,
            strtoupper(trim($currency)),
            $conditions,
            PromotionStatus::Draft,
            1,
        );
        $promotion->recordEvent(new PromotionCreated(
            $id->toString(),
            $name,
            $code,
            $type->value,
            $value,
            $promotion->currency,
        ));

        return $promotion;
    }

    /**
     * Reconstituye una promoción desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        PromotionId $id,
        string $name,
        ?string $code,
        PromotionType $type,
        int $value,
        string $currency,
        PromotionConditions $conditions,
        PromotionStatus $status,
        int $version,
    ): self {
        return new self($id, $name, $code, $type, $value, $currency, $conditions, $status, $version);
    }

    /**
     * Activa la promoción (idempotente: si ya está activa, no hace nada ni graba evento).
     */
    public function activate(): void
    {
        if ($this->status === PromotionStatus::Active) {
            return;
        }
        $this->status = PromotionStatus::Active;
        $this->recordEvent(new PromotionActivated($this->id->toString()));
    }

    /**
     * Expira la promoción (idempotente).
     */
    public function expire(): void
    {
        if ($this->status === PromotionStatus::Expired) {
            return;
        }
        $this->status = PromotionStatus::Expired;
        $this->recordEvent(new PromotionExpired($this->id->toString()));
    }

    /**
     * Cambia las reglas (tipo, valor, condiciones) e incrementa la versión (versionado de reglas).
     */
    public function changeRules(PromotionType $type, int $value, PromotionConditions $conditions): void
    {
        self::assertValueForType($type, $value);

        $this->type = $type;
        $this->value = $value;
        $this->conditions = $conditions;
        $this->version++;

        $this->recordEvent(new PromotionRulesChanged(
            $this->id->toString(),
            $type->value,
            $value,
            $this->version,
        ));
    }

    /**
     * ¿Es un cupón (tiene código) o una promoción automática (sin código)?
     */
    public function isCoupon(): bool
    {
        return $this->code !== null;
    }

    /**
     * ¿Otorga envío gratis? (el ahorro de envío lo resuelve checkout; el descuento de productos es 0).
     */
    public function isFreeShipping(): bool
    {
        return $this->type === PromotionType::FreeShipping;
    }

    /**
     * ¿La promoción aplica para este subtotal, canal e instante? (estado activo + condiciones).
     */
    public function appliesTo(int $subtotalCents, string $channel, DateTimeImmutable $now): bool
    {
        return $this->status === PromotionStatus::Active
            && $this->conditions->channelMatches($channel)
            && $this->conditions->withinWindow($now)
            && $this->conditions->subtotalMeetsMinimum($subtotalCents);
    }

    /**
     * Descuento en centavos aplicable al subtotal dado. Devuelve 0 si la promoción no aplica por estado,
     * condiciones (subtotal mínimo, canal, ventana de fechas) o tipo. El resultado nunca es negativo ni
     * supera el subtotal. Para FreeShipping el descuento sobre productos es 0 (ver isFreeShipping()).
     */
    public function discountFor(int $subtotalCents, string $channel, DateTimeImmutable $now): int
    {
        if ($subtotalCents <= 0 || !$this->appliesTo($subtotalCents, $channel, $now)) {
            return 0;
        }

        $discount = match ($this->type) {
            // value en basis points (10000 = 100%): 1000 bps de 100000 = 10000 centavos.
            PromotionType::PercentageOff => intdiv($subtotalCents * $this->value, 10000),
            // value en centavos; se topa al subtotal.
            PromotionType::FixedAmountOff => $this->value,
            // el ahorro de envío no descuenta productos.
            PromotionType::FreeShipping => 0,
        };

        return max(0, min($discount, $subtotalCents));
    }

    public function id(): PromotionId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function code(): ?string
    {
        return $this->code;
    }

    public function type(): PromotionType
    {
        return $this->type;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function conditions(): PromotionConditions
    {
        return $this->conditions;
    }

    public function status(): PromotionStatus
    {
        return $this->status;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Normaliza el código: null queda null (promoción automática); un string se recorta y pasa a
     * mayúsculas. Un string que queda vacío tras recortar es inválido (un cupón necesita código).
     */
    private static function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw PromotionsException::because('El código del cupón no puede estar vacío.');
        }

        return $code;
    }

    /**
     * Invariantes de valor según el tipo: porcentaje en 0..10000 bps, monto fijo no negativo, envío
     * gratis siempre 0.
     */
    private static function assertValueForType(PromotionType $type, int $value): void
    {
        switch ($type) {
            case PromotionType::PercentageOff:
                if ($value < 0 || $value > 10000) {
                    throw PromotionsException::because(
                        'El porcentaje debe estar entre 0 y 10000 basis points.',
                    );
                }
                break;
            case PromotionType::FixedAmountOff:
                if ($value < 0) {
                    throw PromotionsException::because('El monto fijo no puede ser negativo.');
                }
                break;
            case PromotionType::FreeShipping:
                if ($value !== 0) {
                    throw PromotionsException::because('El envío gratis no lleva valor (debe ser 0).');
                }
                break;
            default:
                // Inalcanzable: PromotionType es un enum cerrado y los tres casos ya están cubiertos.
                // Si se agrega un tipo nuevo sin tratarlo aquí, esto lo delata de inmediato.
                throw PromotionsException::because('Tipo de promoción inesperado: '.$type->value.'.');
        }
    }
}
