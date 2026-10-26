---
name: test-data-seeders
description: "Datos de prueba para la app Flutter — TestDataSeeder (hoy+mañana, todos los roles) + cuentas"
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-22T02:31:57.177Z
---

Para testear toda la app (Flutter + panel) con todos los roles, para **hoy y mañana**, hay un orquestador
de seed idempotente. Ver [[seed-data-cross-refs]] y [[no-destructive-db-ops]].

**Correr (idempotente, aditivo, re-ejecutable):**
`docker compose exec -T app php artisan db:seed --class="Database\Seeders\TestDataSeeder" --force`

**Piezas:**
- `database/seeders/TestDataSeeder.php` (orquestador): corre fundaciones si faltan (Access/Network/Fleet/
  Coverage/Capacity/Settings/Sellers/Carriers), CapacitySeeder siempre (cupos hoy+2d), y el escenario.
- `TodayScenarioSeeder` parametrizado por fecha+etapa: `buildForDate($date, STAGE_IN_PROGRESS|STAGE_PLANNED)`.
  HOY = ruta EN CURSO (2 paradas entregadas); MAÑANA = ruta PLANIFICADA/fresca (nada entregado). Crea los
  usuarios por rol y la tripulación. Constantes CD_CODE='CD-SCZ-01', STORE_CODE='T-SCZ-01'.
- `database/seeders/TestDataVarietySeeder.php`: estados variados de entrega (entregada+foto, en curso lista
  POD, fallida, inconveniente reportado), cola de preparación, transportista+liquidación, tracking con tokens,
  notificaciones por outbox, clientes con teléfono/dirección/coords de Santa Cruz + productos con imagen.
- Idempotencia: external_id con sufijo de fecha `ymd` (ej. `SCZ-261021-01`, `VAR-261022-POD`); plan_date/
  route_date por día; firstOrCreate/updateOrCreate/dispatch idempotente. Mismo chofer↔vehículo↔tienda ambos días.

**Cuentas de prueba (tabla `users`, la del login `App\Models\User`), contraseña `password123`:**
`admin@dismac.test` (admin), `supervisor@dismac.test`, `chofer@dismac.test` (↔ driver_id + vehículo + rutas),
`preparador@dismac.test`, `tienda@dismac.test` (↔ node_id T-SCZ-01), `transportista@dismac.test`.
Login app: `POST /api/v1/app/auth/login` `{email, password, device:{fingerprint,...}}`. Backend `http://localhost:8110`.

**OJO tablas de usuario:** el login usa `users` (App\Models\User con driver_id/node_id/roles vía user_roles);
el contexto access tiene su propio `access_users` (UserRecord del AccessSeeder = solo admin@dismac.test). Los
usuarios de prueba por rol viven en `users`. Etiquetas QR requieren `LABELS_SIGNING_KEY` en `.env`
(`php artisan logistics:labels:keygen` imprime la clave, hay que pegarla al .env). Guía: `docs/testing/guia-pruebas-app.md`.
