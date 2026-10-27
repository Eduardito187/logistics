# Iconos del admin DisTrack — Heroicons, iconos custom y animación en Actions (Filament v4)

> Guía/prompt para agentes. Contexto COMPLETO para crear, elegir y **animar** iconos en el panel
> Filament sin romper las reglas del proyecto. Todo el texto visible del admin es en **inglés**; los
> comentarios y esta doc, en español.

> **Estado actual: el panel usa el set propio DisTrack (`ds-*`) en el 100% de los iconos.**
>
> - **Set:** 183 SVG dibujados para la marca en `resources/svg/distrack/`, registrados como set
>   `distrack` (prefijo `ds`) en `config/blade-icons.php`. Cada uno lleva el mismo nombre que el
>   Heroicon al que reemplaza (`heroicon-o-truck` → `ds-truck`).
> - **Código propio:** cada `->icon()`, `$navigationIcon` y `descriptionIcon()` usa `ds-*`. No quedan
>   referencias a `heroicon-*` ni al enum `Heroicon::`.
> - **Iconos internos de Filament** (búsqueda, menú de usuario, paginación, modales, filtros,
>   notificaciones, editor de texto…): los 222 alias se registran en
>   `App\Support\Icons\DistrackIconSet::filamentAliases()`, que se carga desde `AppServiceProvider::boot()`.
> - **Animación del ícono COMPLETO:** cada SVG trae su dibujo en `<g class="ds-i ds-m-MOVIMIENTO">`,
>   todas sus formas con `pathLength="1"` y un acento `class="ds-accent"`. Con hover o foco, el tema
>   (`theme.css`) hace cuatro cosas:
>   1. Pinta todo el ícono de rojo de marca, con un brillo suave.
>   2. Redibuja cada trazo en cascada; los puntos rellenos aparecen escalando.
>   3. Mueve el grupo entero con su movimiento propio.
>   4. Remata con un "pop" del acento.
>
>   Al hacer clic, el ícono se aprieta y destella. Con movimiento reducido solo cambian el color y el brillo.
> - **Para un ícono nuevo:** dibujar `resources/svg/distrack/<nombre>.svg` con el formato de §3 y usar
>   `'ds-<nombre>'`. Nunca volver a `heroicon-*`.
>
> Las secciones de abajo conservan la referencia general (Heroicons, técnicas A/B/C). Donde dicen
> `heroicon-o-<nombre>`, en este repo va `ds-<nombre>`.

---

## 0. Contexto del repo (no negociable)

- **Filament v4** (`filament/filament: ^4.0`). Capa de render de iconos: **blade-ui-kit/blade-icons**
  + **blade-ui-kit/blade-heroicons 2.7.0** (el set `heroicon-*` viene incluido).
- **Tema compilado propio**: [`resources/css/filament/admin/theme.css`](../resources/css/filament/admin/theme.css).
  Aquí van los `@keyframes` y clases de animación. Tras editarlo hay que **recompilar el tema**
  (ver §7). No se puede animar metiendo `<style>` sueltos en runtime.
- **Regla CERO CDN (dura).** Ningún recurso se trae de un CDN en runtime: ni iconos, ni fuentes, ni
  sets externos por URL. Los iconos custom son **SVG self-hosted dentro del repo**. La CSP del admin
  es `'self'`-only (ver `app/Http/Middleware/SecurityHeaders.php`): un `<img src="https://cdn...">`
  o un `@font-face`/icono remoto se bloquea.
- **Marca**: Rojo `#E30613`, Noche `#141414`. En el tema, usar los tokens de color de Filament
  (`text-primary-500`, etc.), no hex sueltos, salvo dentro de un SVG de marca.
- **`prefers-reduced-motion`**: toda animación debe apagarse/atenuarse para quien pide menos
  movimiento (accesibilidad). Es obligatorio (ver §6).

---

## 1. Cómo acepta iconos Filament v4

`->icon(...)` (y `->onIcon()`, `->iconButton()`, columnas/entries, badges) acepta **tres** formas:

```php
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

// 1) Enum Heroicon (autocompletado en el IDE). Heroicon::Star = solid, Heroicon::OutlinedStar = outline.
Action::make('star')->icon(Heroicon::OutlinedStar);

// 2) Nombre de icono Blade como string (lo más usado en este repo). 'heroicon-o-*' = outline,
//    'heroicon-s-*' = solid, 'heroicon-m-*' = mini 20px, 'heroicon-c-*' = micro 16px.
Action::make('retry')->icon('heroicon-o-arrow-path');

// 3) (solo en FilamentIcon::register) una vista Blade como icono — ver §4.
```

En Blade:

```blade
<x-filament::badge :icon="Heroicon::Star">Entregado</x-filament::badge>
<x-filament::badge icon="heroicon-o-truck">En ruta</x-filament::badge>
```

**Convención del repo (verificada contra el código):**

- Siempre el string `'ds-<nombre>'` del set DisTrack, en acciones, navegación, secciones, columnas,
  entries y widgets. Un mismo SVG sirve a cualquier tamaño: los que antes eran `heroicon-m-*` (mini)
  usan el mismo `ds-*` y Filament los dibuja chicos.
- No usar el enum `Heroicon::…` ni strings `heroicon-*`.

---

## 2. Catálogo semántico — qué icono y para qué

Heroicons tiene ~300 iconos; abajo están los **usados en este panel** + los recomendados para el
dominio logístico, con su significado. Elegir por **significado**, no por parecido visual; mismo
concepto ⇒ mismo icono en todo el panel.

### Acciones de estado / confirmación

| Icono | Para qué |
|---|---|
| `heroicon-o-check` | Marcar hecho / aceptar (acción genérica de OK) |
| `heroicon-o-check-circle` | Confirmar / éxito / "entregado", "recibido OK" |
| `heroicon-o-check-badge` | Verificado / validado (calidad, inspección pasada) |
| `heroicon-o-x-circle` | Rechazar / fallo / cancelar con error |
| `heroicon-o-no-symbol` | Bloquear / deshabilitar / "no disponible", feriado, cupo cerrado |
| `heroicon-o-exclamation-triangle` | Advertencia / incidente / alerta de mal uso |
| `heroicon-o-arrow-path` | Reintentar / recargar / reprocesar outbox / refrescar |

### Flujo logístico (andén → puerta)

| Icono | Para qué |
|---|---|
| `heroicon-o-inbox-arrow-down` | Recepción / ingreso de mercadería (inbound) |
| `heroicon-o-qr-code` | Escanear / etiqueta QR / unidad física |
| `heroicon-o-clipboard-document-check` | Reconciliar / checklist de recepción |
| `heroicon-o-truck` | Vehículo / flota / "en ruta" / despacho |
| `heroicon-o-paper-airplane` | Despachar / enviar / salida de ruta |
| `heroicon-o-forward` | Avanzar etapa / siguiente parada / reasignar |
| `heroicon-o-map-pin` | Ubicación / parada / geocerca / punto de entrega |
| `heroicon-o-map` | Mapa / vista de ruta / torre de control |
| `heroicon-o-queue-list` | Cola de entregas / última milla / lista de trabajo |
| `heroicon-o-arrow-up-tray` | Cargar / subir (carga del camión, import) |
| `heroicon-o-printer` | Imprimir etiqueta / documento |
| `heroicon-o-shopping-bag` | Pedido del cliente / orden |

### Personas, accesos y catálogos

| Icono | Para qué |
|---|---|
| `heroicon-o-user` | Usuario / chofer individual |
| `heroicon-o-user-group` | Equipo / choferes+ayudantes / roles |
| `heroicon-o-identification` | Documento de identidad / licencia / credencial |
| `heroicon-o-key` | Permiso / token de integración / API key |
| `heroicon-o-lock-closed` | Seguridad / 2FA / bloqueo de cuenta |

### Tiempo, planificación y dinero

| Icono | Para qué |
|---|---|
| `heroicon-o-calendar-days` | Calendario de despacho / cupos por municipio |
| `heroicon-o-clock` | Horario / ventana de entrega / slot / inactividad |
| `heroicon-o-banknotes` | Costos / combustible / liquidación |
| `heroicon-o-calculator` | Cálculo / TCO / costo por km |
| `heroicon-o-arrow-trending-up` | Métrica / analítica / tendencia |

### Mantenimiento y utilidades

| Icono | Para qué |
|---|---|
| `heroicon-o-wrench-screwdriver` | Mantenimiento / taller / perfil técnico |
| `heroicon-o-sparkles` | Auto-planner / sugerencia / acción "mágica" (IA) |
| `heroicon-o-play` | Ejecutar / iniciar proceso / generar |
| `heroicon-o-plus` / `heroicon-o-plus-circle` | Crear / agregar |
| `heroicon-o-document-plus` | Nuevo documento / ASN / adjuntar |
| `heroicon-o-trash` | Eliminar (destructivo: confirmar siempre) |
| `heroicon-o-arrow-top-right-on-square` | Abrir en nueva pestaña / enlace externo |
| `heroicon-o-arrow-left` | Volver / navegación hacia atrás |
| `heroicon-o-phone` | Teléfono del cliente / contacto |
| `heroicon-o-star` | Favorito / satisfacción / destacar |

### Reglas de consistencia (mismo concepto ⇒ mismo icono)

Decisiones ya aplicadas en el panel. Respetarlas al agregar acciones nuevas:

| Concepto | Icono | Notas |
|---|---|---|
| Activar / Reactivar / Desbloquear | `heroicon-o-check-circle` | Antes "Reactivate" usaba `arrow-path` y el plan de mantenimiento `play`. |
| Desactivar / Suspender / Bloquear / Revocar / Suprimir | `heroicon-o-no-symbol` | Antes el plan de mantenimiento usaba `pause`. |
| Iniciar un proceso (orden de taller) | `heroicon-o-play` | Solo "start", no "activate". |
| Reintentar / Reprocesar / Rotar token / Cambiar estado | `heroicon-o-arrow-path` | No usarlo para reactivar ni reimprimir. |
| Imprimir / Reimprimir etiquetas | `heroicon-o-printer` | Antes "Reprint labels" usaba `arrow-path`. |
| Reconocer alerta (visto) | `heroicon-o-check` | Telemetría. |
| Confirmación de SAP ("recibido OK") | `heroicon-o-check-circle` | Stock transfers. |
| Recepción (ASN, nodo, devolución) | `heroicon-o-inbox-arrow-down` | Antes "Node reception" usaba `building-office-2`. |
| Outbox (mensajes salientes) | `heroicon-o-paper-airplane` | Antes la navegación usaba `inbox-arrow-down`, que significa entrada. |
| Auto-planner / sugerencias automáticas | `heroicon-o-sparkles` | Antes "Auto-plan the day" usaba `truck`. |
| Mapa de flota en vivo | `ds-radar` | SVG propio; como el resto, solo se anima con hover/clic. |
| Mapas estáticos (cobertura, áreas, planes) | `heroicon-o-map` | |

> Catálogo completo oficial: https://heroicons.com (elegir ahí el nombre; en código es
> `heroicon-o-<nombre-kebab>`). **No** incrustar el SVG remoto: el set ya está vendorizado por
> blade-heroicons.

---

## 3. Crear un icono CUSTOM self-hosted (SVG propio, CERO CDN)

Cuando ningún Heroicon sirve (un pictograma de marca, un estado del dominio, un logo de transportista):

1. **Config de Blade Icons: ya está hecha.** `config/blade-icons.php` está versionado con el set
   `distrack` (equivale a `vendor:publish --tag=blade-icons` + el set agregado). No volver a
   publicarla, porque pisaría el archivo. El set queda así:
   ```php
   'sets' => [
       'distrack' => [
           'path' => 'resources/svg/distrack',
           'prefix' => 'ds',          // prefijo → se referencia como 'ds-<archivo>'
           'fallback' => '',
       ],
   ],
   ```

2. **Dejar el SVG en `resources/svg/distrack/`.** Ej.: `resources/svg/distrack/pallet.svg`. Requisitos:
   - Raíz exacta: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">`.
     Así **hereda el color del tema** y calza en tamaño con el resto.
   - Todo el dibujo dentro de un único `<g class="ds-i ds-m-MOVIMIENTO">`. Movimientos: `spin`, `pulse`,
     `bounce`, `pop`, `wiggle`, `nudge-r`, `nudge-l`, `nudge-u`, `nudge-d`, `swing`, `blink`, `draw`
     (con `draw`, los trazos llevan `pathLength="1"`).
   - Exactamente un elemento clave con `class="ds-accent"` (remata la animación con un "pop").
   - **Todas** las formas (`path`, `circle`, `rect`, `line`, `polyline`, `polygon`, `ellipse`) llevan
     `pathLength="1"`: así el tema redibuja el ícono entero al hacer hover.
   - Sin `width`/`height`, ids ni referencias externas (ni `<image href="http...">`, ni fuentes): CERO CDN.
     Sin `<style>` ni animación propia: todos los íconos están quietos en reposo y solo los anima el tema.
     (Un `<style>` interno choca con el `transform-origin` del tema y deforma el ícono.)

3. **Usarlo** con el prefijo `ds-` y el nombre de archivo sin extensión:
   ```php
   Action::make('split_pallet')->icon('ds-pallet');
   ```
   En Blade: `@svg('ds-pallet', 'w-5 h-5')` o `<x-icon name="ds-pallet" />`.

4. **Íconos que Filament dibuja sin alias** (selector de tema sun/moon/computer-desktop, calendario del
   DateTimePicker, lupa del RichEditor): `config/blade-heroicons.php` hace que el set `heroicons` busque
   primero en `resources/svg/heroicons-override/`. Esa carpeta tiene copias de los SVG `ds-*` con el
   nombre de archivo de Heroicons (`o-sun.svg`, `s-sun.svg`, `m-sun.svg`, `c-sun.svg`). Si Filament
   agrega otro ícono sin alias, copiar ahí su `ds-*` con los cuatro prefijos.

> Para un set aparte (p. ej. logos de transportistas) se agrega otra entrada en `sets` con su propio
> `prefix` (sin guiones en el prefijo) y `path`. Mantener todo dentro del repo.

---

## 4. Alias semánticos de icono (reemplazar iconos por defecto de Filament)

Para cambiar globalmente un icono que Filament usa en toda la UI (no por acción), se registran
**alias** en el `boot()` de un service provider (p. ej. `AppServiceProvider` o uno dedicado de iconos):

```php
use Filament\Support\Facades\FilamentIcon;
use Filament\Actions\View\ActionsIconAlias;
use Filament\View\PanelsIconAlias;

FilamentIcon::register([
    // Reemplaza el icono con un nombre de icono Blade...
    ActionsIconAlias::DELETE_ACTION         => 'heroicon-o-trash',
    PanelsIconAlias::GLOBAL_SEARCH_FIELD    => 'ds-magnifying-glass',
    // ...o con una VISTA Blade (útil para inyectar una clase/animación al SVG):
    PanelsIconAlias::USER_MENU_LOGOUT_BUTTON => view('icons.logout-animated'),
]);
```

Clases de alias disponibles (cada una es un enum con su namespace):
`Filament\Actions\View\ActionsIconAlias`, `Filament\Forms\View\FormsIconAlias`,
`Filament\Infolists\View\InfolistsIconAlias`, `Filament\Notifications\View\NotificationsIconAlias`,
`Filament\View\PanelsIconAlias`, `Filament\Tables\View\TablesIconAlias`,
`Filament\Schemas\View\SchemaIconAlias`, `Filament\Support\View\SupportIconAlias`,
`Filament\Widgets\View\WidgetsIconAlias`, `Filament\QueryBuilder\View\QueryBuilderIconAlias`.

La opción `view(...)` es la puerta oficial para un icono con clase/animación propia (§5, técnica B).

---

## 5. Animar iconos en Actions

**Filament v4 NO tiene API de "animar icono"** (`->icon()` no recibe clases). La animación es **CSS en
el tema compilado**. Hay tres técnicas, de menor a mayor control:

### Técnica A — Clase en la Action + selector hijo al SVG (la más usada)

`extraAttributes()` pone una clase en el botón de la acción; el CSS del tema anima el `svg` hijo.

```php
Action::make('reprocess_outbox')
    ->label('Reprocess outbox')
    ->icon('heroicon-o-arrow-path')
    ->extraAttributes(['class' => 'ds-icon-spin-hover']);
```

En `resources/css/filament/admin/theme.css`:

```css
/* Gira el icono al pasar el mouse por el botón de la acción. */
.ds-icon-spin-hover:hover svg {
    animation: ds-spin 0.8s linear infinite;
}
@keyframes ds-spin {
    to { transform: rotate(360deg); }
}
```

### Técnica B — Alias/icono = vista Blade con clase fija (animación siempre activa)

Para que el icono se anime SIEMPRE (p. ej. un "sync" en progreso), registrarlo como vista (§4) o usar
`@svg` con segunda-arg de clases. `@svg('nombre', 'clases')` agrega las clases al `<svg>`:

`resources/views/icons/sync-live.blade.php`:
```blade
{{-- Icono que pulsa para indicar actividad en vivo. --}}
@svg('heroicon-o-signal', 'ds-pulse')
```
```css
.ds-pulse { animation: ds-pulse 1.5s ease-in-out infinite; }
@keyframes ds-pulse { 0%,100% { opacity: 1 } 50% { opacity: .35 } }
```

### Técnica C — SVG autocontenido con animación interna (ideal CERO CDN)

El SVG propio (§3) lleva su animación dentro (CSS en `<style>` o SMIL `<animate>`): no depende del
tema ni de clases externas. Perfecto para un pictograma de marca animado.

`resources/svg/ejemplo-radar.svg` (ejemplo genérico; el set DisTrack NO usa esta técnica):
```xml
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" xmlns="http://www.w3.org/2000/svg">
  <style>
    @media (prefers-reduced-motion: no-preference) {
      .radar { transform-origin: 12px 12px; animation: radar-sweep 2s linear infinite; }
    }
    @keyframes radar-sweep { to { transform: rotate(360deg); } }
  </style>
  <circle cx="12" cy="12" r="9" stroke-width="1.5"/>
  <path class="radar" d="M12 12 L12 3" stroke-width="1.5" stroke-linecap="round"/>
</svg>
```
```php
Action::make('live')->icon('icon-ejemplo-radar');
```

### Atajo: utilidades de Tailwind ya disponibles en el tema

Tailwind (base del tema) trae `animate-spin`, `animate-pulse`, `animate-bounce`, `animate-ping`.
Aplicadas con las técnicas A/B. Útiles para estados de carga sin escribir keyframes.

### Implementado en el repo

Las clases ya existen en `resources/css/filament/admin/theme.css`. Todas se disparan con hover **y** con
foco de teclado (`:is(:hover, :focus-visible)`) y están declaradas dentro de
`prefers-reduced-motion: no-preference`.

| Clase | Técnica | Efecto | Dónde se usa |
|---|---|---|---|
| `ds-icon-spin-hover` | A | giro continuo | Outbox: `retry`, `retrySelected` |
| `ds-icon-pulse-hover` | A | latido suave | `generate` (calendario de despacho), `generateProposal`, `autoPlanTheDay` |
| `ds-icon-bounce-hover` | A | rebote corto, 2 veces | `reportIncident` (ruta), `dispute` (liquidación de transportista) |
| `ds-icon-pop-hover` | A | "pop" de una vez | `confirmDelivery` (última milla), `recordProofOfDelivery` |
| `ds-pulse` | B | latido de opacidad permanente | `resources/views/icons/sync-live.blade.php`, indicador "Live" del mapa de flota |

Si un SVG con `<style>` interno se usa inline, sus clases y `@keyframes` son globales en la página y
las reglas del tema (p. ej. `transform-origin`) le aplican también. Por eso el set DisTrack no usa
animación interna: el radar la tenía y se deformaba al girar.

### Patrones recomendados (sobriedad > espectáculo)

| Intención | Animación | Icono base |
|---|---|---|
| Procesando / sincronizando | `spin` continuo | `heroicon-o-arrow-path` |
| Actividad en vivo (GPS/telemetría) | `pulse`/`ping` | `ds-signal`, `ds-radar` |
| Acción destacada (auto-planner IA) | `pulse` suave al hover | `heroicon-o-sparkles` |
| Alerta/incidente que pide atención | `bounce` corto, no infinito | `heroicon-o-exclamation-triangle` |
| Éxito tras completar | transición de 1 vez (no loop) | `heroicon-o-check-circle` |

---

## 6. Reglas de accesibilidad y marca (obligatorias)

- **`prefers-reduced-motion`**: envolver TODA animación de loop para que no se ejecute si el usuario
  pidió menos movimiento. En el tema:
  ```css
  @media (prefers-reduced-motion: reduce) {
      .ds-icon-spin-hover:hover svg,
      .ds-pulse { animation: none !important; }
  }
  ```
  (o, al revés, declarar los `@keyframes` dentro de `@media (prefers-reduced-motion: no-preference)`).
- **Sobriedad**: nada de animaciones infinitas llamativas en toda la tabla; reservarlas para estado
  (procesando, en vivo) o para una única acción destacada. Un admin logístico no es una landing.
- **Color**: `stroke="currentColor"` para que el icono tome el color del contexto (texto/estado). Para
  marca, Rojo `#E30613` / Noche `#141414`, pero preferir tokens del tema.
- **Texto en inglés**: labels, tooltips y `->tooltip()` de las acciones, en inglés. Comentarios, en
  español.
- **CERO CDN**: el SVG y el CSS viven en el repo; nada remoto.

---

## 7. Flujo de trabajo y verificación

1. Agregar/editar iconos (`resources/svg/*.svg`, vistas `resources/views/icons/*.blade.php`) y la
   animación en `resources/css/filament/admin/theme.css`.
2. **Recompilar el tema** (el CSS del admin es compilado, no se sirve crudo):
   ```bash
   make assets
   ```
   `make assets` asegura `vendor/filament` en el árbol del proyecto, corre `npm ci` y `npm run build`
   en el contenedor `node` y deja el resultado en `public/build`. Es el mismo procedimiento que el job
   `assets` del CI. En producción el tema se compila en el stage `assets` del Dockerfile.
   Si se agrega o cambia un SVG en `resources/svg/` y los iconos están cacheados (el entrypoint corre
   `php artisan filament:optimize` con `OPTIMIZE_ON_BOOT=true`), limpiar la caché:
   `docker compose exec -T app php artisan filament:optimize-clear`.
3. Verificar en el panel real (no solo en código): que el icono carga desde `'self'` (sin errores de
   CSP en consola), que la animación corre y que respeta `prefers-reduced-motion`.
4. Si se registró un set/alias nuevo: confirmar que `config/blade-icons.php` quedó versionado y, si se
   tocó `bootstrap/providers.php`, respetar el orden (regla del proyecto).

---

## 8. PROMPT reutilizable para tus agentes

> Copiá/pegá esto como brief cuando le pidas a un agente crear o animar iconos en el admin:

```
Trabajás en el admin Filament v4 de DisTrack (este repo). Reglas duras:
- CERO CDN: iconos y CSS self-hosted en el repo; la CSP es 'self'-only.
- Texto visible del admin en inglés; comentarios y docs en español.
- Marca: Rojo #E30613, Noche #141414; preferí tokens del tema.
- Toda animación respeta prefers-reduced-motion.

Para iconos:
1. Elegí el icono por SIGNIFICADO usando el catálogo de docs/filament-iconos-custom-animados.md
   (mismo concepto ⇒ mismo icono en todo el panel). Forma: 'ds-<nombre>' (set propio DisTrack,
   resources/svg/distrack/). Nunca 'heroicon-*'.
2. Si el ícono no existe en el set, dibujalo en resources/svg/distrack/<nombre>.svg con el formato
   del set: raíz <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
   stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">, un único
   <g class="ds-i ds-m-MOVIMIENTO"> (spin|pulse|bounce|pop|wiggle|nudge-r|nudge-l|nudge-u|nudge-d|
   swing|blink|draw; draw lleva pathLength="1" en sus trazos) y exactamente un elemento
   class="ds-accent". Sin width/height, ids, <style> ni URLs. La animación de hover/clic ya la da
   el tema; no hace falta CSS nuevo.
3. Para animar un icono en una Action, NO existe API nativa: usá CSS en
   resources/css/filament/admin/theme.css con una de estas técnicas:
   (A) ->extraAttributes(['class' => 'ds-...']) + selector '.ds-... svg' con @keyframes;
   (B) icono = vista Blade con @svg('name','clase') + @keyframes sobre esa clase;
   (C) SVG autocontenido con <style>/@keyframes dentro (preferido para pictogramas de marca).
   Usá spin para "procesando", pulse/ping para "en vivo", bounce corto para alertas, y
   transición de una sola vez para "éxito". Nada de loops llamativos en tablas.
4. Recompilá el tema (npm run build) y verificá en el panel real: carga desde 'self' sin
   errores de CSP, la animación corre y se apaga con prefers-reduced-motion.

Entregá: el/los archivos tocados, el nombre exacto del icono para ->icon(), y el bloque CSS
agregado al tema. Confirmá que no introdujiste ningún recurso remoto.
```

---

### Referencias

- Filament v4 — Icons: https://filamentphp.com/docs/4.x/styling/icons
- Heroicons (catálogo + nombres): https://heroicons.com
- Blade Icons (sets/config `@svg`): https://github.com/blade-ui-kit/blade-icons
