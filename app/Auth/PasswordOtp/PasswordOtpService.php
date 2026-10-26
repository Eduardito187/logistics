<?php

declare(strict_types=1);

namespace App\Auth\PasswordOtp;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Reseteo de contraseña por OTP de 6 dígitos (flujo de la app).
 *
 * El código se envía por email y se guarda SOLO su hash en `password_reset_otps`. Para no filtrar si
 * un email existe o no, `issue()` nunca lanza ni revela nada cuando el email no corresponde a un
 * usuario. El código vence a los 10 minutos y se invalida tras 5 intentos fallidos.
 */
final class PasswordOtpService
{
    /** Vigencia del código, en minutos. */
    private const EXPIRES_MINUTES = 10;

    /** Intentos de verificación permitidos antes de invalidar el código. */
    private const MAX_ATTEMPTS = 5;

    /** Ventana mínima entre emisiones para un mismo email, en segundos (rate-limit básico). */
    private const THROTTLE_SECONDS = 60;

    /**
     * Emite un OTP para el email dado y lo envía por correo.
     *
     * Silencioso: si el email no existe como usuario, o si se pidió otro código hace menos de
     * THROTTLE_SECONDS, no hace nada (no revela existencia ni lanza excepción).
     */
    public function issue(string $email): void
    {
        $email = $this->normalizeEmail($email);

        // No filtramos si el email existe: simplemente no emitimos.
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            return;
        }

        // Rate-limit básico: evita reenviar si hay una emisión muy reciente para este email.
        $recent = DB::table('password_reset_otps')
            ->where('email', $email)
            ->where('created_at', '>=', Carbon::now()->subSeconds(self::THROTTLE_SECONDS))
            ->exists();

        if ($recent) {
            return;
        }

        // Invalida cualquier OTP previo del email (solo uno vigente a la vez).
        DB::table('password_reset_otps')->where('email', $email)->delete();

        // Código de 6 dígitos (con ceros a la izquierda).
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_otps')->insert([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => Carbon::now()->addMinutes(self::EXPIRES_MINUTES),
            'attempts' => 0,
            'created_at' => Carbon::now(),
        ]);

        Mail::to($email)->send(new PasswordOtpMail($code, self::EXPIRES_MINUTES));
    }

    /**
     * Verifica un código contra el OTP vigente del email.
     *
     * Incrementa el contador de intentos en cada fallo; al llegar a MAX_ATTEMPTS invalida el código.
     */
    public function verify(string $email, string $code): bool
    {
        $email = $this->normalizeEmail($email);

        $otp = $this->currentOtp($email);
        if ($otp === null) {
            return false;
        }

        // Si ya se agotaron los intentos, se invalida y se rechaza.
        if ((int) $otp->attempts >= self::MAX_ATTEMPTS) {
            DB::table('password_reset_otps')->where('id', $otp->id)->delete();

            return false;
        }

        $valid = Hash::check($code, (string) $otp->code_hash);
        if (!$valid) {
            // Intento fallido: suma uno y, si alcanza el máximo, invalida el código.
            $attempts = (int) $otp->attempts + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                DB::table('password_reset_otps')->where('id', $otp->id)->delete();
            } else {
                DB::table('password_reset_otps')->where('id', $otp->id)->update(['attempts' => $attempts]);
            }
        }

        return $valid;
    }

    /**
     * Verifica el código y, si es válido, fija la nueva contraseña del usuario y consume el OTP.
     */
    public function reset(string $email, string $code, string $newPassword): bool
    {
        $email = $this->normalizeEmail($email);

        if (!$this->verify($email, $code)) {
            return false;
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            return false;
        }

        // El cast `hashed` del modelo se encarga de hashear la contraseña.
        $user->forceFill(['password' => $newPassword])->save();

        // Consume el OTP: ya no debe poder reutilizarse.
        DB::table('password_reset_otps')->where('email', $email)->delete();

        return true;
    }

    /**
     * Devuelve el OTP vigente (no expirado) más reciente del email, o null si no hay.
     */
    private function currentOtp(string $email): ?\stdClass
    {
        // `first()` devuelve stdClass|null; PHPStan nivel 8 permite leer propiedades de stdClass sin
        // "propiedad indefinida", así que verify() accede a id/code_hash/attempts directamente.
        return DB::table('password_reset_otps')
            ->where('email', $email)
            ->where('expires_at', '>=', Carbon::now())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Normaliza el email (minúsculas y sin espacios) para que la búsqueda sea consistente.
     */
    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
