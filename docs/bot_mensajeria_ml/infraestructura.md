# Infraestructura

## Estado actual (hosting compartido, Hostinger)

Relevado directamente del proyecto (skill `deploy`, `deploy.py`, `.env.example`) al plantear este
módulo:

- **Hosting compartido** en Hostinger, Laravel corriendo en subcarpeta de `devstudioweb.com`. Deploy
  por SFTP + comandos remotos vía `deploy.py` (paramiko).
- **`exec()` deshabilitado** por el hosting — rompe cualquier cosa que dependa de spawnear procesos
  del sistema (ya rompió `artisan storage:link`, que se resolvió con symlink manual).
- **No hay crontab real accesible por SSH.** El scheduler de Laravel (`withSchedule()`) sólo corre si
  se configura un cron job desde el panel de Hostinger (hPanel) apuntando a `schedule:run`, típicamente
  cada minuto. No hay margen para nada más frecuente ni para un proceso que corra continuamente.
- **`QUEUE_CONNECTION=sync`** — no hay colas reales hoy. No existe `app/Jobs/`. No hay Redis en
  producción pese a que `.env.example` lo menciona (`CACHE_STORE=database`).
- **No hay proceso persistente tipo `queue:work` + supervisor** corriendo, y en shared hosting típico
  no se puede: no se permite un daemon de larga duración, sólo procesos disparados por request HTTP o
  por el cron de hPanel.
- Los webhooks que ya existen (Tiendanube, `app/Http/Controllers/Integraciones/TiendanubeWebhookController.php`)
  funcionan **100% síncronos dentro del propio request HTTP**: reciben, validan HMAC, loguean y
  responden 200 en el mismo ciclo — nada se encola.

## Qué exige este módulo

El cuello de botella es la llamada al LLM para generar la respuesta sugerida: puede tardar varios
segundos, y no debería bloquear:

1. el webhook de Mercado Libre (que exige respuesta rápida, como Tiendanube), ni
2. la experiencia del usuario en el panel (no queremos que abrir la vista de mensajes se cuelgue
   esperando al LLM).

Eso pide un **queue worker real** (`php artisan queue:work` corriendo indefinidamente, gestionado por
`supervisor` o equivalente) que reciba el job "generar sugerencia de respuesta para mensaje X" y lo
procese de forma asincrónica, notificando al frontend (polling o, más adelante, algo tipo
broadcasting/websockets) cuando la sugerencia está lista.

## Por qué se asume VPS desde el diseño

La migración a VPS **ya está resuelta como decisión de negocio** (se viene sí o sí, por razones más
allá de este módulo). Dado eso, no tiene sentido diseñar el bot alrededor de las limitaciones del
shared hosting actual (cron-cada-minuto simulando async, `QUEUE_CONNECTION=sync`) para después
migrarlo — se diseña directamente asumiendo:

- `QUEUE_CONNECTION` real (database o redis) con `queue:work` persistente vía supervisor.
- Acceso SSH sin las restricciones actuales (`exec()` habilitado, crontab real).
- Posibilidad de correr un proceso propio si hiciera falta (ej. un listener de webhooks o un worker
  dedicado sólo a este módulo).

## Qué queda pendiente de definir una vez con el VPS en mano

- Motor de cola: `database` (más simple, ya usado como cache) vs `redis` (mejor throughput, pero suma
  una dependencia nueva a instalar y mantener).
- Cuántos workers / con qué `--tries`, `--timeout`, backoff ante rate limits del LLM o de la API de ML.
- Cómo se notifica al frontend que la sugerencia está lista (polling simple desde la vista, que es
  consistente con el resto del CRM vía AJAX/DataTables, vs algo más real-time).
- Monitoreo del worker (que no se caiga silenciosamente) — alcanza con `supervisor` + logs, o conviene
  algo más (Horizon, si se termina usando Redis).

Ver [decisiones-pendientes.md](decisiones-pendientes.md) para las preguntas que sí necesitan al
usuario, no sólo al VPS.

## Gate operativo antes de activar el switch en producción (spec 033, T029)

El código de la spec 033 (`App\Jobs\GenerarSugerenciaMercadoLibre`) ya está implementado y corre igual
en local con `QUEUE_CONNECTION=sync` (sin cambios de código) — ver
`specs/033-bot-mercadolibre-ia/research.md` R7. **Antes de activar el switch "Bot de Mercado Libre" en
producción** hace falta confirmar puntualmente:

- [ ] El VPS con `QUEUE_CONNECTION=database` (o `redis`) + `php artisan queue:work` bajo `supervisor`
      ya está migrado y corriendo (no el hosting compartido descripto arriba).
- [ ] `OPENAI_API_KEY` está cargada en el `.env` de producción.

Si el switch se activa sin esto, las sugerencias quedan encoladas sin generarse (no se pierden, no
fallan ruidosamente) hasta que el worker esté activo — comportamiento aceptado explícitamente como
Assumption de `specs/033-bot-mercadolibre-ia/spec.md`; el resto del sistema (bandeja, respuesta manual)
sigue funcionando igual mientras tanto.
