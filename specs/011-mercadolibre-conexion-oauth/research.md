# Phase 0 — Research: Conexión Mercado Libre (OAuth)

**Feature**: `011-mercadolibre-conexion-oauth` | **Fecha**: 2026-07-27

Resolución de las incógnitas técnicas antes del diseño. Cada decisión indica qué se eligió, por qué, y
qué alternativa se descartó.

---

## R1. Flujo de autorización de Mercado Libre

**Decisión**: OAuth 2.0 *authorization code* server-side, **sin PKCE**.

- Autorización: `https://auth.mercadolibre.com.ar/authorization?response_type=code&client_id={APP_ID}&redirect_uri={URI}&state={STATE}`
- Canje y renovación: `POST https://api.mercadolibre.com/oauth/token`
- Datos de cuenta: `GET https://api.mercadolibre.com/users/me`

**Rationale**: es un backend con `client_secret` resguardado del lado servidor, que es exactamente el
caso para el que el flujo con secreto está pensado. PKCE en Mercado Libre es **opcional** y se activa
en la configuración de la aplicación del DevCenter; activarlo obligaría a enviar `code_verifier` en el
canje sin aportar seguridad adicional en este escenario.

**Alternativas descartadas**:
- *Implicit flow*: obsoleto y no entrega credencial de renovación.
- *PKCE activado*: suma un paso y un modo de fallo (desincronización entre la config del DevCenter y
  el código) sin beneficio para un cliente confidencial.

**Consecuencia para el plan**: si el usuario activa PKCE en el DevCenter, el canje fallará. Debe quedar
documentado en `quickstart.md` como requisito de configuración: **PKCE desactivado**.

---

## R2. Dominio de autorización por sitio

**Decisión**: derivar el dominio de autorización del sitio configurado, mediante un mapa
`site_id → dominio` (`MLA → auth.mercadolibre.com.ar`), con MLA por defecto.

**Rationale**: la API (`api.mercadolibre.com`) es única para todos los países, pero el dominio de
**autorización** es por país. Hardcodear el argentino funcionaría hoy, pero el sitio ya es un campo
configurable en la spec (FR-009), así que dejar la inconsistencia sería un bug latente.

**Alternativas descartadas**: hardcodear `.com.ar` — más simple, pero contradice tener el campo `sitio`
configurable.

---

## R3. Ciclo de vida de los tokens

**Datos del proveedor** (verificados contra la documentación oficial vía MCP, 27/07/2026):

- `access_token`: ⚠️ **vigencia ambigua en la documentación** — el texto declara 6 horas, los ejemplos
  de respuesta muestran `expires_in: 10800` (3 horas). **Nunca hardcodear**: usar el `expires_in` de
  cada respuesta (FR-028a).
- `refresh_token`: **de un solo uso**, y sólo vale el último generado. Vida máxima **6 meses**.
- El token puede invalidarse **antes** de su vencimiento: cambio de contraseña del usuario,
  actualización de la clave secreta de la app, revocación de permisos, o **4 meses sin llamadas a la
  API**.
- Los permisos **no se piden por la URL de autorización**: se configuran como permisos funcionales en
  la app del DevCenter (ver R13).

**Decisión**: renovación *lazy* (perezosa) con margen de anticipación de **10 minutos**, calculando el
vencimiento desde el `expires_in` recibido, dentro de un lock atómico. Sin tarea programada de
renovación preventiva.

**Rationale**: la renovación perezosa mantiene el módulo funcionando igual en hosting compartido (donde
no se puede garantizar la ejecución periódica de nada) que en un servidor dedicado. El margen de 10
minutos evita que una operación larga arranque con un token que vence en el medio. **La propia
documentación de Mercado Libre recomienda este enfoque**: *"te sugerimos que renueves tu access token
únicamente cuando pierda validez"*.

**Alternativas descartadas**:
- *Renovación por tarea programada cada 5 horas*: depende de que el cron exista y corra, lo que
  contradice el requisito de portabilidad (SC-010). Además, si el cron se pierde una ventana, la
  conexión muere.
- *Renovar en cada llamada*: quemaría la cadena de renovación innecesariamente y multiplicaría las
  llamadas al proveedor.

---

## R4. Exclusión mutua para la renovación (el bug clásico)

**Decisión**: `Cache::lock('ml:refresh', 15)->block(20, fn () => ...)` usando el **driver de cache
`database`**.

**Rationale**: es el punto de riesgo real de esta integración. Como el `refresh_token` es de un solo
uso, dos procesos que renuevan a la vez producen que el segundo invalide la cadena y la conexión muera,
exigiendo re-autorización manual. Laravel soporta locks atómicos con el driver `database`, apoyándose
en la tabla `cache_locks` — **verificado**: la migración `0001_01_01_000001_create_cache_table.php` ya
existe en este proyecto y crea esa tabla. Esto satisface la restricción de portabilidad: el mismo
código toma un lock real en hosting compartido (MySQL) y en VPS (Redis), cambiando sólo `CACHE_STORE`.

Al liberar el lock, el proceso que esperaba **debe releer el registro desde la base** antes de decidir
si todavía necesita renovar — si no, renovaría con la credencial ya consumida.

**Alternativas descartadas**:
- *Lock en memoria del proceso (`static`)*: no protege entre procesos PHP-FPM distintos, que es
  justamente el escenario del bug.
- *`SELECT ... FOR UPDATE` sobre la fila de la cuenta*: funcionaría, pero mantiene una transacción
  abierta durante una llamada HTTP externa — bloqueo de base de datos sujeto a la latencia de un
  tercero. Mala práctica.
- *Redis directamente*: rompe la portabilidad a hosting compartido.

**Verificación exigida**: SC-004 (10 procesos concurrentes → exactamente una renovación) se cubre con
un test de concurrencia simulada.

---

## R5. Almacenamiento de secretos

**Decisión**: casts `encrypted` de Eloquent sobre `client_secret`, `access_token` y `refresh_token`,
respaldados por `APP_KEY`.

**Rationale**: cifrado transparente en reposo, sin gestión manual de claves ni dependencias nuevas.
Cumple FR-010 y FR-034. Nunca se serializan a la respuesta: los modelos declaran `$hidden` y los
controladores devuelven proyecciones explícitas.

**Modo de fallo a cubrir** (edge case de la spec): si `APP_KEY` cambia, el descifrado lanza
`DecryptException`. Se captura y se traduce a un estado de conexión "credenciales ilegibles — volver a
configurar", en vez de un error 500 opaco.

**Alternativas descartadas**:
- *Variables de entorno*: el usuario pidió carga por interfaz, y además obligaría a tocar el servidor
  para cada cambio.
- *Texto plano en base*: descartado por seguridad.
- *Hashing*: inaplicable — las credenciales deben poder recuperarse para usarse.

---

## R6. Protección del retorno de autorización (`state`)

**Decisión**: tabla propia `ml_solicitudes_vinculacion` con token aleatorio de 40 caracteres,
vencimiento de **10 minutos**, marca de consumo y usuario que la originó.

**Rationale**: la sesión sería lo habitual, pero acá es frágil: el usuario vuelve desde un dominio
externo y, con `SESSION_DRIVER=file` y `SameSite=Lax`, la cookie puede no acompañar el retorno según
la configuración. Una tabla lo hace determinista, auditable y permite cumplir FR-016 (un solo uso +
vencimiento) y el escenario de "retorno repetido" (FR-021) sin ambigüedad.

**Alternativas descartadas**:
- *`state` en sesión*: menos código, pero pierde trazabilidad y depende del comportamiento de cookies
  en navegación cross-site.
- *`state` firmado sin persistencia (JWT/HMAC)*: no permite invalidar por un solo uso sin almacenar
  algo igual.

---

## R7. Kill-switch de sólo lectura

**Decisión**: aplicar la verificación en **un único punto**: el método del cliente HTTP que ejecuta
la petición. Si el método no es `GET` y el modo sólo lectura está activo, no se emite la petición: se
registra en el historial con el indicador correspondiente y se devuelve un resultado explícito de
bloqueo.

**Rationale**: un único punto de control es la única forma de garantizar FR-035 al 100% (SC-005). Si
la verificación se dispersa por los servicios que llaman, cualquier código futuro que olvide chequear
abre un agujero — y este switch existe precisamente para poder apuntar a la cuenta real del cliente sin
riesgo.

**Alternativas descartadas**: verificar en cada servicio de negocio — más flexible, pero no auditable
ni garantizable.

---

## R8. Cliente HTTP y manejo de errores

**Decisión**: cliente propio sobre el `Http` facade de Laravel (Guzzle), con política de reintentos
diferenciada por clase de error:

| Situación | Código | Acción |
|---|---|---|
| Credencial inválida / expirada | 401 | Renovar una vez y reintentar; si vuelve a fallar, marcar conexión caída |
| Sin permisos para la operación | 403 | No reintentar; registrar |
| Exceso de solicitudes | 429 | Espera creciente (1s, 2s, 4s), hasta 3 intentos; respetar `Retry-After` si viene |
| Falla temporal del proveedor | 5xx | Hasta 3 intentos con espera creciente; no marcar conexión caída |
| Error de validación | 400 | No reintentar; registrar el detalle devuelto |

**Rationale**: cubre FR-031 a FR-033. Distinguir 401 (credencial) de 403 (permisos) importa: tratar un
403 como token vencido dispararía renovaciones inútiles que consumen la cadena.

**Timeouts**: conexión 10 s, respuesta 30 s. Evita que una demora del proveedor agote el
`max_execution_time` del hosting compartido.

---

## R9. Historial de operaciones

**Decisión**: tabla `ml_operaciones_log` escrita por el propio cliente HTTP, con retención de **30 días
o 5.000 registros** (lo que ocurra primero), depurada de forma oportunista.

**Rationale**: en hosting compartido no se puede depender de una tarea programada para la limpieza
(SC-010), así que la depuración se dispara de forma oportunista tras insertar. Los campos sensibles se
excluyen antes de persistir, no después (FR-034).

**Alternativas descartadas**:
- *Archivo de log de Laravel*: no consultable desde la interfaz (FR-039/FR-040), y crece sin control en
  hosting compartido.
- *Retención por tarea programada*: depende del cron.

---

## R10. Persistencia de las funciones avanzadas

**Decisión**: tabla `funciones_avanzadas` con una fila por función, sembrada por un seeder con las
diez funciones relevadas y su disponibilidad real en el CRM.

**Rationale**: una tabla con filas (en vez de una fila con diez columnas booleanas) permite agregar
funciones sin migración y da lugar natural a los metadatos que pide FR-004 (disponible o no) y FR-008
(quién cambió el estado y cuándo).

**Alternativas descartadas**:
- *Archivo de configuración*: no persiste cambios desde la interfaz.
- *Tabla clave-valor genérica*: pierde el tipado y los metadatos por función.
- *Una columna por función*: obliga a migrar por cada función nueva.

**Nota de consistencia**: el permiso `configuracion.funciones` **ya existe** en el proyecto
(`database/seeders/PermisoSeeder.php:89`, "Administrar Funciones Avanzadas") y hoy protege la pantalla
de Depósitos. Se reutiliza tal cual, sin crear permisos nuevos.

---

## R11. Listado del historial

**Decisión**: `yajra/laravel-datatables-oracle` con procesamiento del lado del servidor, ya presente en
el proyecto (`composer.json`) y usado por los módulos existentes.

**Rationale**: cumple la especificación de diseño obligatoria del `CLAUDE.md` sin sumar dependencias, y
el historial es la única tabla de volumen de esta spec.

---

## R12. Portabilidad de entorno (restricción transversal)

**Decisión**: nada en este módulo requiere un proceso permanente. Toda operación de esta spec es
**sincrónica** dentro del ciclo de request.

**Rationale**: las operaciones acá son de latencia acotada (canje de token, `users/me`, prueba de
conexión). Encolarlas complicaría la interfaz sin beneficio. Las specs siguientes —importación de
publicaciones, sincronización de stock— sí necesitarán trabajo diferido, y ahí el diseño de colas por
base de datos disparadas por tarea programada aplica. Esta spec deja el cliente HTTP y la gestión de
credenciales listos para ser usados desde un job sin cambios.

**Verificación de SC-010**: la suite de tests corre sin depender de Redis ni de procesos externos, lo
que evidencia la portabilidad.

---

## R13. ⚠️ Permisos funcionales — los scopes no se piden por la API

**Hallazgo** (verificado contra la documentación oficial, página *Permisos funcionales*, 27/07/2026):
**no existe un parámetro `scope` en la dirección de autorización**. Los permisos se configuran como
*permisos funcionales* en la aplicación del DevCenter, agrupados por área, cada una con alcance "sólo
lectura" (GET) o "lectura y escritura" (PUT/POST/DELETE).

**Esto corrige un supuesto de la versión inicial de esta spec**, que asumía —por analogía con otros
proveedores de OAuth— que los permisos se solicitaban en la URL.

| Permiso funcional | Recursos | Cuándo hace falta |
|---|---|---|
| **Usuarios** | `users` | **Activo por defecto**. Es todo lo que necesita esta spec |
| Publicación y sincronización | `items`, `prices`, `pictures` | Etapa 2 — stock y precios. **Lectura y escritura** |
| Ventas y envíos | `orders`, `shipments`, `claims`, `returns` | Etapa 2/3 — ventas |
| Comunicación pre y postventa | `questions`, `messages` | Etapa 3 — preguntas y mensajería |
| Métricas del negocio | `trends`, `visits`, reportes | Opcional |
| Promociones y cupones | `offers`, `deals` | Opcional |
| Facturación | `invoices`, `billing` | Opcional |

**Decisión**: el CRM no puede otorgarse permisos a sí mismo, así que la pantalla de configuración
**debe indicarle al usuario cuáles habilitar** en el DevCenter (FR-015a). Si falta uno, la API devuelve
403 con `PA_UNAUTHORIZED_RESULT_FROM_POLICIES`, que **no** debe confundirse con token vencido — refuerza
la distinción 401/403 de R8, ahora con causa concreta y mensaje accionable.

**Consecuencia para las etapas siguientes**: antes de implementar la sincronización de stock hay que
verificar que "Publicación y sincronización" esté habilitado en **lectura y escritura**, no sólo
lectura. Es un requisito de configuración externa que puede bloquear la etapa 2 si se descubre tarde.

---

## R14. ⚠️ Restricciones de la cuenta que autoriza

**Hallazgo**: la autorización debe otorgarla la **cuenta principal** del vendedor. Un usuario
operador/colaborador no puede hacerlo: devuelve `invalid_operator_user_id`. Además, si el titular tiene
**datos pendientes de validación** o alguna inhabilitación, la autorización falla con un mensaje
genérico que no explica la causa.

**Decisión**: traducir ambos casos a mensajes accionables y advertirlo de antemano en la pantalla de
configuración (FR-015b), porque el mensaje del proveedor no permite deducir qué hacer.

**Recomendación adicional de la documentación**, relevante para el proyecto: la aplicación conviene
crearla con **la cuenta del propietario de la solución** (idealmente bajo una entidad legal) para
evitar problemas de transferencia de cuenta más adelante. Decisión a tomar con el cliente: si la app se
crea con la cuenta del desarrollador o con la del negocio.

---

## Riesgos identificados

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Dirección de retorno mal registrada en el DevCenter | La vinculación falla con un error poco claro del proveedor | Mostrarla en pantalla en modo copiable (FR-011) y traducir ese error específico |
| PKCE activado por error en el DevCenter | El canje falla | Documentado en `quickstart.md` como requisito |
| Cadena de renovación rota por concurrencia | La conexión muere y exige re-autorización manual | Lock atómico (R4) + test de concurrencia (SC-004) |
| Cambio de `APP_KEY` | Las credenciales dejan de descifrarse | Estado de error específico y comprensible (R5) |
| Vinculación de una cuenta equivocada | Se opera sobre publicaciones ajenas | Confirmación previa al reemplazar (FR-022) y validación de sitio (FR-019) |
| Escritura accidental sobre la cuenta real durante el desarrollo | Modificación de publicaciones reales | Kill-switch en punto único (R7) |
