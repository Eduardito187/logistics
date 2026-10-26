<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Auth\PasswordOtp\PasswordOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reseteo de contraseña por código de un solo uso (OTP) para la app Flutter
 * (`/api/v1/app/auth/password/*`). Endpoints PÚBLICOS con límite anti fuerza bruta.
 *
 * Toda la lógica sensible (emisión del código, comparación en tiempo constante,
 * expiración y rehash de la contraseña) vive en {@see PasswordOtpService}; este
 * controlador solo valida la forma de la petición y nunca revela si un correo existe.
 */
final class PasswordResetController
{
    public function __construct(private readonly PasswordOtpService $passwordOtp) {}

    /**
     * Solicita un código de reseteo para el correo indicado. La respuesta es SIEMPRE
     * genérica (200): no revela si la cuenta existe, evitando enumeración de correos.
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // El servicio decide en silencio si emite o no (p. ej. si el correo no existe).
        $this->passwordOtp->issue($data['email']);

        return new JsonResponse([
            'message' => 'If the account exists, a reset code has been sent to the email.',
        ]);
    }

    /**
     * Restablece la contraseña con el código recibido por correo. 200 si el código es
     * válido y vigente; 422 (RFC 7807) si es inválido o expiró.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $ok = $this->passwordOtp->reset($data['email'], $data['code'], $data['password']);

        if ($ok === false) {
            throw ValidationException::withMessages([
                'code' => ['The reset code is invalid or has expired.'],
            ]);
        }

        return new JsonResponse(['message' => 'Password has been reset.']);
    }
}
