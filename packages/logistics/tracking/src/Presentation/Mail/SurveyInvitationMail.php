<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitación por email a calificar una entrega ya realizada (MÓDULO 1 — Calificación de envío).
 *
 * La envía el cron horario `logistics:tracking:send-survey-invitations` para cada envío ENTREGADO que
 * todavía espera calificación. Lleva el enlace público de la página de calificación (`/t/{token}`), que
 * ya vence por el TTL de Configuración (`tracking.public_link_ttl_hours`). No incluye UUIDs: solo el
 * token opaco dentro de la URL y la referencia humana de la orden. Texto en español neutro, marca DisTrack.
 */
final class SurveyInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string      $surveyUrl      URL absoluta de la página de calificación (contiene el token opaco).
     * @param string|null $customerName   Nombre del cliente para el saludo; null usa un saludo genérico.
     * @param string|null $orderReference Referencia HUMANA de la orden (nunca el UUID); null la omite.
     * @param string|null $expiresAtLabel Fecha de vencimiento del enlace ya formateada; null la omite.
     */
    public function __construct(
        public readonly string $surveyUrl,
        public readonly ?string $customerName = null,
        public readonly ?string $orderReference = null,
        public readonly ?string $expiresAtLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->orderReference !== null
            ? '¿Cómo te fue con tu entrega del pedido '.$this->orderReference.'?'
            : '¿Cómo te fue con tu entrega?';

        return new Envelope(subject: 'DisTrack · '.$subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'tracking::email.survey-invitation',
            with: [
                'surveyUrl' => $this->surveyUrl,
                'customerName' => $this->customerName,
                'orderReference' => $this->orderReference,
                'expiresAtLabel' => $this->expiresAtLabel,
            ],
        );
    }
}
