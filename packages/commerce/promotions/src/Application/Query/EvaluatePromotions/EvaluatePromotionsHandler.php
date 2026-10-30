<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Query\EvaluatePromotions;

use Commerce\Promotions\Application\ReadModel\DiscountQuote;
use Commerce\Promotions\Domain\Contract\PromotionRepository;
use Commerce\Promotions\Domain\Model\Promotion;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Junta las promociones AUTOMÁTICAS activas aplicables + (si hay código) la del cupón, suma los
 * descuentos (no negativo, topado al subtotal) y arma el DiscountQuote. La hora "ahora" viene del
 * puerto Clock del núcleo; el cálculo de cada descuento es dominio puro (Promotion::discountFor()).
 *
 * @implements QueryHandler<EvaluatePromotions, DiscountQuote>
 */
final readonly class EvaluatePromotionsHandler implements QueryHandler
{
    public function __construct(
        private PromotionRepository $promotions,
        private Clock $clock,
    ) {}

    public function handle(Query $query): DiscountQuote
    {
        assert($query instanceof EvaluatePromotions);

        $now = $this->clock->now();

        // Candidatas: promociones automáticas (sin código) activas + la del cupón si se ingresó código.
        $candidates = array_values(array_filter(
            $this->promotions->listActive(),
            static fn (Promotion $promotion): bool => !$promotion->isCoupon(),
        ));

        if ($query->couponCode !== null) {
            $code = strtoupper(trim($query->couponCode));
            if ($code !== '') {
                $coupon = $this->promotions->findByCode($code);
                if ($coupon !== null) {
                    $candidates[] = $coupon;
                }
            }
        }

        $applied = [];
        $totalDiscount = 0;
        $freeShipping = false;

        foreach ($candidates as $promotion) {
            if (!$promotion->appliesTo($query->subtotalCents, $query->channel, $now)) {
                continue;
            }

            $discount = $promotion->discountFor($query->subtotalCents, $query->channel, $now);
            $isFreeShipping = $promotion->isFreeShipping();

            // Una promo sin descuento de productos y sin envío gratis no cambia nada: no la listamos.
            if ($discount === 0 && !$isFreeShipping) {
                continue;
            }

            $applied[] = [
                'promotion_id' => $promotion->id()->toString(),
                'name' => $promotion->name(),
                'discount_cents' => $discount,
                'free_shipping' => $isFreeShipping,
            ];
            $totalDiscount += $discount;
            $freeShipping = $freeShipping || $isFreeShipping;
        }

        // Tope global al subtotal (no negativo).
        $totalDiscount = max(0, min($totalDiscount, $query->subtotalCents));

        return DiscountQuote::of($applied, $totalDiscount, $freeShipping);
    }
}
