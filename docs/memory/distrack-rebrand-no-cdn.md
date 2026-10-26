---
name: distrack-rebrand-no-cdn
description: Rebrand del panel interno a DisTrack + regla CERO CDN externo (todo local) + páginas de error en lenguaje de cliente
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-22T02:30:59.555Z
---

El panel/backend interno se rebrandeó de "Dismac Logística" a **DisTrack** (app de campo). La app de
clientes de Dismac (blanca/roja) NO se toca; DisTrack va en **fondo Noche**.

**Marca (valores exactos):** Rojo DisTrack **#E30613** (NO el #E2231A del e-commerce), Noche **#141414**,
Ámbar #FFB000 (alertas), Andén #F3F3F1. Fuentes OFL: **Barlow** 400/500/700/800 (texto), **Barlow
Condensed** 800 (títulos), **IBM Plex Mono** 500 (códigos). Lema "DE ANDÉN A PUERTA." Assets del kit en
`public/brand/distrack/` (favicon, header-logo[-inverse], logos, mark, png/favicon-*). Kit original en
`C:\Users\ehhuallata\Downloads\DisTrack Brand Kit.*` + zip. Única animación del kit = `@keyframes scan`
(2.4s cubic-bezier(.6,0,.3,1) infinite alternate) en las páginas de error; el Lottie splash es de la app
Flutter, no del panel.

**Cómo se aplicó:** Filament `AdminPanelProvider` → `brandName('DisTrack')`, brandLogo/darkModeBrandLogo
(header-logo svg), favicon, `primary=#E30613`, `defaultThemeMode(Dark)`, `->font('Barlow', asset(
'brand/distrack/fonts/barlow.css'), LocalFontProvider::class)`. `<head>` extra en
`resources/views/filament/distrack-head.blade.php` (favicon/apple-touch/manifest/theme-color/OG).
`public/site.webmanifest`. Tema en `resources/css/filament/admin/theme.css` (tokens + @font-face locales).
Email `notifications/.../email/order.blade.php` rebrandeado. `MAIL_FROM_NAME`/`NOTIFICATIONS_EMAIL_FROM_NAME`
= DisTrack. **NO se cambió `APP_NAME`** (de él deriva el prefijo técnico de Redis). Ver [[filament-admin-hardening]].

**REGLA FUNDAMENTAL — CERO CDN externo en runtime, todo local y controlable:**
- Fuentes **auto-hospedadas** (woff2 OFL en `public/brand/distrack/fonts/` + `barlow.css`); NO Google Fonts.
  Filament carga Barlow vía `LocalFontProvider` (si se usa `->font('X')` pelado, Filament lo baja de un CDN
  → prohibido). Las páginas de error usan `@font-face` locales, no `<link>` a Google.
- **Leaflet 1.9.4 + Leaflet-Geoman 2.17.0 vendorizados** en `public/vendor/leaflet/` y
  `public/vendor/leaflet-geoman/` (antes venían de unpkg). Las 3 vistas de mapa (node-map, coverage-map,
  customer-profile) referencian local. OJO: `leaflet.css` descargado puede venir con CRLF → normalizar a LF.
- **Tiles de mapa SOLO self-host** (tileserver, `MAP_TILE_URL`); se eliminó el fallback a tiles públicos de
  OSM (`dismac-maps.js`, `config/maps.php`). Sin tileserver no hay teselas (sin fallback público).
- **CSP** (`app/Http/Middleware/SecurityHeaders.php`) quedó `'self'`-only en script/style/img/font/connect
  (sin unpkg, sin tile.openstreetmap, sin Google). El origen del tileserver self-host se agrega en runtime.
- Las URLs externas dentro de `public/js|css/filament/**` son comentarios/licencias/defaults inertes de
  los assets vendor ya locales (benignos). Pusher/Echo bundleado local (echo.js) apunta a Soketi self-host.
- Dependencias composer (bacon/pragmarx para QR 2FA, etc.) SÍ están permitidas: se descargan a `vendor/`
  (local), la regla es sobre CDN de **runtime**. `vendor/` es un VOLUMEN del contenedor (un `ls vendor/`
  en el host puede mentir; verificar dentro del contenedor y cargando el autoloader).

**Páginas de error (resources/views/errors/*, 14 vistas + layout):** rediseñadas con el kit (visor de
escaneo, referencia de soporte "Código de referencia (para soporte): ERR-{code}-{ref}" con hora/ruta,
light/dark por prefers-color-scheme, auth-aware @auth/@guest, sin fuga de excepción). Copy en **lenguaje
general para cliente** (sin jerga "bulto/andén/rol/supervisor"): 404 "No encontramos esta página", 403
"No tienes acceso a esta página", 500 "Algo salió mal", etc. Ver [[no-uuid-in-admin]] y [[auth-2fa-otp]].

Reportes: `docs/perf/AUDITORIA-devops-performance-2026-10-20.md` y los REPORTE-*.md de perf.
