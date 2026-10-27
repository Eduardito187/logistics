# Quality gates de CI — SonarCloud / PHPStan / Pint / Pest

> Guía para agentes. Objetivo: **cerrar el PR en verde en UN solo lote**, sin el ciclo "pushear →
> falla un gate → arreglar uno → repetir". Leer esto ANTES de tocar algo que el CI vaya a revisar.

## Regla de oro

Antes de pushear, resolver **todas** las condiciones del gate de una vez. No empujar un arreglo por
vez: cada push gasta un run de CI y un turno. Conocé las reglas del gate (abajo) y batcheá.

## Los cuatro gates

1. **Pint** (estilo). Autofix: `docker compose exec -T app ./vendor/bin/pint` o, si no hay Docker,
   en el host `/usr/bin/php vendor/bin/pint` (el `vendor/` está bind-mounteado). Verificar con `--test`.
2. **PHPStan nivel 8**. `docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G`.
   **No está instalado en el host** (solo en la imagen del container). Si no hay Docker, no se puede
   correr local: verificar por inspección y confirmar en CI.
3. **Pest**. Necesita PostgreSQL (`logistics_test`), que vive en el stack de Docker. Sin Docker **no**
   corre local; `php -l` solo valida sintaxis.
4. **SonarCloud Quality Gate** (ver sección propia; es el que más confunde).

### Entorno sin Docker (caso real recurrente)

Cuando Docker Desktop pierde la integración WSL: el host **sí** tiene PHP 8.4, Composer y
`vendor/bin/pint` (vendor bind-mounteado). Entonces local solo se puede: **Pint** + `php -l`. PHPStan,
Pest y el re-análisis de Sonar **solo confirman en CI**. Decirlo explícito al reportar; no afirmar
"tests verdes" si no se corrieron.

## SonarCloud — lo que NADIE debe olvidar

### Qué hace fallar el gate (y qué NO)

El Quality Gate de este proyecto **solo** falla por dos condiciones:

- **Duplication on New Code > 3%**.
- **Reliability Rating on New Code < A** (lo bajan los hallazgos tipo *bug*).

Todo lo demás que aparece como *annotations* (TODO, "too many parameters/methods", "N returns > 3",
atributos HTML deprecados, "immediate return", literal duplicado N veces, "unused private method")
son *issues* de mantenibilidad: **ensucian pero NO hacen fallar el gate**. Arreglarlos si son baratos
y seguros; no bloquear el merge por ellos ni hacer refactors riesgosos para apagarlos.

### Reliability (→ A)

Lo baja un hallazgo tipo *bug* (p. ej. "use of uninitialized value" / data-flow). Arreglar ese bug
puntual y el rating sube a A. Si es un **falso positivo** del motor de data-flow (variable ya
inicializada), reescribir la expresión a una forma que el motor acepte (p. ej. armar el array de una
sola vez con `array_merge(...array_map(...))` en vez de acumular en un `foreach`), o marcarlo
*Won't fix* en la UI de Sonar.

### Duplication — dónde está realmente y cómo tratarla

- Se mide en **bloques contiguos (~10 líneas / ~100 tokens)** sobre **código NUEVO** del PR. Un literal
  o una línea suelta repetida NO alcanza el umbral; lo que cuenta son bloques grandes casi idénticos.
- En este repo el grueso de la duplicación es **intencional por diseño**: cada bounded context tiene su
  propio `Presentation/Http/ApiController` (despacha al CommandBus/QueryBus) y sus Filament
  Resources/Pages, casi idénticos entre contextos. **No se deben unificar**: la plataforma es un
  monolito modular orientado a microservicios donde cada contexto es autónomo y extraíble (ADR 001/008);
  unificar esos adaptadores acoplaría los contextos. Tests y seeders también son repetitivos por
  naturaleza.
- Por eso el tratamiento correcto es **excluir de CPD**, no refactorizar. Para medir dónde está la
  duplicación sin la UI, usar un detector de bloques sobre `git ls-files '*.php'` (ventana de ~12
  líneas normalizadas repetidas ≥2 veces) y categorizar por capa.

### ⚠️ El archivo de config correcto (causa raíz del loop histórico)

El **Análisis Automático** de SonarCloud (el que corre en PRs sin pipeline propio) lee
**`.sonarcloud.properties`** en la raíz — **NO** `sonar-project.properties` (ese último solo lo lee el
*scanner* de CI). Poner las exclusiones en el archivo equivocado = no pasa nada y la métrica queda
idéntica. Config vigente en [`.sonarcloud.properties`](../.sonarcloud.properties):

```properties
sonar.cpd.exclusions=**/tests/**,**/*Test.php,database/seeders/**,**/Presentation/**,app/Filament/**
sonar.issue.ignore.multicriteria=e1,e2,e3
sonar.issue.ignore.multicriteria.e1.ruleKey=php:S107
sonar.issue.ignore.multicriteria.e1.resourceKey=database/seeders/**
# ...S107 en ReadModels, S1448 en seeders
```

Si el Análisis Automático **tampoco** toma ese archivo (puede pasar), la vía **garantizada** es la UI:
`Administration → Analysis Scope → Duplications → "Ignore Duplications"` (mismos globs), y
`Issue Exclusions` para S107/S1448, o marcar los issues como *Won't fix* en la pestaña Issues del PR.

### Falsos positivos típicos de Sonar en este repo

- **"Complete the task associated to this TODO"** sobre la palabra española **"todo"** en comentarios.
  Evitar el token suelto `todo`: usar `cada`, `completo`, `íntegramente`, `a lo largo de`, `ambos`.
  (`todos`/`todas` no se marcan; el problema es `todo` como palabra aislada.)
- **"Remove this unused private method"** sobre un método usado vía *first-class callable*
  `self::metodo(...)`: Sonar no lo reconoce. Envolver en arrow fn:
  `array_map(static fn (X $x): Y => self::metodo($x), $xs)`.
- **Atributos HTML deprecados en emails** (`width`/`align` en `<table>`/`<td>`): pasarlos a CSS inline;
  el ancho fijo de Outlook (600px) se preserva con tabla fantasma en comentario condicional MSO
  (`<!--[if mso]><table width="600">...<![endif]-->`), que Sonar no parsea por estar en comentario.

## PHPStan nivel 8 — patrones que se repiten

- `DB::table(...)->first()` se tipa como `stdClass|null`. Tiparlo así:
  `private function x(...): ?\stdClass { return DB::table(...)->first(); }`. Leer propiedades de
  `\stdClass` está **exento** en nivel 8, así que el llamador accede a `$row->campo` sin error. **No**
  usar `@return object{campo: tipo, ...}` (la forma no es asignable desde `stdClass` → error
  `return.type`), ni un `@var` temporal (dispara el "immediate return" de Sonar).
- `bindec()` / `hexdec()` devuelven `int|float` → castear `(int)` antes de usar como offset/char.
- `$punto?->prop` cuando `$punto` nunca es null → `nullsafe.neverNull`; usar `->prop`.

## Pint — fixers que sorprenden

`braces_position`, `single_line_empty_body`, `phpdoc_align` (alinea con padding los docblocks con
formas `object{...}` largas), `ordered_imports`, `fully_qualified_strict_types`. Siempre correr el
autofix y re-`--test` después de editar a mano.

## Checklist antes de pushear

- [ ] `pint --test` limpio (host o container).
- [ ] PHPStan nivel 8 limpio (container) o, sin Docker, revisado por inspección con los patrones de arriba.
- [ ] Pest verde (container) o declarado como no verificable local.
- [ ] Sin token `todo` suelto en comentarios nuevos.
- [ ] Exclusiones/ignores de Sonar en **`.sonarcloud.properties`** (no en `sonar-project.properties`).
- [ ] Reliability: ningún bug nuevo (data-flow/null).
- [ ] Reportar números reales (tests/aserciones) y qué quedó sin verificar.
