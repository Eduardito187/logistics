---
name: distrack-ds-icons
description: Agregar, redibujar o animar un ícono del set propio DisTrack (ds-*) del admin Filament v4 — SVG en resources/svg/distrack, alias de íconos internos de Filament, overrides de heroicons y la animación del ícono COMPLETO en theme.css. Usar cuando haga falta un ícono nuevo, cuando aparezca `heroicon-*` o el enum `Heroicon::` en código, cuando un ícono se vea deforme o "básico", o al tocar la animación de íconos del tema.
---

# Set de íconos DisTrack (`ds-*`) — admin Filament

El panel usa SOLO el set propio: 183 SVG en `resources/svg/distrack/` (prefijo `ds`, `config/blade-icons.php`),
mismo nombre que el Heroicon que reemplazan (`heroicon-o-truck` → `ds-truck`). Guía completa (catálogo
semántico, alias, accesibilidad, prompt para agentes): `docs/filament-iconos-custom-animados.md`.
Memoria: `docs/memory/distrack-icon-set.md`.

## 🛑 Regla de ejecución

No levantar, bajar ni reconstruir Docker (`make assets`, `docker compose …`, pest, artisan) salvo que el
dueño lo pida en ese turno. Editar código y darle los comandos de verificación, uno por bloque.

## Reglas duras

- **Nunca** `heroicon-*` ni `Heroicon::` en código propio: siempre `'ds-<nombre>'`. CERO CDN (CSP `'self'`).
- **El ícono COMPLETO se anima** (pedido explícito, "no solo una fracción"): en hover/foco el tema pinta
  todo de rojo con brillo, redibuja todas las formas en cascada, mueve el grupo y hace pop del acento;
  clic = apretón + destello; `prefers-reduced-motion` = solo color/brillo. No agregar animaciones de una pieza.
- **Ningún SVG trae `<style>` ni animación propia**: chocan con el `transform-origin` del tema y deforman el
  ícono (pasó con el radar). Quietos en reposo.

## Formato del SVG

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <g class="ds-i ds-m-nudge-r">
    <path pathLength="1" d="…"/>
    <circle class="ds-accent" pathLength="1" …/>
  </g>
</svg>
```

- Un único `<g class="ds-i ds-m-<movimiento>">`. Movimientos: `spin`, `pulse`, `bounce`, `pop`, `wiggle`,
  `nudge-r`, `nudge-l`, `nudge-u`, `nudge-d`, `swing`, `blink`, `draw`.
- **Todas** las formas con `pathLength="1"`; exactamente una `class="ds-accent"`.
- Sin `width`/`height`, ids, `<image>`, fuentes ni URLs.

## Agregar o cambiar un ícono

1. Buscar si ya existe en `resources/svg/distrack/` (nombre = Heroicon equivalente).
2. Dibujarlo con el formato de arriba y usar `->icon('ds-<nombre>')` / `@svg('ds-<nombre>', 'w-5 h-5')`.
3. Ícono interno de Filament: alias en `App\Support\Icons\DistrackIconSet::filamentAliases()`. Si Filament lo
   dibuja sin alias (selector de tema, calendario, lupa del editor): copiar el `ds-*` a
   `resources/svg/heroicons-override/` con los cuatro prefijos (`o-`, `s-`, `m-`, `c-`).
4. Animación nueva: `@keyframes` + clase `ds-m-*` en `resources/css/filament/admin/theme.css`, con su
   variante reduced-motion.

## Chequeo estático (sin Docker)

```bash
grep -rn "heroicon-\|Heroicon::" app resources --include=*.php | grep -v "heroicons-override"
```
```bash
grep -l "<style" resources/svg/distrack/*.svg
```
```bash
grep -L 'class="ds-accent"' resources/svg/distrack/*.svg
```

Los tres deben salir vacíos.

## Verificación (la corre el dueño)

```bash
make assets
```
```bash
docker compose exec -T app php artisan filament:optimize-clear
```

Luego, en el panel: hover y clic en varios íconos (animación completa), consola sin errores de CSP, y
reduced-motion del sistema activado (solo color).
