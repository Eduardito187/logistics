<?php

declare(strict_types=1);

namespace Commerce\Promotions\Infrastructure\Providers;

use Commerce\Promotions\Application\Command\ActivatePromotion\ActivatePromotion;
use Commerce\Promotions\Application\Command\ActivatePromotion\ActivatePromotionHandler;
use Commerce\Promotions\Application\Command\ChangePromotionRules\ChangePromotionRules;
use Commerce\Promotions\Application\Command\ChangePromotionRules\ChangePromotionRulesHandler;
use Commerce\Promotions\Application\Command\CreatePromotion\CreatePromotion;
use Commerce\Promotions\Application\Command\CreatePromotion\CreatePromotionHandler;
use Commerce\Promotions\Application\Command\ExpirePromotion\ExpirePromotion;
use Commerce\Promotions\Application\Command\ExpirePromotion\ExpirePromotionHandler;
use Commerce\Promotions\Application\Query\EvaluatePromotions\EvaluatePromotions;
use Commerce\Promotions\Application\Query\EvaluatePromotions\EvaluatePromotionsHandler;
use Commerce\Promotions\Application\Query\GetPromotion\GetPromotion;
use Commerce\Promotions\Application\Query\GetPromotion\GetPromotionHandler;
use Commerce\Promotions\Application\Query\ListPromotions\ListPromotions;
use Commerce\Promotions\Application\Query\ListPromotions\ListPromotionsHandler;
use Commerce\Promotions\Domain\Contract\PromotionRepository;
use Commerce\Promotions\Infrastructure\Persistence\Eloquent\Repository\EloquentPromotionRepository;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Promotions (motor de promociones/cupones de comercio).
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover) para que el
 * orden de registro lo controle el proyecto. El núcleo SharedServiceProvider va primero.
 */
final class PromotionsServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        CreatePromotion::class => CreatePromotionHandler::class,
        ActivatePromotion::class => ActivatePromotionHandler::class,
        ExpirePromotion::class => ExpirePromotionHandler::class,
        ChangePromotionRules::class => ChangePromotionRulesHandler::class,
        EvaluatePromotions::class => EvaluatePromotionsHandler::class,
        GetPromotion::class => GetPromotionHandler::class,
        ListPromotions::class => ListPromotionsHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(PromotionRepository::class, EloquentPromotionRepository::class);
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
