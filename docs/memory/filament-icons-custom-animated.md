---
name: filament-icons-custom-animated
description: "Catálogo de iconos del admin, iconos SVG custom self-host y cómo animarlos en Actions (Filament v4)"
metadata:
  node_type: memory
  type: reference
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-26T05:52:49.090Z
---

Guía/prompt completo en el repo: `docs/filament-iconos-custom-animados.md` (referenciado desde
CLAUDE.md). Incluye un PROMPT reutilizable (§8) para pegar a agentes.

Lo esencial:

- Filament v4 + blade-icons/blade-heroicons. `->icon()` acepta: enum `Filament\Support\Icons\Heroicon`,
  string blade-icon (`heroicon-o-*` outline por defecto en el repo; `-s-`/`-m-`/`-c-` solid/mini/micro),
  y en `FilamentIcon::register([...])` también una `view()` Blade.
- **Iconos custom (regla CERO CDN):** SVG self-hosted en `resources/svg/` (publicar set `default` de
  blade-icons, prefijo `icon-`), `viewBox="0 0 24 24"`, `fill=none stroke=currentColor`, sin tamaños
  fijos ni recursos remotos → usar como `->icon('icon-<archivo>')`.
- **Animación: NO hay API nativa.** Es CSS en el tema compilado
  `resources/css/filament/admin/theme.css` (recompilar con `npm run build`). Técnicas: (A)
  `->extraAttributes(['class'=>'ds-...'])` + selector `.ds-... svg` con `@keyframes`; (B) icono = vista
  Blade con `@svg('name','clase')`; (C) SVG autocontenido con `<style>`/`@keyframes` adentro (preferido
  para marca). Tailwind trae `animate-spin/pulse/bounce/ping`.
- Obligatorio: respetar `prefers-reduced-motion`, texto del admin en inglés, color de marca
  (#E30613/#141414) vía tokens del tema, sobriedad (spin=procesando, pulse/ping=en vivo, bounce=alerta).

Alias de iconos por defecto de Filament: `FilamentIcon::register` en `boot()` con los enums
`*IconAlias` (ActionsIconAlias, PanelsIconAlias, TablesIconAlias, etc.).

Relacionado: [[admin-ui-maps]], [[distrack-rebrand-no-cdn]], [[filament-v4-component-gotchas]].
