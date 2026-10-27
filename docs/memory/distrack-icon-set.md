---
name: distrack-icon-set
description: Set de íconos propio DisTrack (ds-*, 183 SVG) en el 100% del admin Filament, animado COMPLETO en hover/clic; cómo agregar uno y qué verifica el dueño
metadata:
  type: project
  modified: 2026-10-26T00:00:00.000Z
---

El admin Filament ya NO usa Heroicons: 183 SVG propios en `resources/svg/distrack/` (set `distrack`, prefijo `ds`, `config/blade-icons.php`), mismo nombre que el Heroicon que reemplazan (`heroicon-o-truck` → `ds-truck`). Los 222 íconos internos de Filament se remapean en `App\Support\Icons\DistrackIconSet::filamentAliases()` (cargado en `AppServiceProvider::boot()`). Guía completa y formato del SVG: `docs/filament-iconos-custom-animados.md`.

**Animación al 100% (pedido explícito del dueño, "no solo una fracción"):** cada SVG trae `<g class="ds-i ds-m-<movimiento>">`, todas las formas con `pathLength="1"` y un `ds-accent`. En hover/foco `theme.css` pinta TODO el ícono de rojo con brillo, redibuja cada trazo en cascada, mueve el grupo y remata con pop del acento; clic = apretón + destello; reduce-motion = solo color. Nunca volver a animar una sola pieza. Lo mismo se replicó en la app Flutter (`logistics-app/tool/icons/README.md`).

**Gotchas:**
- El radar se deformaba: era el único SVG con `<style>`/animación interna permanente y la animación del tema le cambiaba el punto de giro. Regla: ningún SVG del set trae `<style>` ni se anima solo; quieto en reposo, animado solo con hover/clic (`grep -l '<style' resources/svg/distrack/*.svg` debe salir vacío).
- Tras tocar `theme.css` hay que recompilar el tema; eso lo corre el dueño (no levantar su Docker sin pedido).
- Entregable para diseño: `C:\Users\ehhuallata\Downloads\distrack-icons-final.zip` (todos los íconos, variantes y animaciones).

Ver [[distrack-rebrand-no-cdn]], [[admin-ui-maps]].
