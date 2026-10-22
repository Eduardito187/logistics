<?php

declare(strict_types=1);

namespace App\Auth\PasswordOtp;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo con el código OTP para restablecer la contraseña (flujo de la app DisTrack).
 *
 * Asunto y cuerpo en español neutro, pensados para el cliente final. El código viaja en claro SOLO
 * en este correo; en la base de datos se guarda su hash.
 */
final class PasswordOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $code             Código OTP de 6 dígitos.
     * @param  int     $expiresInMinutes Minutos de validez del código.
     */
    public function __construct(
        public readonly string $code,
        public readonly int $expiresInMinutes = 10,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'DisTrack · Código para restablecer tu contraseña',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-otp',
            with: [
                'code' => $this->code,
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }
}
