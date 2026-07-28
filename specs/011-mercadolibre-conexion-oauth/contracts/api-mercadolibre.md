# Contrato — API de Mercado Libre (proveedor externo)

**Feature**: `011-mercadolibre-conexion-oauth`

Superficie **exacta** que esta spec consume del proveedor. Deliberadamente mínima: tres endpoints. Todo
lo demás (publicaciones, órdenes, preguntas) queda fuera de alcance y entra en las specs siguientes,
reutilizando el `ClienteMercadoLibre` que esta spec deja construido.

**Base de API**: `https://api.mercadolibre.com` (única para todos los países)
**Base de autorización**: depende del sitio — `https://auth.mercadolibre.com.ar` para `MLA`

> **Verificado contra la documentación oficial** vía el servidor MCP de Mercado Libre (27/07/2026,
> páginas *Autenticación y Autorización* y *Permisos funcionales*, última actualización 15/07/2026).
> Las correcciones respecto de la versión inicial de este contrato están marcadas con ⚠️.

---

## 0. ⚠️ Los permisos NO se solicitan por la API

**Corrección importante**: no existe un parámetro `scope` en la dirección de autorización. Los permisos
se configuran como **permisos funcionales** en la aplicación del DevCenter, y el usuario los otorga al
autorizar. Cada área se habilita con alcance *sólo lectura* (métodos GET) o *lectura y escritura*
(PUT, POST, DELETE).

| Permiso funcional | Recursos | ¿Necesario? |
|---|---|---|
| **Usuarios** | `users` | **Activo por defecto** — es todo lo que necesita esta spec |
| Publicación y sincronización | `items`, `prices`, `pictures` | Etapa siguiente (stock y precios) — **lectura y escritura** |
| Ventas y envíos | `orders`, `shipments`, `claims`, `returns` | Etapa siguiente (ventas) |
| Comunicación pre y postventa | `questions`, `messages`, `claims` | Etapa posterior |

**Consecuencia de diseño**: el CRM **no puede pedir permisos por sí mismo**. La pantalla de
configuración debe indicarle al usuario cuáles habilitar en el DevCenter (FR-015a). Si falta uno, la
API responde 403 con `PA_UNAUTHORIZED_RESULT_FROM_POLICIES` — que **no** debe tratarse como token
vencido.

---

## 1. Autorización (redirección del navegador)

```
GET https://auth.mercadolibre.com.ar/authorization
    ?response_type=code
    &client_id={APP_ID}
    &redirect_uri={REDIRECT_URI}
    &state={STATE}
```

⚠️ **Sin parámetro `scope`** — ver §0.

**Sin PKCE** (ver R1). Si la aplicación tiene PKCE activado en el DevCenter, `code_challenge` y
`code_challenge_method` pasan a ser **obligatorios** y el canje exige `code_verifier`. Requisito de
configuración documentado en `quickstart.md`.

**Retorno exitoso**: `{REDIRECT_URI}?code=TG-xxxx&state={STATE}`
**Retorno con rechazo**: `{REDIRECT_URI}?error=access_denied&error_description=...&state={STATE}`

**Restricciones del proveedor**:
- `redirect_uri` debe coincidir **exactamente** con la registrada en el DevCenter: mismo esquema, host
  y ruta, sin información variable. Los parámetros propios se transportan en `state`.
- Debe usar conexión segura. No se admiten direcciones locales.
- El `code` es de un solo uso y de vida corta.
- ⚠️ **La autorización debe otorgarla la cuenta principal**. Un usuario operador o colaborador no puede
  hacerlo: devuelve `invalid_operator_user_id`.
- ⚠️ Si el titular tiene **datos pendientes de validación** o alguna inhabilitación en la cuenta, la
  autorización falla con el mensaje genérico *"la aplicación no puede conectarse a tu cuenta"*.

---

## 2. Canje del código por credenciales

```
POST https://api.mercadolibre.com/oauth/token
Content-Type: application/x-www-form-urlencoded
Accept: application/json
```

**Cuerpo**:
```
grant_type=authorization_code
client_id={APP_ID}
client_secret={SECRET}
code={CODE}
redirect_uri={REDIRECT_URI}
```

**200**:
```json
{
  "access_token": "APP_USR-...",
  "token_type": "bearer",
  "expires_in": 10800,
  "scope": "offline_access read write",
  "user_id": 123456789,
  "refresh_token": "TG-..."
}
```

> ⚠️ **No hardcodear la vigencia.** La documentación oficial es **contradictoria**: el texto afirma que
> el token dura 6 horas, pero los ejemplos de respuesta muestran `expires_in: 10800` (3 horas). El
> `scope` devuelto refleja lo que el usuario otorgó según los permisos funcionales de la app.
>
> **Regla de implementación (FR-028a)**: calcular el vencimiento siempre como `now() + expires_in` de
> **la respuesta**, nunca con una constante. Fijar 6 horas cuando el token dura 3 produce fallos
> intermitentes difíciles de diagnosticar.

**Errores relevantes**:

| HTTP | `error` | Causa | Manejo |
|---|---|---|---|
| 400 | `invalid_grant` | Código vencido, ya usado, `redirect_uri` distinta, o **titular con datos pendientes de validar** | Traducir; no reintentar |
| 400 | `invalid_client` | `client_id`/`client_secret` incorrectos | Traducir; no reintentar |
| 400 | `invalid_scope` | Alcance inválido o mal formado (valores válidos: `offline_access`, `read`, `write`) | Traducir; no reintentar |
| 400 | `invalid_request` | Falta un parámetro obligatorio o hay valores duplicados | Traducir; no reintentar |
| 400 | ⚠️ `invalid_operator_user_id` | Autorizó un **operador/colaborador** en vez de la cuenta principal | Traducir: "autorizá con la cuenta principal" |
| 400 | ⚠️ `unauthorized_client` | La app no está autorizada para ese usuario, o la autorización no cubre los permisos pedidos | Traducir; revisar permisos funcionales |
| 400 | ⚠️ `unauthorized_application` | **La aplicación está bloqueada** por Mercado Libre | Traducir; no reintentar — requiere gestión con el proveedor |
| 401 | `invalid_client` | Credenciales de aplicación rechazadas | Traducir; no reintentar |

Ninguno se reintenta: son errores de configuración, no fallas transitorias.

---

## 3. Renovación de credenciales

```
POST https://api.mercadolibre.com/oauth/token
```

**Cuerpo**:
```
grant_type=refresh_token
client_id={APP_ID}
client_secret={SECRET}
refresh_token={REFRESH_TOKEN}
```

**200**: misma forma que el canje, **incluyendo un `refresh_token` nuevo**.

> **Regla crítica** (confirmada textualmente en la documentación oficial): *"Sólo permitimos utilizar el
> último REFRESH_TOKEN generado"* y *"el REFRESH_TOKEN sólo puede ser usado una vez y sólo por el
> client_id con el que está asociado; luego de ser utilizado quedará inválido"*.
>
> De acá salen dos requisitos no negociables:
> - la persistencia del nuevo valor ocurre en la misma operación que la renovación (FR-029);
> - dos procesos no pueden renovar a la vez (FR-030, lock atómico — ver R4).
>
> ⚠️ La propia documentación recomienda además *"renovar el access token únicamente cuando pierda
> validez"*, lo que **confirma la decisión de renovación perezosa** tomada en R3.

⚠️ **Vida máxima de la credencial de renovación: 6 meses.** Cumplido ese plazo expira y la
re-vinculación manual es inevitable (FR-028c).

⚠️ **Causas de invalidación anticipada** (el token puede morir antes de `expires_in`, FR-028b):
cambio de contraseña del usuario en Mercado Libre · actualización de la clave secreta de la aplicación ·
revocación de permisos por parte del usuario · **4 meses sin ninguna llamada a la API** · borrado
interno de sesión del lado del proveedor (detección de fraude, desvinculación de dispositivos).

**Errores**:

| HTTP | Causa | Manejo |
|---|---|---|
| 400 `invalid_grant` | Credencial consumida, revocada o vencida (los 6 meses, o cualquiera de las causas de invalidación anticipada) | **Irrecuperable**: marcar conexión `caida`, exigir re-autorización, dejar de reintentar |
| 401 | Credenciales de aplicación inválidas | Marcar `caida` |
| 429 `local_rate_limited` | Exceso de solicitudes | Espera creciente, hasta 3 intentos |
| 5xx | Falla transitoria del proveedor | Hasta 3 intentos; **no** marcar `caida` |

---

## 4. Datos de la cuenta

```
GET https://api.mercadolibre.com/users/me
Authorization: Bearer {ACCESS_TOKEN}
```

**200** (campos consumidos por esta spec; la respuesta real trae muchos más):
```json
{
  "id": 123456789,
  "nickname": "TESTUSER1234",
  "email": "test@testuser.com",
  "site_id": "MLA",
  "user_type": "normal",
  "first_name": "Test",
  "last_name": "Test"
}
```

**Usos**: obtener los datos al vincular (FR-018) y como operación de verificación de "Probar conexión"
(FR-025) — es la lectura más liviana y representativa disponible.

**Validación propia**: si `site_id` de la respuesta no coincide con el configurado, se rechaza la
vinculación (FR-019) y **no se persiste nada**.

---

## Política de reintentos (transversal)

Implementada una sola vez en `ClienteMercadoLibre`:

| Código | Reintenta | Estrategia | Efecto sobre el estado |
|---|---|---|---|
| 2xx | — | — | Registra éxito |
| 400 | No | — | Registra error; traduce el mensaje |
| 401 | Sí, **una vez** tras renovar | Renovación + reintento | Si falla el segundo intento → `caida` |
| 403 | No | — | Registra error. **No** dispara renovación: es falta de **permiso funcional** en la app (`PA_UNAUTHORIZED_RESULT_FROM_POLICIES`), no credencial vencida. El mensaje debe decirle al usuario qué permiso habilitar en el DevCenter |
| 429 | Sí, hasta 3 | Espera creciente 1s/2s/4s; respeta `Retry-After` si viene | Sin cambio de estado |
| 5xx | Sí, hasta 3 | Espera creciente 1s/2s/4s | Sin cambio de estado |
| Timeout / red | Sí, hasta 3 | Espera creciente | Sin cambio de estado |

**Timeouts**: 10 s de conexión, 30 s de respuesta. Con 3 reintentos y esperas crecientes, el peor caso
son ~100 s — por debajo del `max_execution_time` típico de un hosting compartido (120 s).

**Distinción 401 vs 403**: tratar un 403 como credencial vencida dispararía renovaciones inútiles que
consumen la cadena de renovación. Es un error sutil y caro; queda explícito acá para que no se
implemente mal.

---

## Rate limits

El proveedor limita por aplicación y devuelve 429 al excederlo. Esta spec genera un volumen despreciable
(una vinculación, una renovación cada ~6 horas, verificaciones manuales). El manejo de 429 se implementa
igual porque las specs siguientes —sincronización de stock y publicaciones— sí van a acercarse al límite,
y el cliente que ellas usarán es este.

---

## Notas de implementación

- **`Http::fake()`** cubre todos estos contratos en los tests: no se requiere red ni credenciales reales
  para la suite.
- **Nunca registrar** el cuerpo de las respuestas de `/oauth/token`: contienen credenciales. El historial
  guarda sólo metadatos (código, duración, resultado) — el saneado es previo a persistir.
- **Formulario, no JSON**: los endpoints de token esperan `application/x-www-form-urlencoded`. Enviar
  JSON produce un 400 poco descriptivo — error común al integrar.
