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
 * Canal SMS de respaldo crítico con tope mensual (P1-NOT-05 / F0-FIRE-04). Habla por un **gateway de SMS
 * open-source y self-host** (no un SaaS de pago), vía HTTP configurable en `SMS_GATEWAY_URL`. Opciones
 * soportadas (ver README del package):
 *   - **android-sms-gateway** (envía por la línea de un teléfono Android propio; REST con Basic auth).
 *   - **Jasmin** / **Kannel** (SMPP/SMS gateways clásicos; exponen un endpoint HTTP de envío).
 *
 * Aunque el SMS tenga costo de operador, el transporte es gratis/open: no depende de Twilio ni similares.
 * Como aun así cada mensaje cuesta, se usa solo al final de la cascada y nunca por encima del tope del
 * mes: antes de enviar reserva un cupo del presupuesto del período de forma atómica; si el tope ya se
 * alcanzó, lanza (el relay no reintenta indefinidamente un envío imposible). Corre en el relay del
 * outbox; necesita el teléfono de respaldo del destinatario. Sin `SMS_GATEWAY_URL`, queda "no
 * configurado" y lanza para que el relay escale.
 *
 * @param array{url: ?string, token: ?string, username: ?string, password: ?string, sender: string, monthly_cap: int, timeout: int} $config
 */
final readonly class SmsChannelSender implements ChannelSender
{
    /**
     * @param array{url: ?string, token: ?string, username: ?string, password: ?string, sender: string, monthly_cap: int, timeout: int} $config
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

        $url = $this->config['url'] ?? null;
        if ($url === null || trim($url) === '') {
            throw NotificationsException::because('el canal SMS no está configurado (falta SMS_GATEWAY_URL del gateway open-source)');
        }

        $period = $this->clock->now()->format('Y-m');
        if (!$this->budgets->consume($period, $this->config['monthly_cap'])) {
            throw NotificationsException::because("se alcanzó el tope mensual de SMS del período {$period}");
        }

        // Auth del gateway: Basic (android-sms-gateway) tiene prioridad; si no, Bearer (Jasmin/Kannel con
        // shim) o sin auth (gateway en red interna cerrada).
        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;
        $token = $this->config['token'] ?? null;
        if ($username !== null && $username !== '') {
            $request = Http::withBasicAuth($username, (string) $password);
        } elseif ($token !== null && $token !== '') {
            $request = Http::withToken($token);
        } else {
            $request = Http::asJson();
        }

        // Payload genérico compatible con android-sms-gateway (`message` + `phoneNumbers`) y que los
        // gateways clásicos aceptan mapeando campos; `from` queda para el sender id cuando el gateway lo
        // respeta.
        $response = $request
            ->timeout($this->config['timeout'])
            ->post($url, [
                'from' => $this->config['sender'],
                'message' => $message->body,
                'phoneNumbers' => [$phone],
            ]);

        if ($response->failed()) {
            throw NotificationsException::because(
                "el gateway de SMS rechazó el envío a {$phone}: HTTP {$response->status()}",
            );
        }
    }
}
