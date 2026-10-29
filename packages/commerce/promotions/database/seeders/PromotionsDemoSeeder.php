<?php

declare(strict_types=1);

namespace Commerce\Promotions\Database\Seeders;

use Commerce\Promotions\Application\Command\ActivatePromotion\ActivatePromotion;
use Commerce\Promotions\Application\Command\CreatePromotion\CreatePromotion;
use Commerce\Promotions\Infrastructure\Persistence\Eloquent\Model\PromotionRecord;
use Illuminate\Database\Seeder;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Datos demo de promociones. Idempotente y UUID-estable: cada promoción se crea solo si no existe (por
 * su clave natural: código de cupón / nombre de la promo automática). No regenera IDs en re-corridas.
 *
 * Usa el CommandBus (flujo transaccional del dominio + outbox), nunca escribe los Records directo.
 */
final class PromotionsDemoSeeder extends Seeder
{
    public function run(): void
    {
        /** @var CommandBus $bus */
        $bus = app(CommandBus::class);

        // Cupón BIENVENIDA: 10% de descuento (1000 basis points), activo.
        if (!PromotionRecord::query()->where('code', 'BIENVENIDA')->exists()) {
            $bus->dispatch(new CreatePromotion(
                name: 'Bienvenida 10%',
                type: 'percentage_off',
                value: 1000,
                code: 'BIENVENIDA',
            ));
            $record = PromotionRecord::query()->where('code', 'BIENVENIDA')->first();
            if ($record instanceof PromotionRecord) {
                $bus->dispatch(new ActivatePromotion($record->id));
            }
        }

        // Promoción automática: envío gratis a partir de Bs 500 (50000 centavos), activa, sin código.
        if (!PromotionRecord::query()->whereNull('code')->where('name', 'Envío gratis sobre Bs 500')->exists()) {
            $bus->dispatch(new CreatePromotion(
                name: 'Envío gratis sobre Bs 500',
                type: 'free_shipping',
                value: 0,
                minSubtotalCents: 50000,
            ));
            $record = PromotionRecord::query()
                ->whereNull('code')
                ->where('name', 'Envío gratis sobre Bs 500')
                ->first();
            if ($record instanceof PromotionRecord) {
                $bus->dispatch(new ActivatePromotion($record->id));
            }
        }
    }
}
