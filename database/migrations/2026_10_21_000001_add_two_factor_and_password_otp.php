<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega 2FA (TOTP/App MFA) a las cuentas de usuario y una tabla de OTP de reseteo de contraseña.
 *
 * ADITIVO e IDEMPOTENTE: cada columna/tabla se crea solo si no existe, así puede re-aplicarse sin
 * romper. Nada destructivo.
 *
 * - `users.app_authentication_secret`: secreto TOTP del autenticador, CIFRADO en reposo (cast
 *   `encrypted` en el modelo). TEXT porque el ciphertext de Laravel es más largo que el secreto.
 * - `users.app_authentication_recovery_codes`: códigos de recuperación, CIFRADOS como array
 *   (cast `encrypted:array`). TEXT por el mismo motivo.
 * - `users.two_factor_confirmed_at`: cuándo el usuario confirmó el enrolamiento de 2FA (null = no
 *   confirmado todavía).
 *
 * - `password_reset_otps`: OTP de 6 dígitos para reseteo de contraseña por código (flujo de la app),
 *   SEPARADA de la tabla `password_reset_tokens` por-enlace que usa Laravel por defecto. Guardamos el
 *   HASH del código, nunca el código en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'app_authentication_secret')) {
                // Secreto TOTP (Base32) cifrado en reposo; TEXT para alojar el ciphertext.
                $table->text('app_authentication_secret')->nullable()->after('password');
            }

            if (! Schema::hasColumn('users', 'app_authentication_recovery_codes')) {
                // Códigos de recuperación cifrados (array serializado y cifrado).
                $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
            }

            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                // Momento en que el usuario confirmó el enrolamiento de 2FA.
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('app_authentication_recovery_codes');
            }
        });

        if (! Schema::hasTable('password_reset_otps')) {
            Schema::create('password_reset_otps', function (Blueprint $table): void {
                $table->id();
                $table->string('email')->index();
                // Hash del código de 6 dígitos (nunca en claro).
                $table->string('code_hash');
                // Expiración del OTP (zona horaria explícita).
                $table->timestampTz('expires_at');
                // Intentos de verificación fallidos; a los 5 se invalida.
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestampTz('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['app_authentication_secret', 'app_authentication_recovery_codes', 'two_factor_confirmed_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('password_reset_otps');
    }
};
