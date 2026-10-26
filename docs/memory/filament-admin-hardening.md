---
name: filament-admin-hardening
description: "Fixes del panel admin Filament: logout aleatorio, 404 de deep-links, gating por rol, opcache"
metadata:
  node_type: memory
  type: project
  originSessionId: 95be9a11-8872-4d3d-b8b2-ef0548264b66
  modified: 2026-10-13T19:59:14.878Z
---

Arreglos del panel admin (`App\Providers\Filament\AdminPanelProvider`, `bootstrap/app.php`) hechos el 2026-10-13 tras errores reales que reportó el dueño. Todos verificados.

**1. Logout aleatorio + "recordar" que no servía** → causa: `Filament\Http\Middleware\AuthenticateSession` en el stack `->middleware([...])` del panel. Cierra la sesión cuando el hash de password de la sesión no coincide; al re-autenticar por la cookie *remember*, la sesión nueva no trae ese hash → te expulsa. Si ocurre a mitad de una acción Livewire, además deja la URL "intended" rota. **Fix: quitarlo** del panel (el panel por defecto de Filament NO lo incluye; lo habían agregado a mano). `Authenticate::class` (en `->authMiddleware`) se queda: el login sigue exigido.

**2. Deep-links del admin daban 404** (`/admin/shipments/{uuid}`, `/admin/vehicle-routes/{uuid}`) → causa NO era ruta faltante (autenticado, todo da 200), sino que el **UUID del registro ya no existía** (tras un `migrate:fresh` que regeneró todos los IDs de demo — ver [[no-destructive-db-ops]]). El login devuelve al "intended" (link viejo) → 404 sin salida. **Fix en `bootstrap/app.php` `withExceptions`**: un render handler para `ModelNotFoundException` Y `NotFoundHttpException` que, si `GET` + `admin/*` + `Auth::check()`, redirige al listado del recurso (`/admin/<recurso>`, derivado de `$request->segments()`), con guardia anti-bucle (no redirigir si destino == ruta actual). Así NINGUNA URL del admin da 404: todo link viejo aterriza en el listado.
  - **GOTCHA Livewire**: dentro del render handler, `redirect()->to()` devuelve un `Livewire\Features\SupportRedirects\Redirector` (no un `Response`) en peticiones Livewire → TypeError. Usar `new \Illuminate\Http\RedirectResponse(url($target))` directo.
  - **GOTCHA tipo de excepción**: Filament lanza `ModelNotFoundException` (route-binding), que las render-callbacks ven ANTES de convertirse a `NotFoundHttpException`. Registrar AMBOS tipos.

**3. Gating por rol del admin** (ver [[access-iam-context]]): el login usa `App\Models\User` con roles por enum (`HasRoles` + `App\Enums\Role`), NO `access_users`. `User::canAccessPanel()` = solo Admin/Supervisor; `PlatformStatsWidget::canView()` = Admin/Supervisor; los 4 Resources de Access (IAM) = solo Admin (trait `Logistics\Access\...\Filament\Concerns\AdminOnly`, compara rol por valor string 'admin' sin acoplar el package a `App\*`). `user_roles` estaba VACÍO → el `DatabaseSeeder` ahora asigna Admin a `admin@dismac.test` y `ehhuallata@dismac.com.bo`.

**4. opcache**: el container `app` tiene `opcache.enable=On` con `validate_timestamps=On`, `revalidate_freq=0` → revalida por mtime en cada request, así que los cambios de código SÍ se toman solos. Aun así, tras tocar `bootstrap/app.php`/providers conviene `docker compose restart app` para garantizar código vivo (health: `curl -s -o /dev/null -w "%{http_code}" http://localhost:8110/up`).

**5. Sesión/cache en Valkey**: `SESSION_DRIVER=redis` DB 0, `CACHE` redis DB 1 (separadas, el cache no evicciona sesiones); Valkey sin `maxmemory`/`noeviction` → las sesiones no se borran por memoria. `SESSION_LIFETIME=120`.
