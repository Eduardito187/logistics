---
name: ci-docker-vite-gotchas
description: "Gotchas del CI: build de la imagen Docker prod (tema Filament/vite necesita vendor), tests de render sin manifest, deps de frontend"
metadata:
  node_type: memory
  type: reference
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-15T06:42:00.898Z
---

Tres fallos REALES del CI de GitHub Actions (`.github/workflows/ci.yml`) arreglados el 2026-10-14/15,
todos no obvios porque LOCALMENTE no se reproducen (en dev hay `vendor/` y `public/build/` ya presentes).
Verificados reconstruyendo desde un **worktree limpio del commit** (`git worktree add --detach`), que es lo
ÚNICO que replica lo que el runner ve (solo archivos commiteados, sin `vendor/`, `node_modules/`,
`public/build/` ni archivos untracked de otras sesiones). Ver [[testing-coverage-filament-gotchas]].

**1. El build del tema Filament (vite) necesita `vendor/` — pero está en `.dockerignore`.**
`resources/css/filament/admin/theme.css` hace `@import '../../../../vendor/filament/filament/resources/css/theme.css'`
y `app.css` tiene `@source '.../vendor/laravel/framework/...'`. El stage `assets` del Dockerfile
(`.docker/php/Dockerfile`, solo Node) hacía `COPY . . && npm run build`, pero `vendor/` está en
`.dockerignore` → el `@import` no resolvía y `vite build` fallaba el job `image` (target prod). FIX: un
stage `vendor` (`FROM base`, corre `composer install --no-dev --no-scripts`) y en `assets`
`COPY --from=vendor /var/www/html/vendor ./vendor` ANTES de `npm run build`. (Es el hack V-19 que el CI no
contemplaba.) OJO: el stage vendor copia TODO el contexto porque composer.json usa repos `path`
(packages/logistics/*): sin esas carpetas `composer install` falla.

**2. Tests que renderizan una página Filament COMPLETA necesitan el manifest de Vite → 500 en CI.**
Un test que hace `$this->get('/admin/...')` (HTTP, no `Livewire::test()`) renderiza el layout
`base.blade.php`, que usa `@vite`. El job `test` del CI NO compila assets, así que
`public/build/manifest.json` no existe → `ViteManifestNotFoundException` → 500 (status 500 en vez de 200).
FIX: `$this->withoutVite()` en el `setUp()` de `tests/TestCase.php` (base de toda la suite): stubbea Vite
en los tests, el HTML se renderiza sin las etiquetas de assets, suficiente para las aserciones. Reproducir
localmente: mover `public/build/manifest.json` y correr pest. `Livewire::test()` de un widget/página NO
dispara esto (no renderiza el layout con `@vite`), por eso solo mordió el único test con GET de página full.

**3. Dependencias JS importadas deben estar en package.json/package-lock.json o `vite build` falla.**
`resources/js/app.js` → `import './echo'` → `echo.js` → `import 'laravel-echo'` / `'pusher-js'`. Si alguien
agrega el `import` pero NO commitea package.json/lock con la dep (p.ej. corrió `npm install` local sin
commitearlo, o lo commiteó otra sesión a medias), `npm ci` no la instala y `vite` falla con "Rolldown
failed to resolve import". FIX: `npm install --save-dev laravel-echo pusher-js` (lo corrí en un contenedor
`node:22-alpine` montando el repo, porque no hay servicio node activo) y commitear package.json + lock.

**Patrón de verificación CI-equivalente (sin pushear):** `git worktree add --detach /tmp/ci-verify <sha>`
→ `cd` ahí → `DOCKER_BUILDKIT=1 docker build --target assets -f .docker/php/Dockerfile .` (o `--target prod`)
→ `git worktree remove --force`. Las capas base/vendor quedan cacheadas entre corridas.

**Nota de permisos:** en este entorno `git push` lo BLOQUEA el clasificador de auto-mode ("Out-of-Place
Publication"); hay que pedir autorización al dueño o que lo corra él. `gh` no está instalado (PR por link
de compare: `https://github.com/Eduardito187/logistics/compare/dev...<rama>?expand=1`). Ver
[[multisession-shared-tree]].
