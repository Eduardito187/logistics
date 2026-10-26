---
name: access-iam-context
description: "El bounded context access (IAM): identidad, RBAC dinámico y tokens de integración"
metadata:
  node_type: memory
  type: project
  originSessionId: 95be9a11-8872-4d3d-b8b2-ef0548264b66
  modified: 2026-10-13T18:26:33.228Z
---

Contexto `packages/logistics/access` (namespace `Logistics\Access\`), construido el 2026-10-13 en
paralelo con subagentes (3 núcleo por grupo de agregado + 3 presentación/tests). Es la **autoridad de
autorización** de la plataforma, en DDD+CQRS+Clean, UUID v7 y outbox. Es **aditivo**: NO reemplaza el
auth estático heredado (`app/Enums/Role`, `app/Enums/Ability`, Sanctum, Filament login) — convive y lo
**siembra** como catálogo. La bifurcación de superar del todo al enum (migrar Filament/endpoints a los
permisos dinámicos) quedó como paso futuro que el dueño decide activar.

Agregados: `Permission` (catálogo), `Role` (dinámico, editable, permission_keys jsonb), `User`
(identidad + credenciales + roles), `IntegrationToken` (permisos **seleccionables**, secreto revelado
una sola vez). Tablas: `access_permissions`, `access_roles`, `access_users`, `access_integration_tokens`.

Piezas clave:
- `Application/Service/PermissionResolver`: usuario→roles→permisos (unión, respeta `*`); `grants()` estático.
- `Application/Service/IntegrationTokenIssuer`: `issue()/rotate()` devuelven el plaintext una vez (el
  resto del CQRS es void). Secreto = "itk_<prefijo>.<secreto>"; solo se persiste el hash (SHA-256, `hash_equals`).
- Puertos de dominio: `PasswordHasher` (bcrypt), `TokenHasher` (SHA-256), `SecretGenerator` (CSPRNG) — en `Infrastructure/Security`.
- Middleware (alias en `AccessServiceProvider`): `access.token` (autentica token de integración por
  `Authorization: Bearer`) y `access.permission:<key>` (exige permiso). Carril paralelo al `service.auth` heredado.
- API: 24 rutas puerta `app` (gestión RBAC + tokens) + 1 `integrations/access/token/verify`; fragmento
  `routes/api/access.php`; OpenAPI aditivo (19 paths, schemas Permission/Role/User/IntegrationToken/IssuedToken/TokenVerification).
- Admin Filament: 4 Resources (Permissions, Roles, Users, Integration Tokens) en grupo `Access`, acciones transaccionales.
- Seeder `Logistics\Access\Infrastructure\Seeder\AccessSeeder` (registrado en DatabaseSeeder, va primero):
  24 permisos (20 de Ability `grupo:accion`→`grupo.accion` + 4 de módulo `access.*.manage`), 6 roles de
  sistema desde `app/Enums/Role` (admin = `['*']`), 1 user admin `admin@dismac.test`/`password123`.

Registrado en los 3 lugares de siempre: `bootstrap/providers.php` (tras Settings), `composer.json` raíz
(require + dont-discover), `AdminPanelProvider` (discoverResources + NavigationGroup Access).

**Gateo por rol del admin Filament (2026-10-13)**: el login de Filament usa `App\Models\User` con roles
por **enum** (`HasRoles` + `App\Enums\Role`), NO la tabla `access_users` (siguen sin puentearse — es el
"flip the switch" pendiente). Reglas aplicadas: `User::canAccessPanel()` = solo Admin/Supervisor (los
roles de campo chofer/preparador/tienda/transportista usan la app Flutter, no el admin);
`PlatformStatsWidget::canView()` = Admin/Supervisor; los 4 Resources de Access (IAM) = solo Admin vía el
trait `Logistics\Access\...\Filament\Concerns\AdminOnly` (comprueba rol por valor string 'admin' para no
acoplar el package a `App\*`). **Importante**: `user_roles` estaba VACÍO; el `DatabaseSeeder` ahora asigna
el rol Admin a `admin@dismac.test` y `ehhuallata@dismac.com.bo` (idempotente) — sin eso el panel se bloquea.
Widgets del escritorio borrados por pedido del dueño: `QuickLinksWidget` (+ su blade) y `AccountWidget`
(logout redundante); el logout real del menú superior de Filament se mantiene.

**DoD CUMPLIDA (2026-10-13)** para el módulo access (regla #1): **Pint** PASS; **PHPStan nivel 8 limpio**
en todo el package + archivos app tocados (se agregó a `phpstan.neon` un ignore de `method.childReturnType`
para `packages/logistics/*/.../Factory/*`, mismo falso positivo que ya se ignoraba en UserFactory);
**Pest** 64 tests / 128 aserciones del módulo verdes (incl. 9 Feature contra PostgreSQL), y **suite
completa 474 tests / 1283 aserciones verde**. Verificación en vivo: migrate+seed(AccessSeeder)+route:list
(25 rutas)+Filament+gateo por rol.

**Limpieza repo-wide RESUELTA (2026-10-13, 5 agentes en paralelo por grupos de contextos disjuntos):**
- **PHPStan nivel 8 GLOBAL = 0 errores** (antes 201 en 13 contextos). Arreglos REALES, sin bajar a `mixed`
  ni ignores salvo uno de config legítimo: se extendió en `phpstan.neon` el ignore de `method.childReturnType`
  a `packages/logistics/*/src/Infrastructure/Persistence/Eloquent/Factory/*` (mismo falso positivo que
  UserFactory). Varios bugs reales corregidos por los agentes: clase Filament v4 `Component` mal ubicada
  (`Forms\Components\Component`→`Schemas\Components\Component`), `@extends Resource<...>` faltante,
  `Request::date()`/`Factory::store()` sombreados (renombrados `calendarDate`/`asStore`), loop muerto en
  `OsrmRouteOptimizer`, `pluck` con alias en outbox, comparaciones muertas (`isset && !== null`), y tipos
  `list<string>` con `array_values`. Factories de capacity/execution reubicadas de `database/factories/` a
  `src/Infrastructure/Persistence/Eloquent/Factory/`.
- **Suite completa Pest: 474 passed / 1283 aserciones.** **Pint: PASS (1478 files).** DoD global del repo cumplida.
- **Seeders demo idempotentes**: `CarriersDemoSeeder` con guardia (ya no rompe por `carriers_tax_id_unique`);
  el resto de demo-seeders (planning/fleet/labels/coverage/outbox/promise/shipments/network/execution…) se
  hicieron idempotentes con guardia de early-return para que `db:seed` sea re-ejecutable sin wipe
  (regla no-destructiva [[no-destructive-db-ops]]). El workflow previo era `migrate:fresh --seed`.

Ver [[project-rules]], [[project-architecture]], [[roadmap-build-plan]].
