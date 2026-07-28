# Phase 1 — Data Model: Funciones Avanzadas + Conexión Mercado Libre

**Feature**: `011-mercadolibre-conexion-oauth` | **Fecha**: 2026-07-27

5 tablas nuevas. Nomenclatura en español (Principio V), con la excepción de los identificadores propios
del contrato externo (`site_id`, `access_token`), que se conservan porque renombrarlos ocultaría la
correspondencia con la documentación de Mercado Libre.

Estas entidades deben replicarse en `docs/modelo_datos.md` (tarea A3, bloqueante por Principio I).

---

## 1. `funciones_avanzadas`

Una fila por función activable del CRM. Sembrada por `FuncionAvanzadaSeeder` con las 10 funciones
relevadas de Contagram, en su orden original.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `clave` | string(50), unique | Identificador estable en código: `facturacion_electronica`, `mercadolibre`, `tiendanube`, `reportes_email`, `abonos`, `ia`, `retenciones`, `ventas_sin_stock`, `depositos`, `lector_codigo_barras` |
| `nombre` | string(100) | Texto visible en la tarjeta |
| `descripcion` | string(255) | Descripción de una línea |
| `icono` | string(50), nullable | Clase de ícono del template |
| `orden` | unsignedSmallInteger | Orden de presentación (1..10), según el relevamiento |
| `disponible` | boolean, default false | Si la función está construida en el CRM. Las no construidas se muestran deshabilitadas (FR-004) |
| `activa` | boolean, default false | Estado del toggle (FR-003) |
| `ruta_configuracion` | string(150), nullable | Nombre de ruta a la que enlaza la tarjeta cuando tiene configuración propia (ej. Depósitos, Mercado Libre) |
| `actualizada_por` | bigint FK → `users.id`, nullable | Quién cambió el estado por última vez (FR-008) |
| `actualizada_en` | timestamp, nullable | Cuándo (FR-008) |
| `created_at` / `updated_at` | timestamps | |

**Índices**: unique(`clave`), index(`orden`).

**Estado sembrado inicial**:

| clave | orden | disponible | activa | ruta_configuracion |
|---|---|---|---|---|
| `facturacion_electronica` | 1 | false | false | — |
| `mercadolibre` | 2 | **true** | false | `configuracion.mercadolibre.index` |
| `tiendanube` | 3 | false | false | — |
| `reportes_email` | 4 | false | false | — |
| `abonos` | 5 | **true** | true | — |
| `ia` | 6 | false | false | — |
| `retenciones` | 7 | **true** | true | — |
| `ventas_sin_stock` | 8 | false | false | — |
| `depositos` | 9 | **true** | true | `configuracion.depositos.index` |
| `lector_codigo_barras` | 10 | false | false | — |

**Reglas**:
- Una función con `disponible = false` no puede activarse: la validación se hace en el servidor, no sólo
  deshabilitando el control en la interfaz.
- El seeder es idempotente (`updateOrCreate` por `clave`): no pisa el estado `activa` que el usuario ya
  haya elegido.
- `abonos`, `retenciones` y `depositos` se siembran activas por reflejar funcionalidad ya construida y
  operativa en el CRM.

---

## 2. `ml_configuracion`

Registro **único** (single-tenant) con los datos de la aplicación del DevCenter.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | Siempre `1` |
| `client_id` | string(100), nullable | App ID del DevCenter. No es secreto |
| `client_secret` | text, nullable | **Cifrado** (cast `encrypted`). Nunca se devuelve a la interfaz (FR-010) |
| `site_id` | string(5), default `MLA` | Sitio de operación |
| `modo_solo_lectura` | boolean, default false | Kill-switch (FR-035) |
| `actualizada_por` | bigint FK → `users.id`, nullable | |
| `created_at` / `updated_at` | timestamps | |

**Reglas**:
- Acceso siempre por un método `actual()` que devuelve la fila 1, creándola vacía si no existe. Nunca se
  consulta con `find()` suelto.
- Se considera **completa** cuando `client_id` y `client_secret` tienen valor.
- Cambiar `client_id` o `client_secret` con una cuenta vinculada invalida esa vinculación: se marca la
  cuenta como `caida` y se advierte antes de guardar (FR-014).
- `modo_solo_lectura` se lee en cada operación de escritura; no se cachea en memoria de proceso, para que
  el cambio tenga efecto inmediato.

---

## 3. `ml_cuentas`

La cuenta de Mercado Libre vinculada. Single-tenant: se espera **una sola fila activa**, pero la tabla no
lo impide estructuralmente, para no cerrar la puerta a múltiples cuentas más adelante (ver Assumptions
de la spec).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `ml_user_id` | bigint, unique | Identificador del usuario en Mercado Libre |
| `nickname` | string(100), nullable | Apodo |
| `email` | string(150), nullable | |
| `tipo_cuenta` | string(50), nullable | `user_type` devuelto por el proveedor |
| `site_id` | string(5) | Sitio de la cuenta. Debe coincidir con el configurado (FR-019) |
| `access_token` | text, nullable | **Cifrado**. `$hidden` |
| `refresh_token` | text, nullable | **Cifrado**. `$hidden`. De un solo uso (FR-029) |
| `token_expira_en` | timestamp, nullable | Vencimiento del acceso vigente |
| `estado` | string(20), default `desconectada` | Enum `EstadoConexion`. **Nunca almacena `no_configurada`** (es derivado) |
| `pendiente_expira_en` | timestamp, nullable | Sólo en estado `pendiente_confirmacion`: vencimiento de la autorización retenida (+15 min) |
| `vinculada_en` | timestamp, nullable | Fecha de vinculación (FR-024) |
| `ultimo_refresh_en` | timestamp, nullable | Último renovado exitoso (FR-024) |
| `ultimo_error` | string(255), nullable | Motivo de la caída, para mostrar en el panel |
| `vinculada_por` | bigint FK → `users.id`, nullable | |
| `created_at` / `updated_at` | timestamps | |

**Índices**: unique(`ml_user_id`), index(`estado`).

### Estados de conexión (`EstadoConexion`)

| Estado | Significado | Cómo se llega | Acción ofrecida |
|---|---|---|---|
| `no_configurada` | No hay credenciales de aplicación cargadas | **Valor derivado**, nunca persistido en esta columna: se calcula cuando `ml_configuracion` está incompleta | Cargar credenciales |
| `desconectada` | Hay credenciales pero ninguna cuenta vinculada, o se desconectó explícitamente | Alta inicial, o acción "Desconectar" | Conectar |
| `conectada` | Vinculación válida y operativa | Canje exitoso, o renovación exitosa | Probar / Desconectar |
| `pendiente_confirmacion` | Autorización retenida: se autorizó con una cuenta **distinta** de la vigente y falta la confirmación del usuario | Canje exitoso cuyo `ml_user_id` difiere del de la cuenta ya conectada (FR-022) | Confirmar reemplazo / Descartar |
| `caida` | Vinculación rota, requiere re-autorización | Renovación fallida irrecuperable, 401 persistente, credenciales de aplicación modificadas, credenciales ilegibles | Volver a conectar |

**Transiciones válidas**:

```
desconectada           → conectada              (canje exitoso, sin cuenta previa)
conectada              → conectada              (renovación exitosa)
conectada              → caida                  (renovación irrecuperable / 401 persistente / cambio de credenciales de app)
conectada              → desconectada           (acción explícita del usuario)
caida                  → conectada              (re-autorización exitosa)
caida                  → desconectada           (acción explícita del usuario)

(nueva fila)           → pendiente_confirmacion (canje exitoso con ml_user_id distinto al vigente)
pendiente_confirmacion → conectada              (el usuario confirma el reemplazo; la anterior pasa a desconectada)
pendiente_confirmacion → (fila eliminada)       (el usuario descarta, o vence sin confirmar)
```

**Transiciones prohibidas**:
- `caida → conectada` sin pasar por una re-autorización real. No se "recupera" una conexión caída
  reintentando (FR-031).
- Dos filas en estado `conectada` simultáneamente. La confirmación de reemplazo es una operación
  atómica: se activa la nueva y se desconecta la anterior en la misma transacción.

**Reglas del estado intermedio** (FR-022):

- La fila `pendiente_confirmacion` guarda los tokens ya canjeados, cifrados igual que los de una cuenta
  activa: la autorización es válida, sólo falta la decisión del usuario.
- **Vence a los 15 minutos**: pasado ese lapso se elimina, junto con sus tokens. Evita que quede una
  autorización viva indefinidamente (edge case de la spec). La depuración es oportunista, al iniciar una
  vinculación nueva o al cargar la pantalla de estado.
- Mientras exista una fila `pendiente_confirmacion`, la cuenta vigente **sigue operando con normalidad**:
  el cliente HTTP resuelve siempre contra la fila `conectada`, nunca contra la pendiente.
- Sólo puede existir una fila `pendiente_confirmacion` a la vez: una vinculación nueva descarta la
  anterior.

**Reglas**:
- Al desconectar se limpian `access_token`, `refresh_token` y `token_expira_en`, pero **se conservan** los
  datos de la cuenta y el historial (FR-027).
- `token_expira_en` se calcula como `now() + expires_in`. La renovación se dispara con 10 minutos de
  anticipación (R3).
- Vincular una cuenta con `ml_user_id` distinto al existente requiere confirmación previa (FR-022) y se
  registra en el historial.

---

## 4. `ml_solicitudes_vinculacion`

Protección del retorno de autorización (parámetro `state`). Ver R6 sobre por qué es una tabla y no la
sesión.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `state` | string(64), unique | Token aleatorio de 40 caracteres |
| `estado` | string(20), default `pendiente` | `pendiente` / `consumida` / `vencida` |
| `expira_en` | timestamp | Emisión + 10 minutos |
| `consumida_en` | timestamp, nullable | |
| `iniciada_por` | bigint FK → `users.id` | Quién disparó la vinculación |
| `ip` | string(45), nullable | Para auditoría del intento |
| `created_at` / `updated_at` | timestamps | |

**Índices**: unique(`state`), index(`expira_en`).

**Reglas**:
- Se valida en el retorno: debe existir, estar `pendiente` y no estar vencida. Cualquier otro caso se
  rechaza y se registra como incidente (FR-016).
- Se marca `consumida` **antes** de canjear el código, para que un retorno repetido no dispare un segundo
  canje (FR-021).
- Las solicitudes vencidas se depuran de forma oportunista al crear una nueva (no requiere tarea
  programada — restricción de portabilidad).

---

## 5. `ml_operaciones_log`

Historial de interacciones con la API, escrito por el `ClienteMercadoLibre`. Nunca contiene credenciales
(FR-034).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `operacion` | string(100) | Etiqueta de dominio: `vincular_cuenta`, `renovar_token`, `probar_conexion`, `obtener_usuario`, `desconectar` |
| `metodo` | string(10) | Verbo HTTP |
| `endpoint` | string(255) | Ruta consultada, **sin** parámetros sensibles |
| `sentido` | string(10) | `lectura` / `escritura` — determina si el kill-switch aplica |
| `resultado` | string(20) | `exito` / `error` / `bloqueada` |
| `codigo_http` | unsignedSmallInteger, nullable | Nulo cuando fue bloqueada (no hubo petición) |
| `duracion_ms` | unsignedInteger, nullable | |
| `mensaje_error` | text, nullable | Detalle devuelto por el proveedor, saneado |
| `payload_bloqueado` | text, nullable | Sólo cuando `resultado = bloqueada`: qué se habría enviado (FR-036) |
| `usuario_id` | bigint FK → `users.id`, nullable | Nulo si la operación fue automática |
| `created_at` | timestamp | Sin `updated_at`: los registros no se modifican |

**Índices**: index(`created_at`), index(`resultado`), index(`operacion`).

**Reglas**:
- El saneado es **previo a persistir**, no posterior: los campos `access_token`, `refresh_token`,
  `client_secret` y el header `Authorization` se eliminan antes de construir la fila.
- Retención: 30 días o 5.000 registros, lo que ocurra primero. Depuración oportunista tras insertar,
  ejecutada de forma probabilística (~1 de cada 50 inserciones) para no penalizar cada operación (R9).
- Sin `updated_at`: es un registro de auditoría, es inmutable.

---

## Diagrama de relaciones

```text
users ──┬─< funciones_avanzadas.actualizada_por
        ├─< ml_configuracion.actualizada_por
        ├─< ml_cuentas.vinculada_por
        ├─< ml_solicitudes_vinculacion.iniciada_por
        └─< ml_operaciones_log.usuario_id

ml_configuracion (fila única) ──── describe la app usada para vincular ────> ml_cuentas
   (relación conceptual, sin FK: la configuración es única y global)

ml_solicitudes_vinculacion ──── se consume al crear/actualizar ────> ml_cuentas
   (sin FK: la solicitud precede a la existencia de la cuenta)
```

**Decisión de diseño**: no se crea FK entre `ml_solicitudes_vinculacion` y `ml_cuentas` porque la
solicitud existe *antes* de saber qué cuenta se va a vincular — la relación es temporal, no referencial.

---

## Notas de implementación para Laravel

- **Casts**: `client_secret`, `access_token` y `refresh_token` con cast `encrypted`. `token_expira_en`,
  `vinculada_en`, `ultimo_refresh_en`, `expira_en`, `consumida_en` con cast `datetime`. `estado` de
  `ml_cuentas` casteado al enum `EstadoConexion`.
- **`$hidden`**: `access_token`, `refresh_token` en `MercadoLibreCuenta`; `client_secret` en
  `MercadoLibreConfiguracion`. Refuerzo, no sustituto de devolver proyecciones explícitas desde los
  controladores.
- **`DecryptException`**: capturarla al leer cualquier campo cifrado y traducirla a
  `CredencialesIlegiblesException`, que la interfaz muestra como estado `caida` con el mensaje "las
  credenciales guardadas no pueden leerse (¿cambió la clave de la aplicación?)". Sin esto, un cambio de
  `APP_KEY` produce un error 500 sin explicación.
- **Sin soft delete**: ninguna de estas tablas tiene impacto fiscal ni contable, así que no aplica el
  requisito del Principio III. El historial cumple la función de trazabilidad.
- **Migraciones**: prefijo de fecha `2026_08_01_0600xx` para quedar después de las existentes
  (`2026_07_31_0700xx`).
