# Bot de mensajería de Mercado Libre — documentación separada

Este módulo tiene su propia carpeta dentro de `docs/` (en vez de vivir sólo en
`documentacion_principal_crm.md`) porque se anticipa como **una de las partes más complejas de la
app**: integra permisos nuevos de Mercado Libre, generación de texto con un modelo de lenguaje,
revisión humana, y requisitos de infraestructura (colas, procesos persistentes) que el resto del CRM
todavía no necesita.

Este archivo es el punto de entrada. Todavía **no es una spec** (no se corrió `/speckit-specify`
sobre esto) — es la documentación de contexto y diseño que se arma *antes* de especificar, para que
cuando se arranque la cadena de spec-kit (`specify → clarify → plan → checklist → tasks → analyze`)
ya haya una base de decisiones y alcance clara y no se reinvente sobre la marcha.

## Qué es

Traer al panel del CRM los mensajes de Mercado Libre (Preguntas pre-venta y/o mensajería post-venta,
a definir — ver [Decisiones pendientes](#decisiones-pendientes)) en una vista propia, y asistir su
respuesta con un modelo de lenguaje ("bot educado"), sin perder de vista que Mercado Libre tiene
políticas propias sobre atención al comprador que hay que respetar.

## Relación con lo ya construido

- **`specs/011-mercadolibre-conexion-oauth`** ya construyó la conexión OAuth 2.0 propia del negocio
  contra el DevCenter de Mercado Libre (app propia, no la app genérica de Contagram), con permisos de
  **lectura y escritura**, ciclo de vida de tokens con refresh automático, y kill-switch de sólo
  lectura. Esa spec **excluye explícitamente** "preguntas, mensajería" y queda reservado para specs
  posteriores — este módulo es esa spec posterior.
- El scope de OAuth actual casi seguro **no incluye mensajería** (`messages`) — hay que confirmarlo en
  el DevCenter y probablemente haga falta pedir el permiso ahí y volver a autorizar la conexión.
- El **MCP `mercadolibre`** conectado a Claude Code (`.mcp.json`, ver memoria
  `mercadolibre-mcp-server.md`) es una integración completamente aparte: es la cuenta de ML de Federico
  usada por Claude en esta sesión de desarrollo, **no tiene ninguna relación** con la integración OAuth
  del CRM ni con este módulo. No confundir ambas conexiones.

## Por qué esto empuja a un VPS

Ver detalle en [infraestructura.md](infraestructura.md). Resumen: el hosting compartido actual
(Hostinger, ver skill `deploy`) no tiene `exec()`, no tiene crontab real por SSH (sólo el cron de
hPanel disparando `schedule:run` cada minuto) y no soporta un proceso persistente (`queue:work` +
supervisor). Para un flujo con revisión humana (ver `flujo-y-alcance.md`) el hosting actual alcanzaba,
pero como la migración a VPS **ya está decidida y se viene de todas formas** por otras razones del
negocio, este módulo se diseña asumiendo VPS disponible desde el vamos (colas reales con
`queue:work`, no `sync`/cron-fingido).

## Contenido de esta carpeta

- [flujo-y-alcance.md](flujo-y-alcance.md) — qué mensajes se traen, el flujo humano+bot propuesto,
  fases de alcance.
- [infraestructura.md](infraestructura.md) — requisitos técnicos (VPS, colas, LLM), estado actual del
  hosting, qué cambia con el VPS.
- [decisiones-pendientes.md](decisiones-pendientes.md) — preguntas abiertas que hay que cerrar con el
  usuario antes de especificar.
- [riesgos-politicas-ml.md](riesgos-politicas-ml.md) — políticas de Mercado Libre sobre mensajería y
  respuestas automáticas a tener en cuenta en el diseño.

## Estado

📋 **Documentación de contexto en construcción.** No hay spec todavía. No implementar nada de este
módulo hasta correr la cadena completa de spec-kit sobre esta base.
