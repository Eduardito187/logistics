<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\DashboardUpdated;
use App\Support\Staff\StaffInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;
use Throwable;

/**
 * Emite `dashboard.updated` para la fecha de un plan del tablero del día, fuera del request (cola `notifications`).
 *
 * Recibe la fecha (`Y-m-d`) y la ciudad ya resuelta por el observador (null = solo el canal general), y arma acá
 * los canales del tema `operations` que cubren el hecho ({@see RealtimeChannels::forEntity}): `operations.all`
 * (oficina) y, con ciudad, `operations.city.<departamento>` (planificador). Un solo intento (best-effort); sin
 * transporte de tiempo real no hace nada. Nunca deja escapar un error.
 */
final class BroadcastDashboardUpdated implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly string $planDate,
        public readonly ?string $city,
    ) {
        $this->onQueue((string) config('observability.metrics.queues.notifications', 'notifications'));
    }

    public function handle(): void
    {
        if (!StaffInbox::realtimeEnabled()) {
            return;
        }

        try {
            $channels = RealtimeChannels::forEntity(RealtimeChannels::TOPIC_OPERATIONS, ['city' => $this->city]);

            DashboardUpdated::dispatch($channels, $this->planDate);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
