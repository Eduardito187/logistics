<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Contract\ProductImageResolver;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\ProductThumbnail;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Throwable;

/**
 * Canal email transaccional (P1-NOT-04): envía por el mailer de Laravel, que usa el sendmail nativo
 * del host (sin SMTP propio ni catcher). Corre en el relay del outbox.
 *
 * Arma el HTML con la vista `notifications::email.order`, que muestra el cuerpo del mensaje y el listado
 * de productos del pedido con su imagen (thumbnail), nombre y cantidad. Si no se pudo resolver el
 * listado, la vista cae a solo el cuerpo (degradación sin romper).
 *
 * Necesita el email de respaldo del destinatario; si la notificación no lo tiene, lanza para que el
 * relay escale al canal siguiente de la cascada.
 */
final readonly class EmailChannelSender implements ChannelSender
{
    public function __construct(
        private ?string $mailer,
        private string $fromAddress,
        private string $fromName,
        private ViewFactory $views,
        private ?ProductImageResolver $products = null,
    ) {}

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        $email = $notification->email();
        if ($email === null) {
            throw NotificationsException::because('no hay email de respaldo para esta notificación');
        }

        $subject = $message->subject ?? $notification->messageType()->value;
        $html = $this->renderHtml($notification, $subject, $message->body);

        $mailer = $this->mailer !== null && $this->mailer !== '' ? Mail::mailer($this->mailer) : Mail::mailer();

        $mailer->html($html, function (Message $mail) use ($email, $subject): void {
            $mail->to($email)
                ->from($this->fromAddress, $this->fromName)
                ->subject($subject);
        });
    }

    /**
     * Renderiza la plantilla HTML del email con el listado de productos. Si la vista no está disponible
     * o el resolutor falla, cae al cuerpo renderizado tal cual.
     */
    private function renderHtml(Notification $notification, string $subject, string $body): string
    {
        try {
            $products = $this->products?->forShipment($notification->shipmentId()) ?? [];

            return $this->views->make('notifications::email.order', [
                'subject' => $subject,
                'body' => $body,
                'products' => array_map(static fn (ProductThumbnail $p): array => $p->toArray(), $products),
                'shipmentId' => $notification->shipmentId(),
                'trackingTopic' => $notification->fcmTopic()->value,
            ])->render();
        } catch (Throwable) {
            return $body;
        }
    }
}
