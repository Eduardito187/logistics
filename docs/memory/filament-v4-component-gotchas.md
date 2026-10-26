---
name: filament-v4-component-gotchas
description: "Gotchas de componentes Filament v4 — tipos de propiedad invariantes (fatales), ImageColumn, tarjeta Repeatable clickeable"
metadata:
  node_type: memory
  type: reference
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-14T07:49:39.016Z
---

Gotchas a nivel de COMPONENTE de Filament v4 (distintos del hardening de panel [[filament-admin-hardening]] y del tema/i18n [[admin-ui-maps]]). Todos vistos y arreglados el 2026-10-13 tras fatales reales que rompían el panel.

**1. Tipos de propiedad INVARIANTES → fatal de PHP que tumba el panel.** Al sobre-escribir una propiedad de una clase base de Filament, el tipo debe coincidir EXACTO con el del padre (PHP exige invarianza). Un `?string`/`int|string|array` que no calce = `Fatal error: Type of X must be ...` al autoload → 500 en el admin. Tipos correctos en v4:
- Widgets `StatsOverviewWidget`: `protected int|array|null $columns` (NO `int|string|array`).
- Pages/Resources: `protected static string|BackedEnum|null $navigationIcon` y `protected static string|UnitEnum|null $navigationGroup`. Un `?string $navigationIcon` solo también revienta.
- Verificación no-test: `php -r 'require "vendor/autoload.php"; class_exists(\App\Filament\...::class);'` dentro del container autocarga la clase y revela el fatal. Barrer todas las props con grep antes de que exploten en runtime.

**2. `Factory::configure()` debe ser `public`.** La clase base `Illuminate\...\Factories\Factory::configure()` es public; declararla `protected function configure()` en una factory = fatal al usar la factory (p.ej. `ZoneRecordFactory`). Mismo principio de visibilidad que arriba.

**3. Imágenes de producto: cuadrado pequeño, NO círculo.** `ImageColumn`/`ImageEntry` con `->circular()` sobre una foto apaisada la recorta en un círculo grande (se veía un TV con un círculo de nubes). API correcta v4: `->square()->imageSize(40)` (columnas) / `->imageSize(56)` (infolist) y `->checkFileExistence(false)` para URLs externas (los demos usan `picsum.photos`). Métodos reales: `imageSize(int|string)`, `imageHeight()`, `imageWidth()`, `square()`, `circular()`, `defaultImageUrl()`, `checkFileExistence()`.

**3b. Imágenes NUNCA rotas ni huecos en blanco (dueño: "no quiero imágenes rotas").** Causa real (choferes/ayudantes): las fotos viven en disco `s3`/privado (no servible en local) y los seeds dejan `photo_path` NULL → Filament emite `<img src="">` = ícono de imagen rota. `checkFileExistence(false)` + sin `defaultImageUrl` lo deja roto. Solución: helper compartido `Logistics\Shared\Presentation\Filament\ImageFallback` (en el package `shared`, dependencia de todos) con tres capas:
- `diskUrl(?disk,?path): ?string` — try/catch total: si la ruta es vacía, el archivo no existe o el disco no es alcanzable → null (nunca lanza; s3 sin credenciales cae en null). Devuelve URL temporal firmada o pública.
- `placeholder('avatar'|'box'): string` — SVG embebido como data URI (`rawurlencode`, sin comillas sin escapar → seguro dentro de `onerror="...src='...'"`). Cero peticiones externas (coherente con stack sin Google).
- `onError('avatar'|'box', extra): array` — atributos `<img>` con `onerror` que cambia la imagen rota por el placeholder (para URLs externas que dan 404 en el navegador).
Cableado: fotos en disco → `->getStateUsing(fn($r)=>ImageFallback::diskUrl(...))` (devuelve URL absoluta o null; SIN `->disk()`) + `->defaultImageUrl(placeholder)` + `->extraImgAttributes(onError)`. URLs externas (producto/POD) → `->defaultImageUrl(placeholder)` + `->extraImgAttributes(onError)`. Filament usa `defaultImageUrl` cuando el state es vacío/null y acepta data URIs directo (ImageColumn.php/ImageEntry.php: `filter_var($state,FILTER_VALIDATE_URL)||startsWith('data:')` → devuelve el state tal cual). Test de render: `tests/Feature/Admin/ImageFallbackRenderTest` (afirma que sale el data URI y que NO hay `src=""`).

**4. Hacer clickeable TODO el recuadro de un `RepeatableEntry` (no un botón).** El dueño NO quiere botones "ver en el mapa"; quiere la tarjeta entera clickeable. Patrón:
- El contenedor de cada item es `.fi-in-repeatable-item`.
- Un entry oculto `Filament\Schemas\Components\View` por item (sí renderiza dentro del RepeatableEntry, uno por fila; su `->viewData(fn($record)=>...)` recibe el record del item, p.ej. la parada) corre Alpine `x-init` que hace `$el.closest('.fi-in-repeatable-item')`, le pone `cursor-pointer`+`role=button`+`tabindex`, y le engancha `click`/`keydown` → llama una función global.
- La función global la expone el blade del mapa (`window.focusRouteStop = focusStop`): hay un solo mapa por página, así se evita el baile de eventos/mapId (que fue por qué un intento previo con botón + CustomEvent no disparaba fiable).
- El handler ignora clics sobre `a, button` (`e.target.closest('a, button')`) para que los links de texto sigan navegando.
- Solo cablear si el item tiene punto en el mapa (`hasPoint`): las paradas sin geocodificar no tienen marcador, así que no se hacen clickeables (si no, "parece roto"). Ejemplo vivo: `packages/logistics/execution/.../VehicleRouteResource.php` + `resources/views/filament/route-map.blade.php` + `route-stop-clickable.blade.php`.

**5. Tests de render del admin NO afirman literales en inglés.** El admin se muestra en ESPAÑOL (APP_LOCALE=es, sin override en phpunit.xml) vía `lang/es.json` ([[admin-ui-maps]]). Un `->assertSee('Dead-letter')` se rompe en cuanto es.json traduce esa clave (p.ej. "Dead-letter"→"Descartados (dead-letter)": contiene "dead-letter" minúscula pero NO el literal). Afirmar SIEMPRE la etiqueta traducida: `->assertSee(__('Dead-letter'))` (locale-independiente). Esto mordió al correr el verify global tras la ola i18n.

**6. `composer dump-autoload` falla cuando un Resource NUEVO referencia una Page aún no autocargada.** El
script `post-autoload-dump` corre `php artisan package:discover`, que bootea Filament y descubre el
Resource, que hace `use ...\Pages\ListX` → si el classmap aún no incluye `ListX` = `Class not found` →
el dump entero aborta y el admin/artisan dejan de bootear. Romper el círculo: `composer dump-autoload -o
--no-scripts` (regenera el classmap SIN botear Filament) y luego `php artisan package:discover` aparte.

**7. `grep` de patrones que empiezan con `-` dan falso 0.** `grep -rn "->native(false)" ...` devuelve 0
(grep interpreta `->...` como flags) y lleva a concluir que la regla 5 está incumplida cuando NO lo está.
Usar `grep -rn -- "->native(false)" ...`. (Esto hizo creer que 95 selects eran nativos; en realidad TODOS
ya tenían `->native(false)`.)

Al agregar texto visible nuevo (p.ej. nuevas claves) recordar i18n: `__()` en inglés + par en `lang/es.json` ([[admin-ui-maps]]). Verificar siempre sin ejecutar tests ([[no-ejecutar-tests]]): PHPStan 8 + Pint + autoload check + `view:clear`. Render del admin: sweep por el HTTP kernel sobre `getPages()`+`getResources()::getUrl('index')` autenticado como admin, con el script temporal DENTRO del repo (el container no ve el scratchpad del host). Ver [[admin-ops-first-redesign]].
