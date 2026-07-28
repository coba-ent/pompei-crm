# Quickstart — Validación de la conexión con Mercado Libre

**Feature**: `011-mercadolibre-conexion-oauth`

Guía para validar la feature de punta a punta. Dos niveles: **local** (sin red, con la API simulada) y
**publicado** (contra Mercado Libre real con usuarios de prueba).

---

## Parte 1 — Validación local (sin Mercado Libre)

El flujo OAuth **no puede completarse en local**: Mercado Libre exige una dirección de retorno pública
con conexión segura y no admite direcciones locales. Localmente se valida todo lo demás.

### Preparación

```bash
php artisan migrate
php artisan db:seed --class=FuncionAvanzadaSeeder
```

### Ejecutar la suite

```bash
php artisan test --filter=Integraciones
```

**Debe pasar en verde**, incluyendo:

| Test | Qué prueba | Criterio |
|---|---|---|
| `FuncionesAvanzadasTest` | Las 10 tarjetas, orden, toggles, bloqueo de las no disponibles, permisos | FR-001..008 |
| `MercadoLibreConfiguracionTest` | Guardado de credenciales, cifrado, secreto nunca expuesto, validaciones | FR-009..014 |
| `MercadoLibreOAuthTest` | `state` inválido/vencido/reusado, sitio incorrecto, cancelación, retorno repetido | FR-015..022 |
| `MercadoLibreRenovacionTokenTest` | **Concurrencia**: 10 procesos → una sola renovación | **SC-004** |
| `MercadoLibreModoSoloLecturaTest` | Escrituras bloqueadas y registradas; lecturas permitidas | **SC-005** |
| `MercadoLibreManejoErroresTest` | 401 / 403 / 429 / 5xx según la política de reintentos | FR-031..033 |

### Verificación manual de la pantalla

1. Entrar a **Configuración & Ajustes → Funciones Avanzadas**.
2. Confirmar que aparecen **las 10 funciones en el orden relevado**: Facturación electrónica, Mercado
   Libre, Tiendanube, Reportes por email, Abonos, IA, Retenciones, Ventas sin stock, Depósitos, Lector
   de código de barras (**SC-008**).
3. Confirmar que las no construidas están deshabilitadas y marcadas como no disponibles.
4. Activar/desactivar una función disponible → toast de confirmación, **sin recarga de página**
   (**SC-009**), y el estado persiste al recargar.
5. Entrar a la tarjeta de Mercado Libre → el estado debe ser **"No configurada"**.

### Verificación de que no se filtran secretos (SC-007)

1. Cargar credenciales de prueba.
2. Recargar la pantalla → el campo de la clave secreta **no** debe traer el valor, sólo indicar que está
   cargada.
3. Inspeccionar la respuesta de `GET /configuracion/mercadolibre/estado` en las herramientas del
   navegador → no debe aparecer `client_secret` en ninguna forma.
4. En la base: `SELECT client_secret FROM ml_configuracion` → debe verse cifrado, no legible.

---

## Parte 2 — Validación publicada (contra Mercado Libre real)

### Requisitos previos

| Requisito | Detalle |
|---|---|
| Aplicación publicada | En una dirección pública con conexión segura y certificado válido (no autofirmado) |
| Cuenta de Mercado Libre | Con los datos del titular **validados** — en Argentina es obligatorio para poder crear aplicaciones |
| Aplicación en el DevCenter | Creada en https://developers.mercadolibre.com.ar/devcenter |

### Configuración de la aplicación en el DevCenter

| Campo | Valor |
|---|---|
| **Redirect URI** | `https://TU-DOMINIO/configuracion/mercadolibre/callback` — copiar **exactamente** la que muestra la pantalla del CRM |
| **Permisos funcionales** | ⚠️ **Habilitar YA todos los que se van a necesitar** (ver recuadro abajo), no sólo los de esta etapa |
| **PKCE** | **Desactivado** — si está activo, `code_challenge` pasa a ser obligatorio y el canje falla (ver R1) |
| URL de notificaciones | No se usa en esta spec; queda para la spec de sincronización |

> ⚠️ **Los permisos NO se piden por la API** — se configuran acá, en la aplicación. El CRM no puede
> otorgárselos a sí mismo. Si falta uno, la API responde 403 (`PA_UNAUTHORIZED_RESULT_FROM_POLICIES`).

### ⚠️ Habilitar todos los permisos ANTES de la primera vinculación

**El alcance del token queda congelado con los permisos que la aplicación tenía al momento de
autorizar.** La documentación lo dice explícitamente: *"la aplicación requiere un scope de escritura
para que el usuario pueda otorgar permisos de escritura"*, y el error `unauthorized_client` se produce
cuando *"la autorización existente no autoriza los scopes con los que se quiere crear el token"*.

Consecuencia práctica: si se vincula con sólo "Usuarios" y más adelante se habilita "Publicación y
sincronización", **el token existente no sirve para escribir stock — hay que re-autorizar la cuenta**.
En producción eso significa volver a molestar al cliente.

**Por eso, al crear la aplicación habilitar desde el arranque:**

| Permiso funcional | Alcance | Para qué |
|---|---|---|
| Usuarios | Lectura | Único que usa esta etapa (activo por defecto) |
| **Publicación y sincronización** | **Lectura y escritura** | Etapa 2: stock, precios, pausar/activar publicaciones |
| **Ventas y envíos** | **Lectura y escritura** | Etapa 2/3: órdenes, envíos, reclamos |
| **Comunicación pre y postventa** | **Lectura y escritura** | Etapa 3: preguntas y mensajería |
| Promociones, cupones y descuentos | Lectura y escritura | Opcional, si se van a gestionar ofertas |

Habilitar de más no tiene costo técnico: el usuario ve la lista completa en la pantalla de autorización
y la otorga una sola vez.

> **El error más común**: la Redirect URI no coincide carácter por carácter. Debe ser idéntica en
> esquema, host y ruta, sin barra final de más ni de menos.

> ⚠️ **Autorizar con la cuenta principal.** Un usuario operador/colaborador no puede otorgar el permiso
> (`invalid_operator_user_id`). Y si el titular tiene datos pendientes de validar en Mercado Libre, la
> autorización falla con un mensaje genérico que no explica la causa.

### Crear usuarios de prueba

Se necesitan **dos**: uno vendedor y uno comprador (las operaciones de prueba son entre usuarios de
test). Con un token de la aplicación:

```bash
curl -X POST https://api.mercadolibre.com/users/test_user \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"site_id":"MLA"}'
```

Devuelve nickname, email y contraseña generados. **Guardarlos**: no se recuperan después. Límite de 10
usuarios de prueba por cuenta; caducan a los 60 días sin actividad.

> Anotar estas credenciales en `CREDENCIALES_ACCESO.txt` según la regla del `CLAUDE.md`.

### Recorrido de validación

| # | Acción | Resultado esperado | Cubre |
|---|---|---|---|
| 1 | Cargar App ID y clave secreta, guardar | Toast de confirmación; estado pasa a "Desconectada" | US2 |
| 2 | Copiar la Redirect URI del CRM y verificar que coincide con la del DevCenter | Coinciden exactamente | FR-011 |
| 3 | Presionar "Conectar con Mercado Libre" | Redirige a la pantalla de autorización de Mercado Libre pidiendo lectura, escritura y acceso prolongado | FR-015 |
| 4 | Autorizar con el usuario de prueba **vendedor** | Vuelve al CRM; estado **"Conectada"**; el panel muestra nickname, identificador, correo, tipo de cuenta, sitio, fecha de vinculación y vencimiento del acceso | **SC-002** |
| 5 | Comparar los datos mostrados con los de la cuenta en Mercado Libre | Coinciden | **SC-002** |
| 6 | Presionar "Probar conexión" | Toast de éxito nombrando la cuenta | FR-025 |
| 7 | Activar "Modo sólo lectura" | Aviso visible y permanente de que las escrituras están bloqueadas | FR-038 |
| 8 | Consultar el historial | Aparecen las operaciones con fecha, resultado, código y duración; **ninguna credencial visible** | FR-039, **SC-007** |
| 9 | Presionar "Desconectar" y confirmar | Estado "Desconectada"; los datos de la cuenta y el historial se conservan | FR-026, FR-027 |
| 10 | Volver a conectar | Vuelve a "Conectada" sin necesidad de recargar credenciales | US3 |

**Cronometrar el paso 3 al 4**: debe completarse en **menos de 3 minutos** (**SC-001**).

### Validación de errores (importante — es la mitad del valor de la feature)

| Escenario | Cómo provocarlo | Resultado esperado |
|---|---|---|
| Autorización cancelada | Presionar "Cancelar" en la pantalla de Mercado Libre | Mensaje claro; estado sin cambios; sin datos parciales |
| Redirect URI incorrecta | Cambiarla temporalmente en el DevCenter | Mensaje que apunta explícitamente a esa causa y recuerda la URI correcta |
| Retorno repetido | Recargar la página de retorno tras vincular | Informa que ya se completó; **no** rompe la conexión |
| `state` vencido | Dejar la pantalla de autorización abierta más de 10 minutos | Rechaza y pide reiniciar la vinculación |
| Credenciales de aplicación cambiadas | Editar el App ID con la cuenta vinculada | Advertencia previa; la conexión queda pendiente de re-vinculación |
| **Reemplazo de cuenta** | Con el vendedor ya vinculado, conectar autorizando con el **comprador** | Pide confirmación mostrando ambas cuentas; **la cuenta vigente sigue operando** mientras tanto; al descartar, todo queda como estaba |
| **`APP_URL` mal configurada** | Dejar `APP_URL=http://localhost` en el servidor publicado | La pantalla advierte que la URI de retorno no coincide con el host real y nombra `APP_URL` |

En **todos** los casos el usuario debe ver un mensaje comprensible, nunca un error técnico crudo
(**SC-006**).

### Validación de la renovación (SC-003)

El acceso dura 6 horas. Para verificar la renovación sin esperar:

```sql
UPDATE ml_cuentas SET token_expira_en = NOW() - INTERVAL 1 MINUTE;
```

Después presionar "Probar conexión": debe funcionar **con normalidad y sin intervención**, y
`ultimo_refresh_en` debe actualizarse. Verificar en el historial que aparece una operación
`renovar_token` exitosa.

Para SC-003 completo (7 días ininterrumpidos), dejar la conexión vinculada y volver a verificar a los 7
días sin haber tocado nada.

---

## Parte 3 — Validación de portabilidad (SC-010)

El módulo debe comportarse igual en hosting compartido y en servidor dedicado, **sin cambios en el
código**.

```bash
# Perfil hosting compartido (sin procesos permanentes, sin almacenamiento en memoria)
CACHE_STORE=database QUEUE_CONNECTION=database php artisan test --filter=Integraciones

# Perfil servidor dedicado
CACHE_STORE=redis QUEUE_CONNECTION=redis php artisan test --filter=Integraciones
```

Ambas ejecuciones deben dar el mismo resultado. El test de concurrencia (**SC-004**) es el que valida
que el lock funcione con el driver de base de datos: es el único punto donde la portabilidad podría
romperse en silencio.

> Si el segundo perfil no puede ejecutarse por no tener Redis disponible, es aceptable: el perfil
> relevante para el entorno actual es el primero. Dejarlo registrado.

---

## Criterios de aceptación de la feature

La feature se considera terminada cuando:

- [ ] La suite `--filter=Integraciones` pasa completa en el perfil de hosting compartido
- [ ] Las 10 tarjetas aparecen en el orden relevado (**SC-008**)
- [ ] La vinculación de punta a punta funciona contra un usuario de prueba real (**SC-002**)
- [ ] La renovación forzada funciona sin intervención (**SC-003**)
- [ ] Los 5 escenarios de error muestran mensajes comprensibles (**SC-006**)
- [ ] Ningún secreto es recuperable desde la interfaz ni figura en el historial (**SC-007**)
- [ ] Ninguna operación de la pantalla recarga la página (**SC-009**)
- [ ] `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` quedaron actualizados (Principio I)
- [ ] `CREDENCIALES_ACCESO.txt` refleja los accesos de prueba creados (regla del `CLAUDE.md`)
