<?php

declare(strict_types=1);

use Commerce\Promotions\Application\Command\ActivatePromotion\ActivatePromotion;
use Commerce\Promotions\Application\Command\CreatePromotion\CreatePromotion;
use Commerce\Promotions\Application\Query\EvaluatePromotions\EvaluatePromotions;
use Commerce\Promotions\Application\Query\GetPromotion\GetPromotion;
use Commerce\Promotions\Application\Query\ListPromotions\ListPromotions;
use Commerce\Promotions\Infrastructure\Persistence\Eloquent\Model\PromotionRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Circuito de promociones contra PostgreSQL real (logistics_test, RefreshDatabase): crear un cupón,
 * activarlo, leerlo por las queries y evaluar el descuento con y sin código. Atraviesa CommandBus →
 * handler → repositorio → mapper → persistencia → QueryBus → ReadModel.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('crea y activa un cupón y lo consulta', function (): void {
    $this->commands->dispatch(new CreatePromotion(
        name: 'Bienvenida 10%',
        type: 'percentage_off',
        value: 1000,
        code: 'bienvenida',
    ));

    $drafts = $this->queries->ask(new ListPromotions('draft'));
    expect($drafts)->toHaveCount(1)
        ->and($drafts[0]->code)->toBe('BIENVENIDA');
    $promotionId = $drafts[0]->id;

    $this->commands->dispatch(new ActivatePromotion($promotionId));

    $view = $this->queries->ask(new GetPromotion($promotionId));
    expect($view)->not->toBeNull()
        ->and($view->status)->toBe('active')
        ->and($view->isCoupon)->toBeTrue()
        ->and($view->version)->toBe(1);

    expect($this->queries->ask(new ListPromotions('active')))->toHaveCount(1);
    expect($this->queries->ask(new ListPromotions('draft')))->toHaveCount(0);
});

it('evalúa el descuento con y sin código de cupón', function (): void {
    // Cupón 10% activo.
    $this->commands->dispatch(new CreatePromotion(
        name: 'Bienvenida 10%',
        type: 'percentage_off',
        value: 1000,
        code: 'BIENVENIDA',
    ));
    $coupon = PromotionRecord::query()->where('code', 'BIENVENIDA')->firstOrFail();
    $this->commands->dispatch(new ActivatePromotion($coupon->id));

    // Sin cupón: no hay promos automáticas → sin descuento.
    $noCoupon = $this->queries->ask(new EvaluatePromotions(100000, 'Retail'));
    expect($noCoupon->totalDiscountCents)->toBe(0)
        ->and($noCoupon->applied)->toHaveCount(0)
        ->and($noCoupon->freeShipping)->toBeFalse();

    // Con el cupón (en minúscula; se normaliza): 10% de 100000 = 10000.
    $withCoupon = $this->queries->ask(new EvaluatePromotions(100000, 'Retail', 'bienvenida'));
    expect($withCoupon->totalDiscountCents)->toBe(10000)
        ->and($withCoupon->applied)->toHaveCount(1)
        ->and($withCoupon->applied[0]['discount_cents'])->toBe(10000);
});
