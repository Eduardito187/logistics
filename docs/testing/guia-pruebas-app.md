# Guía de pruebas — DisTrack (app Flutter + panel) · hoy y mañana

Datos de prueba sembrados para probar TODA la app con TODOS los roles, para **hoy** y **mañana**.
Backend: `http://localhost:8110` · API base `http://localhost:8110/api/v1` · Panel: `http://localhost:8110/admin`.

Re-sembrar en cualquier momento (idempotente, aditivo, no borra nada):
```
docker compose exec -T app php artisan db:seed --class="Database\Seeders\TestDataSeeder" --force
```

## Cuentas de prueba (contraseña: `password123`)

| Rol | Email | Qué probar |
|-----|-------|------------|
| Admin | `admin@dismac.test` | Todo el panel; activar 2FA en /admin/profile |
| Supervisor | `supervisor@dismac.test` | Torre de control, telemetría en vivo (hoy), planes/flota |
| Chofer | `chofer@dismac.test` | Ruta del día, preparar/cargar/escanear, POD con foto, "no se pudo entregar", reportar inconveniente |
| Preparador | `preparador@dismac.test` | Cola de preparación (escaneos de bultos) |
| Tienda | `tienda@dismac.test` | Recepción de mercadería + recojo en tienda (nodo T-SCZ-01) |
| Transportista | `transportista@dismac.test` | Seguimiento de envíos de última milla |

Login app (Flutter): `POST /api/v1/app/auth/login` con `{ email, password, device:{fingerprint,platform,name,push_token} }`.

## Datos por día y rol

- **Chofer** (camión y ruta los mismos ambos días):
  - HOY (2026-10-21, ruta EN CURSO): 7 paradas, 2 entregadas. Variedad extra: 1 ENTREGADA con foto, 1 EN CURSO lista para POD, 1 FALLIDA (cliente ausente), 1 con INCONVENIENTE (DELAY) reportado sin cerrar.
  - MAÑANA (2026-10-22, ruta PLANIFICADA/fresca): 7 paradas, nada entregado → probar el flujo completo desde cero. Variedad: 1 EN CURSO lista para POD, 1 INCONVENIENTE (ACCESS).
  - 19 envíos con entrega hoy, 17 mañana; todos con teléfono, dirección+referencia, coordenadas reales de Santa Cruz y productos con imagen.
- **Tienda** (`T-SCZ-01`): cada día 3 recepciones abiertas + 1 recojo. HOY el recojo ya conciliado (listo en mostrador); MAÑANA todo pendiente (recibir desde cero).
- **Preparador**: 3 envíos recibidos por día con bultos y etiquetas QR listos para escanear.
- **Supervisor**: recorrido GPS en vivo (telemetría) HOY; mañana el vehículo arranca en reposo.
- **Transportista**: `Expreso Cruceño (prueba)` con liquidación del período 2026-10 (corredor CD→tienda).
- **Tracking público** (sin login): `http://localhost:8110/... ` con tokens, p.ej.
  `kZQOHNpwHxWBl1R90p6hFydW1fuJJDi9ty3Kqn9UVQE`, `QnX4OhpZpYTpoCtmALjM_7l3BVca25aaYw8rnDAK-Hk` (hay ~12).
- **Notificaciones**: 17 encoladas (EN_ROUTE / IN_PREPARATION) en el outbox.

## Doble autenticación (2FA · TOTP)

Unificada por usuario (un secreto TOTP sirve en admin y app). QR escaneable o clave visible.

- **Admin (panel):** `/admin/profile` → "App authentication" → muestra **QR + la clave** + **códigos de recuperación**. Escaneá con Google Authenticator/Authy (o tipeá la clave), confirmá el código. En el próximo login el panel pedirá el código. Opcional por usuario (no forzado).
- **App (Flutter):**
  - `POST /api/v1/app/auth/2fa/enroll` (autenticado) → `{ secret, otpauth_uri, recovery_codes[] }` (la app dibuja el QR del `otpauth_uri` o muestra `secret`).
  - `POST /api/v1/app/auth/2fa/confirm` `{code}` → activa.
  - Login con 2FA activo: `login` responde `{ two_factor_required:true, challenge_id }`; luego `POST /app/auth/2fa/verify` `{challenge_id, code}` → emite el token. (Usuarios SIN 2FA: login igual que siempre.)
  - `POST /app/auth/2fa/disable` `{code|password}`.

## Reseteo de contraseña por OTP (código de 6 dígitos por email)

- **Admin:** en el login → "Forgot password?" → email → ingresá el código recibido + nueva contraseña (una sola pantalla de 2 pasos). No revela si el email existe.
- **App:** `POST /app/auth/password/forgot` `{email}` → `POST /app/auth/password/reset` `{email, code, password}`.

> **Para VER el código OTP en desarrollo:** el email sale por `sendmail` nativo (sin catcher). Para capturar el
> código al probar, poné `MAIL_MAILER=log` en `.env` y leé `storage/logs/laravel.log` (ahí queda el correo con el código).

## Notas
- Clave de firma de etiquetas QR agregada al `.env` (dev). Clave pública para la app:
  `1:1CesIThRjOq06ZsIeIumHVOQKZiXXSZf5h4b/MSuHKU=`.
- Backup pre-seed en el contenedor postgres: `/tmp/pre_seed_backup.dump`.
- 2FA/QR 100% local (bacon-qr-code + google2fa vendorizados en `vendor/`, sin CDN).
