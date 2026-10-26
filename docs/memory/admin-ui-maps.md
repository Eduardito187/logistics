---
name: admin-ui-maps
description: "Admin Filament (tema compilado, i18n, acceso/RBAC), mapas OSM/OSRM y dónde vive cada cosa"
metadata:
  node_type: memory
  type: project
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-14T07:49:24.687Z
---

Estado del admin y los mapas al 2026-10-13 (rama production, trabajo local sin commitear).

**Filament admin** (~36 resources, pages: Dashboard con widgets, `CoverageMap`, `Dispatch calendar`, `CustomerProfile`):
- **i18n**: código en inglés; textos visibles envueltos en `__()` + diccionario `lang/es.json` (~925 claves, estilo Magento). `APP_LOCALE=es`; Filament trae su propio `es`. Al agregar textos nuevos, envolver en `__()` y mergear pares {en,es} a `lang/es.json`.
- **Tema de marca compilado** (Tailwind v4): `resources/css/filament/admin/theme.css` (hace `@import` del theme de Filament + `@source` de app/`resources/views/filament`/`packages/logistics/*/src/Presentation/Filament`). Registrado con `->viteTheme('resources/css/filament/admin/theme.css')` en `AdminPanelProvider`. **Las clases Tailwind de los blades custom NO aplican sin este build.** Primario rojo Dismac `#E2231A`, fuente Open Sans, acentos sutiles (ver [[brand-palette]]). `AdminPanelProvider` inyecta además CSS por render hook (`resources/views/filament/brand-theme.blade.php`).
- **Build del tema (gotcha)**: `npm run build` en el HOST. **El vendor de Filament vive solo en el container**, así que hay que copiarlo al host antes del build: `docker compose cp app:/var/www/html/vendor/filament vendor/filament`. Node v20 en host. NO lanzar dos `npm install` a la vez (corrompe node_modules → ENOTEMPTY). Si el build no existe, dejar el `viteTheme` comentado (Filament usa su CSS y el admin funciona, pero los blades custom se ven planos).

**Acceso / RBAC** (package `logistics/access`): roles en `App\Enums\Role` (Admin, Supervisor, Chofer, Preparador, Tienda, Transportista). `User` implementa `FilamentUser`; `canAccessPanel()` exige **Admin o Supervisor** (los roles de campo operan desde la app Flutter). Tras `migrate:fresh --seed` hay que re-asignar el rol al usuario dev: `User::firstOrCreate([...])->assignRole(\App\Enums\Role::Admin)`. Login dev: `ehhuallata@dismac.com.bo` / `Dismac*2026`.

**Mapas** (todo OpenStreetMap, sin API key): Leaflet + tiles OSM. Patrón de carga en `resources/views/filament/pages/coverage-map.blade.php` (@assets Leaflet/Geoman CDN + @script). Páginas/vistas con mapa: `CoverageMap` (ver + dibujar/editar polígonos de zona con Geoman → persiste vía `Zone::define` + `ST_GeomFromGeoJSON`), `CustomerProfile` (direcciones del cliente), detalle de pedido (ubicación de entrega), `VehicleRouteResource` (ruta del chofer), `LiveFleetMap` (telemetría en vivo), `shipment-delivery-map`, `geofence-map`, `public` (tracking público).

**Mapas grises → tiles resilientes (2026-10-14).** `config/maps.tile_url` apunta por defecto a un tileserver OSM self-host (`http://localhost:8082/...`) que NO existe en muchos entornos (demo/laptop/CI) → el mapa pedía teselas que fallaban y quedaba TODO GRIS. Además la CSP permitía `https://*.tile.openstreetmap.org` (con subdominio) pero el OSM moderno sirve desde `https://tile.openstreetmap.org` SIN subdominio (no matchea el comodín). Fix (aplicado a los 7 blades de mapa): helper compartido **`public/js/dismac-maps.js`** → `window.DismacMaps.tileLayer(L, {url, fallbackUrl, attribution, maxZoom})`: crea la capa con la URL primaria (self-host) y, tras varios `tileerror`, hace `setUrl(fallbackUrl)` UNA vez al OSM público → el mapa nunca queda gris, con self-host o sin él. Se carga por `<script src="{{ asset('js/dismac-maps.js') }}"></script>` tras `leaflet.js`. CSP (`SecurityHeaders`) ahora incluye `https://tile.openstreetmap.org` además del comodín; `config/maps.tile_fallback_url` = `https://tile.openstreetmap.org/{z}/{x}/{y}.png` (sin `{s}`). Al añadir un mapa nuevo, usar SIEMPRE el helper, nunca `L.tileLayer()` crudo.

**Componentes dropdown propios (div, no `<select>` nativo).** Los `Select::make` de Filament ya son div-dropdowns (`->native(false)`, ver [[admin-ops-first-redesign]]); los `<select>` nativos hechos a mano se reemplazaron por: (a) Blade/Alpine `resources/views/components/dismac-select.blade.php` (`<x-dismac-select :options :property>`, sincroniza con Livewire vía `$wire.get/set`; usado en coverage-map) — admin claro, foco rojo de marca; (b) Vue `resources/js/Components/UI/Select.vue` (API v-model, `options` acepta `[{value,label}]` u objeto; usado en `DeliveryActions.vue`, `Planning/Board.vue`) — SPA oscura, acento `#E2231A`. Reutilizarlos para cualquier select nuevo hecho a mano en blade/SPA.

**Ruteo / mapa de ruta**: ver [[routing-geo-rule]]. La línea planificada se lee de la geometría persistida server-side en `planning_plans.route_geometry` (OSRM `/route`); fallback a OSRM en navegador y luego línea recta. El recorrido real (GPS, escaneos IN_TRANSIT) se road-snapea con OSRM `/match` en el navegador. Para la demo, la geometría del plan se pre-carga con OSRM `/route` sobre las paradas reales (usar el cliente HTTP de Laravel, NO `file_get_contents` https — falla por SSL en el container).

**GD**: agregado al Dockerfile (`docker-php-ext-configure gd --with-freetype --with-jpeg` + libs Alpine) para el collage de imágenes del push de notificaciones. Requiere rebuild de la imagen; al recrear el container `app` cambia su IP → **reiniciar nginx** o da 502.
