# ADR 009 — App web operativa en Vue + Inertia (híbrido con Filament)

Fecha: 2026-10-13. Estado: aceptado. Enmienda la elección de tecnología de [ADR 005](005-admin-filament-y-react.md).

## Contexto

El dueño quiere una **app web** (SPA, sin recargar entre páginas, barra de progreso superior) para la
operación interactiva, usando tecnologías modernas y dinámicas. El frontend actual del admin es **Filament**
(36 Resources + 3 Pages), no páginas blade: migrar "blade a Vue" no aplica. Reemplazar Filament por completo
sería reescribir CRUD/auth/RBAC/validación desde cero (meses, alto riesgo) y tirar el hardening ya hecho.

ADR 005 había decidido **React** vía Inertia solo para el tablero de planificación y la torre de control. El
dueño ahora prefiere **Vue** y un alcance operativo más amplio.

## Decisión

**Arquitectura híbrida:**
- **Filament (/admin)** se queda para todo el CRUD/configuración (datos maestros, flota, SAP, settings, etc.).
- Nueva **app web Vue 3 + Inertia** en **/app** para la operación interactiva: torre de control, planificación,
  rutas, entregas, pedidos (vista operativa), seguimiento. SPA con barra de progreso.
- Stack: Inertia (laravel adapter) + Vue 3 + Vite + Ziggy + Tailwind v4. Mapas con Leaflet + OSM (sin API key),
  consistente con el resto del proyecto (no Google Maps, enmienda ese detalle de ADR 005). Tema oscuro, rojo
  de marca #E2231A, textos en español.
- Auth compartida: la app /app usa la sesión web; invitado → login de Filament (sin duplicar auth).
- Solo lectura en el SPA por ahora: lee el read-side (QueryBus / *Record). Las escrituras de negocio siguen
  por admin/API (comandos del dominio + outbox).

**Se cambia React → Vue.** Motivo: preferencia del dueño; una sola tecnología de front nueva (Vue) en vez de
sumar React, menos superficie.

## Materialización

- Fundación: `resources/js/{app.js, Layouts/AppLayout.vue, Components/UI/*, Components/Map/RouteMap.vue}`,
  `resources/views/app.blade.php`, `app/Http/Middleware/HandleInertiaRequests.php`, grupo /app en
  `routes/web.php` que auto-incluye `routes/app/<modulo>.php`.
- Convenciones y contrato para construir módulos: `docs/frontend-spa.md`.
- Módulo de referencia (piloto): Operaciones/Rutas (listado + detalle con mapa y paradas clickeables).

## Consecuencias

- Dos tecnologías de front conviven (Filament/Livewire + Vue/Inertia), como ya anticipaba ADR 005. Aceptable:
  límites claros (/admin vs /app), auth y sesión compartidas.
- Gotcha de build: `npm`/`vite` corren en el HOST (el container no tiene Node); no lanzar dos `npm install`
  a la vez (ENOTEMPTY corrompe node_modules). El vendor de Filament debe estar en el host para compilar su tema.
