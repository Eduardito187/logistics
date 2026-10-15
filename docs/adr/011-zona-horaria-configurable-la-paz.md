# ADR 011 — Zona horaria configurable por entorno, default America/La_Paz

**Status:** Accepted
**Fecha:** 2026-10-13
**Deciders:** Eduard Huallata

## Contexto

La operación es en Bolivia (UTC-4, sin horario de verano), pero Laravel por defecto corre en **UTC**.
Muchas decisiones del dominio dependen de la hora **local**: cortes de pedido del día, franjas y slots
de entrega, "entregas de hoy", programación del auto-plan diario, vencimientos y reservas con
expiración, y todo texto de hora que ve un operador o un cliente. Si el código usa la hora del
servidor en UTC, esas fronteras de día y de franja caen en el momento equivocado (un corte "a las
18:00" se evaluaría a las 14:00 locales). A la vez, la plataforma debe poder desplegarse para otra
zona sin tocar código.

## Decisión

La zona horaria de la aplicación es **configurable por variable de entorno**, con **América/La_Paz**
como valor por defecto:

- `APP_TIMEZONE=America/La_Paz` es el default del proyecto (`.env.example` y `config/app.php`).
- Todo el código usa la hora de la aplicación: `now()` y las instancias de `Carbon` de Laravel ya
  devuelven hora local (p. ej. UTC-4). No se usa `now()->utc()` ni fechas "a mano" para lógica de
  negocio salvo que explícitamente se necesite UTC.
- **Persistencia en UTC, presentación en local.** Los timestamps se guardan en UTC en la base
  (convención de Laravel/PostgreSQL) y se convierten a la zona de la aplicación al mostrarlos o al
  evaluar fronteras de día/franja. El cambio de zona no reescribe datos, solo cambia la interpretación.
- Las fronteras sensibles a la hora (cortes, slots, "hoy", scheduler del auto-plan, expiraciones) se
  calculan siempre en la zona de la aplicación.

## Alternativas consideradas

### Dejar todo en UTC y convertir solo en la vista
- Pros: menos sorpresas de almacenamiento; es el default de Laravel.
- Contras: la **lógica de negocio** (cortes, "hoy", slots) también depende de la hora local; evaluarla
  en UTC produce fronteras de día equivocadas. La conversión no puede quedar solo en la capa de vista.

### Fijar `America/La_Paz` constante en el código
- Pros: simple, correcto para la operación actual.
- Contras: impide reutilizar la plataforma en otra zona sin editar y redeplegar; va contra la regla de
  que la configuración es de entorno, no de código.

## Consecuencias

### Positivas
- Las fronteras de día y de franja caen donde el negocio las espera (hora de Bolivia).
- La plataforma se puede desplegar para otra zona cambiando una variable de entorno.
- Un único origen de verdad para la hora (`now()`/`Carbon` de la app).

### Negativas / trade-offs aceptados
- Hay que ser disciplinado: usar siempre la hora de la app y no mezclar UTC "a mano" en la lógica de
  negocio; los tests deben fijar la zona para ser deterministas.

### Neutras
- El almacenamiento sigue en UTC; solo cambia la zona de interpretación y presentación.

## Referencias

- `config/app.php` (`timezone`), `.env.example` (`APP_TIMEZONE`).
- Relacionado con slots/calendario, cortes de pedido y el scheduler del auto-plan (ADR 012).
