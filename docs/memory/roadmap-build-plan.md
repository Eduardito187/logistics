---
name: roadmap-build-plan
description: "Estado real del backend vs roadmap, y el plan de construcción por olas con agentes"
metadata:
  node_type: memory
  type: project
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-15T00:50:22.168Z
---

El usuario pegó un "Roadmap — Plataforma logística Dismac" (fases 0–3, IDs tipo P1-SHP-05) y pidió completarlo con agentes. Dos verdades importantes:

**IMPORTANTE — la rama con el trabajo real es `production` (origin/production), NO `feature/initial-scaffold`.** Siempre hacer `git fetch --all` antes de juzgar el estado de las ramas: los refs locales llegan desactualizados. Verificado el 2026-10-13: `origin/production` (commit 56ee7b9, vía PRs dev→staging→production) tiene el build DDD/CQRS completo: **16 packages, 572 archivos PHP** — shared, outbox, settings + los 9 contexts de Fase 1 + los 4 de Fase 2 (capacity, promise, sap-connector, carriers) + docs/discovery, tools/discovery, docs/roadmap.md, docs/adr/008, app/Http, routes. `feature/initial-scaffold` era una rama vieja con solo el scaffold + labels; NO trabajar ahí. (Error cometido una vez: revisar ramas sin `fetch` mostró todo en 3be2f0d y llevó a construir `shared` desde cero en la rama vieja — descartado vía stash.) Docker corre (7 containers) y el toolchain está en el container `app`: Pest 4.7.8, PHPStan 2.3.1, Pint.

**El dueño pidió NO ejecutar tests/phpstan/pint — solo completar el código y que funcione. Prohibió verificar con pest/phpstan/pint; sí se permite migrate/seed/route:list/dump-autoload (operación de app, no tests).** NO es "terminado" por la regla #1 de CLAUDE.md hasta correr la verificación real; dejar claro eso.

**Estado al cierre de la sesión 2026-10-13 (rama production, trabajo local sin commitear — ~417 archivos cambiados):**
- **Outbox wireado** en los 34 handlers vía `TransactionalCommandBus` (decorator en shared/Infrastructure, bindeado en SharedServiceProvider; atómico save+outbox). 0 TODO(outbox).
- **API**: 133 rutas en las 3 puertas (`/api/v1/app|channels|integrations`), ~82 endpoints por caso de uso, auth Sanctum+roles+dispositivos, `openapi/v1.yaml` con 71 paths/102 schemas.
- **Admin Filament funcional**: 31 Resources registrados (discoverResources por package en `AdminPanelProvider`) + dashboard. **0 errores de navegación, 0 de query.** Usuario admin de dev: `admin@dismac.test` / `password123`.
- **DB**: 30 migraciones + 15 seeders corren limpios (`migrate:fresh --seed`). Datos demo: 9 nodos, 8 vehículos, 6 pedidos, 5 transportistas, 54 cupos, 112 mensajes outbox. Relay del outbox programado en scheduler.
- **Errores arreglados** (logs limpiados a 0): página Filament redundante `MessageTypeRules` eliminada; closures `static fn` en factories → no-static; PSR-4 de seeders/factories de packages mapeado en composer.json RAÍZ (namespace completo→carpeta minúscula); `DatabaseSeeder` idempotente. Los gotchas quedaron documentados en el skill `bounded-context`.

**Sesión UX + i18n (2026-10-13, también sin commitear):**
- **Admin usable**: 31 Filament Resources con datos legibles (relaciones/joins, nombres/placas/códigos + badges en vez de UUIDs; UUIDs toggleable ocultos) y **flujos transaccionales** (acciones que ejecutan los Command vía `app(CommandBus::class)->dispatch(...)`, no CRUD). 0 errores de nav/query verificados.
- **Marca**: primary del panel = rojo Dismac `#E2231A` (ver [[brand-palette]]).
- **i18n**: código en inglés, admin en español. `APP_LOCALE=es`; Filament trae su `es`; nuestros textos envueltos en `__()` + `lang/es.json` (767 claves, diccionario fuente→es estilo Magento). Login dev: `ehhuallata@dismac.com.bo` / `Dismac*2026` (User implementa FilamentUser, entra en cualquier entorno).
- Errores arreglados en el camino: relación `unit` inválida en ScanEventResource (unit_id es código, no UUID); getters de Filament deben seguir `static`. Gotchas 8–10 añadidos al skill `bounded-context`.

Pendiente (fuera de agentes de código): app Flutter (repo aparte), tableros React, infra/deploy, Fase 0 operativa; y la verificación formal (pest+phpstan8+pint) que el dueño pospuso.

**Contexto completo ya guardado en el repo** (del zip `logistics-contexto.zip`, 2026-10-13): `ALCANCE-Y-CONTEXTO.md` (resumen + decisión del dueño), `docs/roadmap.md` (roadmap completo con IDs), `docs/adr/008-ddd-cqrs-uuidv7-outbox.md` (autoritativo), toolkit Fase 0 en `docs/discovery/` (plan, KPIs, order-import, coordinate-quality, fleet-nodes, satisfaction, access-checklist + `templates/*.csv`) y `tools/discovery/{coordinate_quality.php,validate_order_import.php}` (PHP stdlib ejecutables). El skill `bounded-context` se corrigió con la versión del zip (code fences correctos).

**Decisión del dueño (arquitectura objetivo)**: respuestas rápidas sobre millones de registros, procesos transaccionales simultáneos, DDD+CQRS+Clean para aislar errores, orientada a microservicios (cada contexto extraíble a servicio). Ver `ALCANCE-Y-CONTEXTO.md` y [[project-architecture]].

**Gran parte del roadmap NO es delegable a agentes de código**: Fase 0 Discovery (datos reales, acceso Firebase, inventario físico de flota, proveedor SMS), la app Flutter (repo aparte `logistics-app`, no está acá), el piloto (ciudad/camiones/gente reales), e infra/deploy/secrets/entornos (credenciales y decisiones de ops).

**Plan de construcción por olas** (dependencias obligan secuencia; todo compila contra `shared`, y los 9 contexts editan los mismos 2 archivos raíz `bootstrap/providers.php` + `composer.json` dont-discover → un integrador serial, no 9 en paralelo):
1. **Ola 1 Fundación** (workflow `logistics-foundation`, run wf_feb4f024-fd8): shared (UUID v7 + CQRS + AggregateRoot) + ADR 008 → outbox + settings → integrar y verificar suite completa en containers. Debe quedar verde antes de abrir el fan-out.
2. **Ola 2 Contexts**: los 9 de Fase 1 en paralelo (código + migraciones + Unit + Feature + phpstan8 + pint por package), integrador serial registra en raíz y corre la suite completa.
3. **Ola 3 Fase 2**: capacity, promise, sap-connector, carriers.
4. **Ola 4 Transversales**: auth/Sanctum, 3 puertas de API, OpenAPI, Filament, outbox panel.

Cada paquete sigue el skill `bounded-context` y la DoD de CLAUDE.md. Ver [[project-rules]] y [[project-architecture]].

## Features construidas (sesión 2026-10-13, local sin commitear)
Se construyó con oleadas de agentes (workflows) casi todo el backend + admin, verificado funcionalmente (boot + migrate:fresh --seed + render de resources; NO con pest/phpstan/pint, el dueño los pospuso):
- **16 packages** DDD/CQRS + `logistics/access` (RBAC). Outbox wireado en los 34 handlers (TransactionalCommandBus).
- **API 3 puertas** (app/channels/integrations, Sanctum+roles), **OpenAPI** `v1.yaml`.
- **Admin Filament** completo: ~36 resources con datos legibles (relaciones, no UUIDs) + **flujos transaccionales** (acciones que despachan Commands vía CommandBus, no CRUD), i18n es, tema de marca compilado, dashboard con widgets. Ver [[admin-ui-maps]].
- **Mapas OSM/Leaflet**: editor de coberturas (polígonos PostGIS), mapa de ruta (planificada OSRM server-side + GPS road-snap), perfil de cliente, ubicación de pedido.
- **Ruteo**: VRP Clarke-Wright + 2-opt sobre OSRM `/table`, backend enchufable (builtin/osrm/vroom), auto-planner 2 modos (optimization/slots) async por colas, calendario de despacho + slots. Ver [[routing-geo-rule]].
- **Notificaciones** multicanal: WhatsApp, Telegram, Push FCM (+collage GD), web push, email, SMS; 14 tipos de mensaje con plantillas + interpolación de variables.
- **Cliente**: CI/teléfono/correo en el pedido + página `CustomerProfile`. **Slots de entrega** por pedido.
- **Incidentes de camión** (avería/accidente): alerta del chofer → reprogramación automática (grave: día siguiente + vehículo fuera de servicio; leve: retraso) + notificaciones.

## Sesión 2 (2026-10-13): Fase V + refinamientos — DoD VERDE y commiteado
Rama `feature/plataforma-logistica-completa` (2 commits: 211163f + 477194b). **DoD lograda**: Pest **955 passed** (2693 aserciones), PHPStan nivel 8 **limpio**, Pint **limpio** (2219 archivos). Admin 41 resources / 6 pages, 0 errores. es.json 1082 claves.
- **Timezone configurable** (APP_TIMEZONE, default America/La_Paz). El dueño **levantó la regla de no-ejecutar-pest** (ver [[no-ejecutar-tests]], obsoleta).
- Refinamientos agent-doable completados por oleadas: ruteo VRP+VRPTW+geometría server-side+road-snap; auto-planner 2 modos+slots; promesa integrada; recepción en nodos+offline+POD S3; tracking per-bulto+GPS vivo+página pública; network 6 corredores; SAP ACL evento→outbox; coverage jerarquía completa+KML; notifications variables+reglas versionadas+alertas docs; fleet turnos+docs S3; **fleetops** (mantenimiento/gastos/perfil vehículo, contexto nuevo); access gestión+auditoría; geocoder real (Google/Nominatim); índices+cache; RFC7807+rate-limit+CSP.
- **Infra/ops (código/config/docs, no desplegado):** OSRM/VROOM self-host (compose+runbook `docs/routing-selfhost.md`), Dockerfile prod multi-stage+colas+S3+supervisor (`docs/deploy.md`), CI+make assets, logging estructurado+observabilidad (`docs/observability.md`), backups (`scripts/backup-db.sh`+`docs/backup-restore.md`), seguridad (`docs/security.md`). Roadmap detallado vivo en `docs/roadmap.md`.

**Pendiente — NO agent-doable (necesita desbloqueo externo):** correr en infra real (staging/prod deploy), credenciales reales (FCM/Firebase, VAPID, WhatsApp/SMS, SAP, Google key — hoy stubs/sandbox), **app Flutter** (`logistics-app`, repo aparte no presente), **tablero React** (hay un `PlanningBoardController` app-level base), **datos reales de Fase 0** (flota/nodos/coordenadas), **piloto físico**, y **Fase 3** (costos/pronóstico/simulador/rentabilidad). Todo listo para enchufar vía env/deploy. `push`/PR pendiente de pedido del dueño.

## Sesión 3 (2026-10-14): backlog agent-doable casi cerrado + features nuevas — VERDE y commiteado
Rama `feature/plataforma-logistica-completa`. 2 commits nuevos: `840ad01` (open-source sin Google + fallback
de imágenes) y `58c19dc` (flota ampliada, calendario de despacho, Fase 3 costos, canales, i18n, hardening).
**DoD: Pest 1119 passed (3246 aserciones), PHPStan 8 limpio, Pint limpio.** Hecho por OLAS de agentes:
- **Flota**: vehículos (marca/modelo/año/VIN/fotos 4 lados + docs SOAT/inspección) y choferes (ciudad) +
  **sistema de alertas de vencimiento 30d/7d/vencido** (widget + scheduler). Ver [[fleet-personnel-expansion]].
- **Calendario de despacho por municipio** (7 días, feriados, activación por rango, enforcement). Ver
  [[dispatch-calendar-municipality]].
- **Fase 3 costos** (3-01/3-06): costo por vehículo/corredor/tercero + rentabilidad enchufable (fleetops).
- **Canales/cliente**: K-06 encuesta de satisfacción (NPS) + K-04 web push VAPID en tracking público.
- **Bloque A técnico**: `.env.example` (NTFY_URL vacío + vars muertas), tiles configurables, CSP sin Google
  Fonts + **Open Sans self-host**, telemetry UUID oculto, índice único capacity, typo doc.
- **CI/infra**: V-15 gate de cobertura real (pest --min=75), V-19 build de assets doc/idempotente, **MinIO**
  local (B2), E-05 idempotencia de API (**ya estaba hecha**, solo faltaban tests).
- **i18n**: causa del spanglish = claves `__()` sin traducir; `es.json` a 1605 claves (admin 100% español);
  SPA ya en español. Se borró la memoria obsoleta `no-ejecutar-tests` (la regla fue derogada).
- Coordinado con sesiones paralelas (panel de integraciones, telemetry+Reverb). Ver [[multisession-shared-tree]].

**FALTA agent-doable (en curso/pendiente, sesión 3): OLA 2c** — R-04 restricciones de vehículo + R-05 re-opt
incremental + R-06 métricas de ruta (planning); K-03 reserva de slot + K-05 promesa a canales (/channels);
E-02 paginación; V-14 tests de contrato OpenAPI (necesita `composer require` de spectator); documentar
auth/devices en openapi. **La ola 2c de 5 agentes topó el límite de sesión** (resetea 7:20am La Paz) sin
escribir nada; se reintenta más liviana. El resto NO agent-doable sigue igual (deploy, credenciales, Flutter,
Fase 0, piloto, Fase 3 avanzada 3-02..3-09).
