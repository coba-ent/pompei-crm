# Phase 0 — Research: Conexión Tiendanube (Aplicación personalizada)

**Feature**: `015-tiendanube-conexion` | **Fecha**: 2026-07-29

Resolución de las incógnitas técnicas antes del diseño. Cada decisión indica qué se eligió, por qué, y
qué alternativa se descartó. A diferencia de `specs/011-mercadolibre-conexion-oauth/research.md`, la
mayoría de las incógnitas acá son mucho más chicas: no hay flujo OAuth que investigar.

---

## R1. Modelo de autenticación: Aplicación personalizada (custom app) vs OAuth público

**Decisión**: Aplicación personalizada — el usuario genera un `access_token` de larga vida desde el
panel de administración de su propia tienda (o desde el Partner Portal si Tiendanube lo expone ahí al
momento de implementar) y lo pega directamente en el CRM, junto con el identificador numérico de la
tienda (`store_id`). No hay redirect a un sitio de autorización externo, no hay `state`, no hay
`refresh_token`.

**Rationale**: decisión explícita del usuario (sesión 2026-07-29, ver spec.md "Contexto y fuentes").
Tiendanube ofrece este camino específicamente para el caso de una única tienda operando su propia
integración — exactamente la naturaleza single-tenant de este CRM. Replicar el modelo OAuth completo de
Mercado Libre no aportaría nada: no existe un intercambio de autorización entre dos partes distintas,
el usuario ya es dueño de ambos sistemas.

**Alternativas descartadas**: aplicación pública vía Partner Portal con flujo `authorization_code` +
`refresh_token` (el modelo que sí usa Mercado Libre) — se descarta por la complejidad de gestión de
credenciales (callback público, `state`, renovación, concurrencia de renovación) sin beneficio real
para un solo negocio.

**A verificar al implementar**: el nombre exacto y la ubicación de la pantalla donde Tiendanube genera
el token para una Aplicación personalizada pueden variar (panel de la tienda vs. Partner Portor). No
cambia el diseño del CRM (que sólo consume identificador + token), pero sí el texto de ayuda que se
muestra al usuario en la pantalla de configuración — anotar en `quickstart.md` el camino verificado.

---

## R2. Base de la API y direccionamiento por tienda

**Decisión**: `https://api.tiendanube.com/v1/{store_id}/{recurso}` para tiendas de habla hispana
(Argentina, México, Colombia, Chile, etc. — el caso de este negocio). El identificador de tienda que
carga el usuario es el mismo que forma parte de la URL de cada llamada.

**Rationale**: es el formato documentado de la API de Tiendanube; a diferencia de Mercado Libre (una
única API para todos los países, con sólo el dominio de *autorización* variando por sitio), acá el
propio identificador de tienda ya resuelve el enrutamiento — no hace falta un mapa de dominios por
país como en `011-mercadolibre-conexion-oauth/research.md` §R2.

**Nota de alcance**: Tiendanube (marca usada en países hispanohablantes) y Nuvemshop (la misma
plataforma en Brasil, dominio `api.nuvemshop.com.br`) son el mismo producto con dominios de API
distintos según el país de la tienda. Esta spec asume una tienda de habla hispana (`api.tiendanube.com`),
consistente con que el negocio opera en Argentina — igual criterio que MLA por defecto en Mercado Libre.

---

## R3. Autenticación de las llamadas: cabecera no estándar

**Decisión**: enviar el token en la cabecera `Authentication: bearer {access_token}` (no
`Authorization`, pese a ser lo esperable por convención HTTP), más una cabecera `User-Agent`
descriptiva de la aplicación (nombre + contacto), que Tiendanube exige para identificar quién llama.

**Rationale**: es un comportamiento documentado y conocido de la API de Tiendanube — un cliente HTTP
que sólo pruebe `Authorization: Bearer` (el estándar de facto que sí usa Mercado Libre) recibiría
rechazos de autenticación sin una razón evidente. Se marca explícitamente para que la implementación no
pierda tiempo redescubriendo esta trampa.

**A verificar al implementar**: confirmar contra la documentación oficial vigente al momento de
implementar (puede haber cambiado); si la cabecera estándar `Authorization` ya es válida en ese
momento, usarla no rompe nada — probar ambas antes de descartar. Dejar el nombre de la cabecera como
una constante de un único lugar (mismo patrón que `ClienteMercadoLibre::API_BASE`), para que un cambio
futuro sea un ajuste de una línea.

---

## R4. Verificación de conexión: endpoint de datos de la tienda

**Decisión**: `GET /{store_id}/store` como operación de "Probar conexión" — devuelve nombre, dominio
principal, país (`country`) y moneda (`currency`) de la tienda, exactamente los datos que FR-009 exige
mostrar en el panel de estado.

**Rationale**: mismo criterio que Mercado Libre (`GET /users/me` como verificación, spec 011 Assumptions)
— es la operación de lectura más liviana y representativa disponible, y de paso confirma que el
`store_id` cargado corresponde al `access_token` cargado (si no coinciden, la API rechaza la llamada).

---

## R5. Manejo de errores y códigos de estado

**Decisión**: mapeo de la respuesta HTTP al estado de conexión, reutilizando la misma taxonomía de
resultado que `ml_operaciones_log` (éxito / error / bloqueada):

| Código | Tratamiento |
|---|---|
| 2xx | Éxito. Actualiza `ultima_verificacion_en` cuando la operación es "Probar conexión". |
| 401 / 403 | Credencial inválida o revocada → conexión pasa a **Caída** (FR-012), se registra el error, no se reintenta. |
| 404 sobre `/store` | El `store_id` no existe o no corresponde al token → mismo tratamiento que 401/403 (edge case "identificador no coincide"). |
| 429 | Exceso de solicitudes → espera creciente y reintento acotado (FR-013), igual que Mercado Libre. |
| 5xx / error de conexión | Falla temporal → reintento acotado sin marcar la conexión como caída (FR-014). |
| Otro 4xx | Error de validación/config no reintentable, se informa el mensaje del proveedor. |

**Rationale**: reutilizar la taxonomía ya probada de Mercado Libre (`ClienteMercadoLibre::ejecutarConReintentos`)
minimiza decisiones nuevas y mantiene el mismo vocabulario de estados en ambas integraciones, más fácil
de mantener para quien ya conoce una.

---

## R6. Modelo de datos: una tabla en vez de la separación config/cuenta de Mercado Libre

**Decisión**: una única tabla `tn_configuracion` (registro único, single-tenant) que guarda tanto las
credenciales (identificador de tienda, token cifrado) como los datos de la tienda vinculada (nombre,
dominio, país, moneda) y el estado de la conexión — a diferencia de Mercado Libre, que separa
`ml_configuracion` (credenciales de la app) de `ml_cuentas` (la cuenta autorizada), porque ahí una
misma app puede autorizarse con cuentas distintas a lo largo del tiempo (spec 011, historia de "cuenta
distinta" y estado `pendiente_confirmacion`).

**Rationale**: en el modelo de Aplicación personalizada no existe ese escenario — no hay una pantalla
de autorización externa donde "otra cuenta" pueda aparecer de vuelta; cambiar el token es simplemente
reemplazar la credencial de la misma fila y volver a probar. Separar en dos tablas replicaría la
complejidad de Mercado Libre sin que exista el problema que la origina. Tampoco hace falta el estado
`PendienteConfirmacion` del enum `EstadoConexion` de Mercado Libre.

**Alternativas descartadas**: reutilizar `EstadoConexion` (enum de Mercado Libre) tal cual — se
descarta porque incluye `PendienteConfirmacion`, que no aplica acá; se crea un enum propio
`Tiendanube\EstadoConexion` con sólo `NoConfigurada | Desconectada | Conectada | Caida`.

---

## R7. Historial de operaciones: tabla propia

**Decisión**: `tn_operaciones_log`, mismo esquema de columnas que `ml_operaciones_log` (operación,
método, endpoint, sentido, resultado, código HTTP, duración, mensaje de error, payload bloqueado,
usuario, fecha), tabla separada.

**Rationale**: ya resuelto en spec.md (Assumptions, "Historiales independientes") — son integraciones y
credenciales distintas, mezclarlas en una tabla polimórfica agregaría una columna de tipo de proveedor y
complicaría los índices sin necesidad real a esta escala (una pantalla de configuración, no un módulo de
alto volumen todavía).

---

## R8. Retención del historial

**Decisión**: mismo criterio numérico ya vigente para `ml_operaciones_log` (`docs/modelo_datos.md` §8)
— se retienen los registros de los últimos **30 días o 5.000 filas**, lo que se alcance primero,
depurados de forma **oportunista** (en el propio flujo de escritura del log, no con una tarea
programada dedicada).

**Rationale**: consistencia entre ambas integraciones; no hay ningún motivo de dominio para que
Tiendanube necesite una ventana de retención distinta a la ya validada para Mercado Libre. La purga
oportunista (en vez de una tarea `Schedule` dedicada) es, además, coherente con la restricción de
portabilidad a hosting compartido (research.md §R11): no depende de que exista un cron corriendo.

---

## R9. Cifrado del token

**Decisión**: reutilizar el cast `encrypted` de Laravel (respaldado por `APP_KEY`), exactamente el
mismo mecanismo que protege `ml_configuracion.client_secret` y las credenciales de `ml_cuentas`.

**Rationale**: es infraestructura ya existente y probada en el proyecto; no se justifica ningún
mecanismo nuevo para un segundo token.

---

## R10. Permiso de acceso

**Decisión**: reutilizar `configuracion.funciones`, el mismo permiso que ya protege la pantalla
Funciones Avanzadas y la configuración de Mercado Libre (spec 011).

**Rationale**: es exactamente el mismo alcance de autorización (configurar una integración avanzada del
CRM); crear un permiso nuevo sólo para Tiendanube fragmentaría el modelo de permisos sin necesidad.

---

## R11. Portabilidad de entorno

**Decisión**: sin procesos permanentes ni locks de concurrencia en esta spec — a diferencia de
Mercado Libre, acá no hay renovación de token que proteger con `Cache::lock`. Todas las operaciones son
síncronas dentro del request.

**Rationale**: al no existir ciclo de renovación, desaparece el único motivo por el que Mercado Libre
necesitaba un lock atómico (R4 de `011-mercadolibre-conexion-oauth/research.md`). El módulo es, por
construcción, igual de portable en hosting compartido que en servidor dedicado, sin trabajo adicional.
