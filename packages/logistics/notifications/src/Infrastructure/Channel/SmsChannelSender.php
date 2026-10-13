<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel;

use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Contract\SmsBudgetRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Canal SMS de respaldo crítico con tope mensual (P1-NOT-05 / F0-FIRE-04). Es el único canal con costo
 * real por mensaje, por eso se usa solo al final de la cascada y nunca por encima del tope del mes.
 *
 * Antes de enviar, reserva un cupo del presupuesto del período de forma atómica; si el tope ya se
 * alcanzó, lanza (el relay no reintenta indefinidamente un envío imposible). Corre en el relay del
 * outbox; necesita el teléfono de respaldo del destinatario.
 */
final readonly class SmsChannelSender implements ChannelSender
{
    /**
     * @param array{endpoint: ?string, token: ?string, sender: string, monthly_cap: int, timeout: int} $config
     */
    public function __construct(
        private SmsBudgetRepository $budgets,
        private Clock $clock,
        private array $config,
    ) {}

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        $phone = $notification->phone();
        if ($phone === null) {
            throw NotificationsException::because('no hay teléfono de respaldo para esta notificación');
        }

        $endpoint = $this->config['endpoint'] ?? null;
        $token = $this->config['token'] ?? null;
        if ($endpoint === null || $endpoint === '') {
            throw NotificationsException::because('el canal SMS no está configurado (falta endpoint del proveedor)');
        }

        $period = $this->clock->now()->format('Y-m');
        if (!$this->budgets->consume($period, $this->config['monthly_cap'])) {
            throw NotificationsException::because("se alcanzó el tope mensual de SMS del período {$period}");
        }

        $request = $token !== null && $token !== '' ? Http::withToken($token) : Http::asJson();

        $response = $request
            ->timeout($this->config['timeout'])
            ->post($endpoint, [
                'from' => $this->config['sender'],
                'to' => $phone,
                'text' => $message->body,
            ]);

        if ($response->failed()) {
            throw NotificationsException::because(
                "el proveedor de SMS rechazó el envío a {$phone}: HTTP {$response->status()}",
            );
        }
    }
}
