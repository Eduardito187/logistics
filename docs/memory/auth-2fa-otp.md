---
name: auth-2fa-otp
description: 2FA (TOTP) unificado admin+app y reseteo de contraseña por OTP de 6 dígitos
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-22T02:31:39.249Z
---

**2FA (TOTP) unificado por usuario** (un secreto sirve en admin y app) + **reseteo de contraseña por
código OTP** (6 dígitos por email), para admin y clientes de la app. Todo local, sin CDN. Ver [[access-iam-context]].

**Núcleo compartido** (`app/Auth/`):
- `App\Auth\TwoFactor\TotpService`: TOTP estándar RFC 6238 **nativo** (HMAC-SHA1, 6 díg, 30s, secreto Base32;
  compatible con Google Authenticator y con el App MFA de Filament). `generateSecret()`, `otpauthUri(secret,
  holder, issuer='DisTrack')`, `verify(secret, code, window=1)`.
- `App\Auth\TwoFactor\RecoveryCodes::generate(n=8)`.
- `App\Auth\PasswordOtp\PasswordOtpService`: `issue(email)` (código 6 díg, hash+expira 10min en tabla
  `password_reset_otps`, rate-limit 60s, email `PasswordOtpMail`, silencioso si no existe), `verify(email,
  code)`, `reset(email, code, newPassword): bool`.
- Migración `2026_10_21_000001_add_two_factor_and_password_otp`: columnas en `users`
  `app_authentication_secret` (encrypted), `app_authentication_recovery_codes` (encrypted:array),
  `two_factor_confirmed_at`; tabla `password_reset_otps`.
- `App\Models\User` implementa los contratos Filament `HasAppAuthentication` + `HasAppAuthenticationRecovery`.

**Admin (Filament v4 MFA nativo):** en `AdminPanelProvider` → `->profile()` + `->multiFactorAuthentication(
[AppAuthentication::make()->recoverable()], isRequired: false)`. El setup en `/admin/profile` muestra **QR +
clave copiable + recovery codes**. Reset OTP: página `app/Filament/Pages/Auth/RequestPasswordOtp.php` de 2
pasos (la ruta nativa de reset de Filament es `signed`, incompatible con código → todo en la página request),
registrada con `->passwordReset(RequestPasswordOtp::class)`. Requiere composer libs **bacon/bacon-qr-code** +
**pragmarx/google2fa-qrcode** (Filament las declara como `suggest`, NO las instala `composer install` → hay
que `composer require`; se descargan a vendor/ = local, permitido).

**App (API `/api/v1/app`):** `POST auth/2fa/enroll` → `{secret, otpauth_uri, recovery_codes}`; `auth/2fa/confirm`
`{code}`; login con 2FA activo devuelve `{two_factor_required, challenge_id}` → `auth/2fa/verify` `{challenge_id,
code}` emite token (code = TOTP o recovery); `auth/2fa/disable` `{code|password}`; `auth/password/forgot`
`{email}`; `auth/password/reset` `{email, code, password}`. **Login de usuarios SIN 2FA no cambió el contrato.**
openapi/v1.yaml actualizado (login 200 = oneOf[AppLoginResponse, TwoFactorChallenge] + schemas TwoFactorEnrollment/
Challenge). ApiContractTest verde.

**Para probar el OTP en dev:** el correo sale por sendmail (sin catcher, ver [[perf-scale-and-mail]]); poner
`MAIL_MAILER=log` y leer el código en `storage/logs/laravel.log`.
