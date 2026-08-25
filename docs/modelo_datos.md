# Modelo de Datos — CRM (Laravel 12 + MySQL)

Este documento define el esquema de base de datos **actualmente implementado**, derivado de
`documentacion_principal_crm.md`. Está pensado para implementarse como migraciones de Laravel 12 (ver
`database/migrations/`).

> **Alcance (24/07/2026):** este documento fue recortado para reflejar sólo lo que está construido y
> validado hoy — Clientes, Productos & Servicios/Stock, y Usuarios/Roles/Permisos —, más las tablas de
> soporte que esos dos módulos usan directamente (categorías, listas de precio, depósitos, condiciones
> de IVA, provincias/localidades). El resto del modelo (compras, tesorería, facturación electrónica,
> informes, integraciones, etc.) se descartó junto con su código y se documentará de nuevo cuando se
> retome cada módulo. **Excepción:** §5 (Ingresos — presupuestos, ventas, otros ingresos, abonos) y §7
> (Egresos — compras, gastos) ya se re-relevaron y están documentados, aunque todavía no implementados
> — Egresos es el próximo módulo a construir.

Convenciones:
- Nombres de tablas y columnas en español, snake_case.
- Sistema **single-tenant**: no existe `empresa_id` en las tablas transaccionales.
- `id` = clave primaria autoincremental (`bigIncrements` en Laravel) salvo que se indique lo contrario.
- Todas las tablas llevan `created_at` / `updated_at`.
- FK = Foreign Key (referencia a `id` de la tabla indicada).

---

## 1. Núcleo: usuarios, roles y permisos

### `usuarios`
Tabla estándar de autenticación de Laravel (`users`): id, name, email, password, remember_token, activo, timestamps.

### `roles`
id, nombre (ej. Admin, Vendedor), descripcion (nullable), es_sistema (boolean, default false —
`true` para roles precargados como Admin: no se pueden eliminar, sí editar sus permisos).

### `permisos`
id, codigo único (`modulo.accion`, ej. `clientes.ver`, `configuracion.usuarios`), descripcion,
modulo (agrupador para la matriz de permisos de la UI de Roles).

> **Módulo `monitoreo` (spec 073, 21/08/2026)**: `monitoreo.ver` (pantalla de Monitoreo, indicador de
> la barra superior y notificaciones) y `monitoreo.gestionar` (destrabar/reactivar publicaciones,
> forzar sincronizaciones y editar el punto de reposición desde el panel). Mismo par que
> `integraciones.ver`/`integraciones.gestionar`. Al inicio se asignan **sólo a Admin** — que los recibe
> solo, porque `RolSeeder` sincroniza Admin con todos los permisos existentes. Marcar una notificación
> como leída requiere sólo `monitoreo.ver`: es una acción sobre el propio estado de lectura del
> usuario, no sobre la integración.

### `permiso_rol` (pivot)
rol_id, permiso_id.

### `notificaciones_leidas` (spec 073, 21/08/2026)
Única parte **persistida** de las notificaciones de la campanita. No guarda el contenido del aviso:
sólo que un usuario ya vio un episodio determinado. Las notificaciones en sí se calculan sobre el
estado vigente en cada consulta — **no hay tabla de histórico** (decisión explícita del negocio).

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| user_id | FK → `users`, cascadeOnDelete | de quién es la lectura |
| clave | string(190) | identificador del **episodio** (ver abajo). 190 y no 255 por el largo del índice único compuesto en utf8mb4 |
| leida_en | timestamp | cuándo la marcó |

Único por `(user_id, clave)`, más índice por `user_id` para el conteo.

**Formato de `clave`**: `reposicion:{producto_id}` y `ml_stock:{ml_item_id}`.

> **El episodio es implícito**: si la marca fuera permanente, marcarla leída una vez silenciaría ese
> producto **para siempre**. Lo que evita eso es que las filas cuya clave ya no corresponde a ninguna
> alerta vigente **se borran** de forma oportunista al calcular el resumen (sin cron ni política de
> retención): al reponerse el stock la marca desaparece, y cuando el producto vuelve a caer no hay
> nada que la silencie.
>
> **Por qué la clave NO lleva timestamp**: la primera versión de esta spec la armaba como
> `reposicion:{producto_id}:{MAX(movimientos_stock.created_at)}`. Cada venta del producto cambia ese
> timestamp, así que un producto que se mantiene por debajo de su punto volvería a alertar en cada
> venta — los que más rotan serían los más molestos, y el usuario terminaría ignorando la campanita.
> Riesgo residual asumido a cambio: si el problema se resuelve y reaparece entre dos consultas del
> mismo usuario, la limpieza no llegó a correr y la alerta le figura como ya leída.

### `rol_usuario` (pivot)
usuario_id (FK), rol_id (FK).

### `condiciones_iva` (tabla lookup/seed)
id, nombre (Responsable Inscripto, Monotributista, Consumidor Final, Exento, No Categorizado), codigo_afip, requiere_cuit (bool, default false; true para Responsable Inscripto y Monotributista).

---

## 2. Base de Datos: clientes, productos, stock

### `categorias`
Tabla genérica de catálogo, usada hoy por Clientes (categoría de venta). Se conserva pensada para
reutilizarse por futuros módulos (Compras, Gastos, etc.) cuando se retomen.

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tipo | enum(`venta`,`compra`,`producto`,`gasto`,`ingreso`) | sólo `venta` está en uso hoy (Clientes) |
| categoria_padre_id | FK → categorias, nullable | para subcategorías |
| nombre | string | |
| es_sistema | boolean, default false | true para categorías precargadas no editables/eliminables |
| activo | boolean, default true | permite desactivar sin eliminar |

### `clientes`
Derivado del formulario real de alta/edición de Contagram. Los datos de facturación son un bloque
fiscal separado del domicilio/teléfono comercial (persiste aunque la verificación de CUIT contra
ARCA/facturación esté fuera de alcance por ahora).

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | **obligatorio** — "Cliente" (empresa o nombre y apellido). Único campo requerido |
| nombre_pila | string, nullable | "Nombre" (de pila) |
| apellido | string, nullable | |
| apodo_ml | string, nullable | Apodo de Mercado Libre |
| pagina_web | string, nullable | |
| email, telefono, telefono_celular | string, nullable | contacto comercial |
| domicilio, localidad, provincia, cp | string, nullable | domicilio comercial |
| nota | text, nullable | nota general |
| **— Bloque de facturación —** | | |
| razon_social | string, nullable | razón social fiscal (puede diferir de `nombre`) |
| tipo_documento | string, nullable | 'CUIT' (default) / 'CUIL' / 'DNI' / 'Pasaporte' / 'CDI' |
| cuit | string, nullable, único (ignora NULL) | N° de documento fiscal, **cualquiera sea su tipo** (el nombre de la columna es histórico). Validación de DV sólo si tipo_documento es CUIT/CUIL. **`tipo_documento` es el único campo que determina qué es este número** — de él se deriva el `DocTipo` que se envía a ARCA (ver `Cliente::datosFiscalesArca()`); leer esta columna asumiendo CUIT hace que ARCA rechace el comprobante |
| condicion_iva_id | FK → condiciones_iva, nullable | |
| tipo_comprobante_defecto | string, nullable | A/B/C/E |
| domicilio_fiscal, localidad_fiscal, provincia_fiscal, cp_fiscal | string, nullable | domicilio fiscal (separado del comercial) |
| telefono_fiscal, telefono_celular_fiscal | string, nullable | teléfonos fiscales |
| **— Ventas —** | | |
| categoria_id | FK → categorias (tipo=venta), nullable | |
| lista_precio_id | FK → listas_precio, nullable | |
| descuento_general_pct | decimal, nullable | 0–100 |
| nota_cliente | text, nullable | "Nota para el Cliente" |
| saldo_inicial | decimal | default 0 |
| saldo_inicial_fecha | date, nullable | Fecha de apertura de la cuenta corriente. Se incorpora al aging desde spec 031: `App\Services\Tesoreria\CuentaCorriente::aging()`/`porCliente()` suman `saldo_inicial` al bucket que le corresponda según esta fecha (nula → "A Vencer"), afectando Dashboard (spec 010) y "Saldos Clientes"/"Movimientos" (spec 029) |
| campos_personalizados | json, nullable | "Agregar Nuevo campo". Campos adicionales **propios de ese cliente** (no hay catálogo global): array de objetos `[{ "nombre", "tipo", "opciones": [...]|null, "valor" }]`. `tipo` = `texto`\|`numerico`\|`fecha`\|`opciones` |
| activo | boolean | default true (baja lógica) |

### `provincias` / `localidades`
Catálogo geográfico de Argentina (dataset oficial georef), usado para poblar los selects linkeados de
provincia/localidad del formulario de Clientes (país fijo: Argentina). En `clientes` se sigue
guardando el **nombre** (string) de provincia y localidad — comercial y fiscal —, no la FK.

- `provincias`: id, nombre (unique). 24 registros.
- `localidades`: id, provincia_id (FK → provincias, cascade), nombre. ~4.037 registros. Índices en `provincia_id` y `nombre`. Se puebla con `GeoArgentinaSeeder` desde `database/data/ar_geo.json`. Endpoint `geo/localidades?provincia=<nombre>` devuelve las localidades de una provincia.

### `cliente_contactos`
Personas de contacto de un cliente (1..N) — botón "+ Agregar Persona de Contacto" del formulario.

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| cliente_id | FK → clientes | cascade on delete |
| nombre | string | |
| apellido | string, nullable | |
| telefono | string, nullable | |
| telefono_celular | string, nullable | "Cel." |
| email | string, nullable | |
| enviar_mails | boolean | default false — "Enviar también mails a esta dirección" |

### `proveedores` (reincorporada en spec 003, 24/07/2026)
Espejo de `clientes`, con las diferencias documentadas en la doc principal §2.3.

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | **único campo obligatorio** — "Proveedor" |
| nombre_pila, apellido | string, nullable | |
| pagina_web, email, telefono, telefono_celular | string, nullable | (sin `apodo_ml`, exclusivo de Cliente) |
| domicilio, localidad, provincia, cp | string, nullable | domicilio comercial |
| nota | text, nullable | |
| razon_social, tipo_documento, cuit, condicion_iva_id, tipo_comprobante_defecto | — | idéntico al bloque de facturación de `clientes` |
| domicilio_fiscal, localidad_fiscal, provincia_fiscal, cp_fiscal, telefono_fiscal, telefono_celular_fiscal | string, nullable | |
| categoria_id | FK → categorias (tipo=compra), nullable | "Categoría Compras" (equivalente al `categoria_id` de venta de Cliente). **Sin** `lista_precio_id` ni `descuento_general_pct` (Proveedor no vende con lista de precios) |
| nota_interna | text, nullable | reemplaza `nota_cliente` |
| saldo_inicial, saldo_inicial_fecha | decimal/date | igual semántica que Cliente |
| campos_personalizados | json, nullable | mismo formato que `clientes.campos_personalizados` |
| activo | boolean | default true |

Índices: `unique(cuit)`, `index(nombre)`, `index(activo)`. Método de dominio
`Proveedor::tieneOperaciones()`: existe algún `Producto` con `proveedor_id` apuntando a este proveedor
(bloquea el `destroy()` físico, igual patrón que `Cliente`/`Producto`).

### `proveedor_contactos` (reincorporada en spec 003)
Espejo exacto de `cliente_contactos` — mismos campos (`nombre`, `apellido`, `telefono`,
`telefono_celular`, `email`, `enviar_mails`), FK `proveedor_id` → `proveedores` (cascade on delete).

### `productos`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | |
| codigo | string, nullable | SKU del producto base — **no es único** (corrección 02/08/2026: el negocio reutiliza códigos entre productos distintos; ninguna integración vigente matchea por `codigo`, ML/Tiendanube vinculan por `id`) |
| tipo | enum(`producto`,`servicio`) | los servicios no controlan stock |
| descripcion | text, nullable | |
| imagen | string, nullable | ruta relativa en disk `public` (upload "+ Agregar Imagen"). Accesor `imagen_url` expone la URL pública |
| mostrar_en_ventas | boolean | default true |
| precio_venta | decimal(14,2) | ≥ 0 |
| iva_venta_pct | string(12) | Código de la opción de Contagram, no un % libre: `5`, `10.5`, `21`, `27`, `exento`, `no_gravado`. Default `21`. El % numérico se deriva con `Producto::porcentajeIva()` |
| mostrar_en_compras | boolean | default true |
| costo | decimal(14,2) | ≥ 0, default 0 |
| iva_compra_pct | string(12) | Mismas opciones y semántica que `iva_venta_pct`. Default `21` |
| activo | boolean | reemplaza al "eliminar" — no se puede eliminar con operaciones cargadas |
| proveedor_id | unsignedBigInteger, nullable, FK → `proveedores` | reincorporado en spec 003 (24/07/2026): la columna ya existía en el esquema (nunca tuvo FK real — ver nota abajo), se reincorporan el fillable/relación `Producto::proveedor()` y la FK de base |
| punto_reposicion | unsignedInteger, **NOT NULL**, default `0` | **Columna nueva (spec 073, 21/08/2026)**. Cantidad mínima deseada. `0` → el producto **no se controla** (no genera alerta ni notificación). Sólo aplica a `tipo='producto'` y `activo=true`. Sin fila en `stocks` para el depósito evaluado = stock 0; stock negativo = caso más urgente; con variantes se compara contra el total del producto en ese depósito. **Dejó de ser nullable el 25/08/2026** (migración `punto_reposicion_no_nullable`): `null` y `0` significaban lo mismo, así que los NULL existentes se backfillearon a 0 y el único valor de "no se controla" es ahora `0`. La entrada sí puede venir vacía (modal, celda de importación): `Producto::setPuntoReposicionAttribute()` la normaliza a 0 |

> **Historia de `proveedor_id` (spec 003):** la migración de `productos` corrió (2026-07-19) **antes**
> de que existiera `proveedores` (2026-07-20 originalmente), así que su `if (Schema::hasTable(...))`
> nunca se cumplió y la columna quedó sin FK real. Al reincorporar Proveedores se agregó una migración
> aparte sólo para la FK (`ON DELETE SET NULL`), como defensa en profundidad — la regla de negocio real
> ("no eliminar proveedor con productos asociados") se aplica en `ProveedorController::destroy()` vía
> `Proveedor::tieneOperaciones()`, no depende del constraint de base.

> **`punto_reposicion` (spec 073, 21/08/2026):** un producto está **en punto de reposición** cuando su
> stock es **≤** este valor. El mismo número alimenta **dos controles**: **A reponer** (stock en
> **Local**, todo el catálogo → "¿le compro al proveedor o traigo de Full?") y **Riesgo de
> publicación** (stock **Local + Full**, sólo publicados en ML → "¿se me cae la publicación?").
> Reemplaza el umbral fijo de 3 unidades que el panel de Monitoreo tenía escrito a mano.
>
> **Ojo con los depósitos**: los únicos vigentes son **Local (5)** y **Full (6)**, y
> `ml_configuracion.deposito_id = 5` — o sea, "el depósito de Mercado Libre" **es** el Local. No
> definir el segundo control "contra el depósito de ML": daría la misma lista que el primero. Lo que
> los distingue es **Full**.
>
> **Historia**: la importación de datos reales dejó este dato modelado como una `lista_precio` más
> (id 14, "Punto Reposición") para no tocar schema. La spec 073 lo migra a esta columna con
> `migracion:punto-reposicion` (dry-run por defecto) y **elimina esa fila de `listas_precio` junto con
> sus `precios_producto`**. El borrado **aborta sin modo forzado** si la lista está referenciada por
> `clientes.lista_precio_id`, `ventas.lista_precio_id`, `presupuestos.lista_precio_id`,
> `ml_configuracion.lista_precio_id`/`lista_precio_id_premium`,
> `tiendanube_configuracion.lista_precio_id` o `empresa.lista_precio_id` — lo que se rompería del otro
> lado son precios de venta reales. Efecto colateral esperado: como el listado de Productos genera una
> columna por lista activa, esa columna desaparece sola del listado y del export.

> **Stock inicial al crear (24/07/2026):** el formulario "Nuevo Producto" acepta `stock_inicial`
> (numérico) y `stock_inicial_deposito_id` como campos de **request únicamente** — no son columnas de
> `productos`. Si `stock_inicial > 0` y el producto controla stock, el controller genera un movimiento
> en `movimientos_stock` (tipo `ajuste`, descripción "Registro inicial") y actualiza `stocks` a través
> de `StockService::ajustar()`, igual que un ajuste manual posterior.

### `producto_variantes`
Variantes de un producto (ej. talle, color) — infraestructura conservada para una futura integración
con canales externos. Un producto sin variantes no lleva filas acá. La UI de alta está oculta en el
modal (ver doc principal §2.2).

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| producto_id | FK → productos | cascade on delete |
| sku | string, nullable | código de la variante — **no es único** (corrección 02/08/2026, mismo motivo que `productos.codigo` arriba) |
| talle | string, nullable | |
| color | string, nullable | |
| nombre | string, nullable | etiqueta libre si no aplica talle/color |
| precio_extra | decimal(14,2), nullable | diferencia de precio respecto del producto base (opcional) |
| activo | boolean | default true |

> El stock (`stocks` / `movimientos_stock`) puede referenciar opcionalmente `variante_id` (nullable):
> si el producto tiene variantes, el stock se lleva por variante+depósito; si no, por producto+depósito.

### `listas_precio`
id, nombre (ej. Mayorista, Minorista, Tarjeta), activo.

> **Baja de "Punto Reposición" (spec 073, 21/08/2026)**: la fila id 14 llamada "Punto Reposición" —que
> la importación de datos reales había usado para guardar un dato que **no es un precio**— se elimina
> junto con sus `precios_producto`, después de migrar sus valores a `productos.punto_reposicion`. Ver
> la nota de esa columna en `productos` para el procedimiento y la verificación previa obligatoria.

### `precios_producto`
producto_id (FK), lista_precio_id (FK), precio (decimal(14,2), ≥ 0). Único por (producto_id, lista_precio_id).

> **Columnas dinámicas en el listado (24/07/2026):** el listado de Productos y su export CSV agregan
> **una columna por cada fila activa de `listas_precio`** (subselect contra `precios_producto`,
> ordenado por `id`), en vez de una columna fija "Lista 1". Si se crea o desactiva una lista, el
> listado se ajusta automáticamente sin cambios de código.

### `depositos`
id, nombre, activo. (Multidepósito). Gestionable desde Configuración & Ajustes → Depósitos (spec 005).
`Deposito::tieneOperaciones()`: existe alguna fila en `stocks` con `cantidad != 0` para ese depósito,
o alguna fila en `movimientos_stock` con ese `deposito_id` — bloquea la eliminación física (sólo se
puede inactivar), mismo patrón que `Cliente`/`Proveedor`/`Producto::tieneOperaciones()`.

### `stocks`
producto_id (FK), variante_id (FK → producto_variantes, nullable), deposito_id (FK), cantidad (decimal(14,3)).
Único por (producto_id, variante_id, deposito_id). Representa el stock **actual** (foto); el histórico va
en `movimientos_stock`.

> **Fijar stock a un valor absoluto — `StockService::fijar()` (spec 074, 22/08/2026):** cuando el
> llamador conoce la **cantidad final deseada** (y no un delta), leer el stock actual y después llamar a
> `ajustar()` con la diferencia **es un bug de concurrencia**: la lectura queda fuera de la transacción
> que escribe, así que cualquier operación que mueva ese stock en el medio (una venta, una compra, otro
> ajuste) se pisa — *lost update*. Fue exactamente lo que hacía el importador de productos, con una
> ventana real de minutos (el asistente procesa en tandas de 1.000 filas con el negocio operando).
>
> `fijar(producto, variante, deposito, cantidadDeseada, descripcion, usuario)` resuelve lectura, cálculo
> del delta y escritura del `MovimientoStock` **dentro de una única transacción con `lockForUpdate()`**
> sobre la fila de `stocks`. Si la cantidad deseada ya coincide con la actual no escribe nada. `ajustar()`
> **no se deprecia**: sigue siendo lo correcto cuando el llamador ya conoce el delta (ajuste manual desde
> la UI, NC/ND, reintegros).
>
> **Regla para el futuro:** cualquier llamador que combine `disponibilidad()` + `ajustar()` para llegar a
> un valor absoluto tiene el mismo bug y debe migrar a `fijar()`.

### `movimientos_stock`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| producto_id | FK → productos | |
| variante_id | FK → producto_variantes, nullable | si el producto tiene variantes |
| deposito_id | FK → depositos | |
| tipo | enum(`entrada`,`salida`,`ajuste`,`transferencia`) | `transferencia` = movimiento entre depósitos (2 filas: salida negativa + entrada positiva). `ajuste` = ajuste manual o NC/ND que afecta stock (spec 008/009). **`salida`/`entrada` = Ventas, desde la spec 012** (`salida` al crear la Venta, `entrada` al eliminarla o reintegrar por edición). **`entrada`/`salida` = Compras, desde la spec 030** (`entrada` al crear la Compra, `salida` al eliminarla o reintegrar por edición — mismo patrón que Ventas mas invertido) |
| cantidad | decimal | |
| origen_type / origen_id | polimórfico | **`Venta` desde la spec 012**; **`Compra` desde la spec 030**. En los ajustes manuales y en NC/ND queda nulo |
| fecha | **datetime** (spec 051) | Ventas/ajustes/transferencias: fecha y **hora real** del momento en que se generó el movimiento (`now()`). **Compras (spec 030): `fecha_emision` de la Compra** en el alta (no la fecha/hora de guardado), para que el histórico refleje cuándo entró realmente la mercadería aunque la carga sea retroactiva — esta excepción se mantiene con hora `00:00:00` (spec 051 FR-001); el reintegro por eliminación de Compra sí usa fecha/hora real del momento en que se elimina. Movimientos creados antes de spec 051 quedan con hora `00:00:00` (MySQL completa la parte horaria al ensanchar DATE→DATETIME). |
| usuario_id | FK → usuarios, nullable | quién generó el movimiento |

> **"Stock Saldo" del Informe de Stock (spec 003, 24/07/2026):** columna **calculada, no persistida** —
> `SUM(cantidad) OVER (PARTITION BY producto_id, variante_id, deposito_id ORDER BY fecha, id)` sobre el
> histórico **completo** de `movimientos_stock` (nunca sobre el subconjunto filtrado por pantalla). Se
> proyecta como columna adicional de `InformeStockController::data()`, análoga a los `addSelect` de
> subconsulta ya usados en `ProductoController::queryFiltrada()` para las columnas dinámicas de lista de
> precio. El filtro "Operación" del informe expone `ajuste`/`transferencia`, **`salida`/`entrada`
> generados por Ventas (spec 012)** y **`entrada`/`salida` generados por Compras (spec 030)**.

### `importacion_corridas` (spec 078)

Una fila por cada corrida confirmada del Paso 3 del asistente de Importar Datos, **sólo solapa
Productos & Servicios**. Habilita "Deshacer import".

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| entidad | string | `productos` en esta spec; columna presente por si se extiende a Clientes/Proveedores |
| usuario_id | FK → usuarios, nullable | quién ejecutó el import |
| archivo_original | string | nombre del archivo subido en el Paso 1 |
| confirmado_en | datetime | inicio de la primera tanda del Paso 3 |
| deshacer_disponible_hasta | datetime | `confirmado_en + 48h`, fijo (no se recalcula) |
| filas_creadas / filas_actualizadas / filas_fallidas | unsignedInteger, default 0 | acumulado a través de todas las tandas de la corrida |
| deshecho_en | datetime, nullable | `null` = corrida vigente/no deshecha |
| deshecho_por_id | FK → usuarios, nullable | quién ejecutó el undo |
| filas_revertidas / filas_no_revertidas | unsignedInteger, nullable | resultado del undo, sólo si `deshecho_en` no es null |

Estado derivado (no columna): `vigente` / `deshecho` / `vencido`, según `deshecho_en` y
`deshacer_disponible_hasta` vs. `now()`.

### `importacion_filas_snapshot` (spec 078)

Una fila por cada producto creado o actualizado por una `importacion_corrida` (no se generan para
filas fallidas ni productos no tocados).

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| importacion_corrida_id | FK → importacion_corridas, cascade on delete | |
| producto_id | FK → productos, nullable | |
| modo | enum(`alta`,`actualizacion`) | |
| existia | boolean | `false` en altas |
| estado_anterior | json, nullable | snapshot de `productos` (atributos), null si `existia=false` |
| precios_anteriores | json, nullable | `[{lista_precio_id, precio}]` previos, null si `existia=false` |
| stock_anterior | json, nullable | `[{deposito_id, cantidad, ultimo_movimiento_stock_id}]` previo, null si `existia=false` — `ultimo_movimiento_stock_id` detecta operaciones posteriores que bloquean el undo de esa fila |
| numero_fila | unsignedInteger | fila del archivo original |
| estado_undo | enum(`pendiente`,`revertida`,`no_revertida`), default `pendiente` | |
| motivo_no_revertida | string, nullable | sólo si `estado_undo=no_revertida` |

Índice `(importacion_corrida_id, producto_id)`. El undo de stock reutiliza `StockService::fijar()`
(mismo mecanismo bajo lock que ya usa `ImportadorFilas::actualizarProducto()`, spec 074); el undo
de precio queda auditado en `logs_auditoria` con origen `"Deshacer import"` (nuevo caso en
`OrigenCambioPrecio`, junto al `IMPORTACION` ya existente).

---

## 3. Diagrama de relaciones (resumen textual)

```
usuarios N───N roles (vía rol_usuario) — roles N───N permisos (vía permiso_rol)

entidades base: clientes, proveedores, productos, depositos, listas_precio, categorias, condiciones_iva

clientes 1───N cliente_contactos
proveedores 1───N proveedor_contactos, productos (proveedor_id, nullable)
productos 1───N producto_variantes, precios_producto, stocks, movimientos_stock
producto_variantes 1───N stocks, movimientos_stock (si el producto tiene variantes)
depositos 1───N stocks, movimientos_stock
```

---

## 4. Notas de implementación para Laravel 12

- Sistema single-tenant: **no** hay Global Scope por empresa ni `empresa_id` en las tablas.
- Las relaciones polimórficas (`movimientos_stock.origen`) se implementan con `morphTo`/`morphMany` estándar de Eloquent; se usan para Ventas (spec 012) y Compras (spec 030), además de los ajustes manuales (sin origen).
- Un `FormRequest` específico por módulo (`StoreClienteRequest`, `StoreProductoRequest`, etc.) valida antes de tocar la base de datos.

Las migraciones de Laravel correspondientes a este modelo están en `database/migrations/`.

---

## 5. Ingresos: presupuestos, ventas, otros ingresos, abonos

> **Estado:** `etiquetas`, `etiquetables`, `presupuestos`, `presupuesto_items`, `presupuesto_conceptos`,
> `ventas`, `venta_items`, `venta_conceptos`, `cobros`, `otros_ingresos`, `notas_credito_debito`,
> `nota_credito_debito_items` y `remitos` están **implementadas** (spec 008-ingresos-ventas-presupuestos,
> 30/07/2026) — ver `specs/008-ingresos-ventas-presupuestos/data-model.md` para el detalle definitivo
> (fuente de verdad actual de estos campos por encima de lo que sigue en esta sección). Las tablas de
> **Abonos** (`abono_id` en `ventas`, etc., más abajo) siguen sin implementar — esa spec no se construyó
> todavía.

### `etiquetas` (catálogo global)
id, nombre (unique). Usado por Presupuestos y Ventas ("+ Nueva Etiqueta" en el popup de Etiquetas).

### `etiquetables` (pivot polimórfico)
etiqueta_id (FK → etiquetas), etiquetable_type, etiquetable_id. Permite reutilizar el mismo catálogo
de etiquetas entre `presupuestos` y `ventas` sin dos pivots separados.

### `vendedores` (catálogo global — spec 020, implementada)
id, nombre (unique). Catálogo plano: sin jerarquía, sin tipo, sin `activo`, sin `es_sistema` — a
diferencia de `categorias` (no lo necesita, ver `specs/020-vendedores/research.md` R1). ABM inline
desde el select de Vendedor de Presupuestos/Ventas y desde el select de "Vendedor por defecto" de
Configuración Tiendanube/MercadoLibre (mismo patrón que Categorías: crear/renombrar/eliminar sin
pantalla propia; eliminar está bloqueado si el vendedor está en uso en cualquiera de las cuatro
tablas que lo referencian). Reemplaza al `vendedor_id → usuarios` que existía hasta la spec 020 (ver
nota en `presupuestos`/`ventas` más abajo); la migración a esta tabla preserva el historial existente
(un vendedor por cada usuario que ya aparecía como vendedor de alguna Venta/Presupuesto).

### `presupuestos`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nro_presupuesto | string, único | autogenerado, mostrado como "Nro. Presupuesto" |
| cliente_id | FK → clientes | |
| categoria_id | FK → categorias (tipo=venta), nullable | autocompletada desde `clientes.categoria_id` |
| lista_precio_id | FK → listas_precio, nullable | autocompletada si el cliente tiene una asignada |
| fecha_emision | date | |
| fecha_validez | date, nullable | |
| estado | enum(`pendiente`,`rechazado`,`aceptado`) | "Vencido" es derivado (fecha_validez pasada + estado=pendiente), no una columna |
| venta_id | FK → ventas, nullable | seteado cuando se usa "Crear Venta" (evita reconvertir el mismo presupuesto) |
| subtotal_sin_descuento, descuento, subtotal_con_descuento, total | decimal(14,2) | descuento = `descuento_general_pct` aplicado sobre el subtotal de ítems |
| descuento_general_pct | decimal, nullable | 0–100 |
| descuento_general_tipo, descuento_general_monto | enum(`porcentaje`,`monto`) NOT NULL default `porcentaje`; decimal(12,2) nullable | **Columnas nuevas (spec 060, pendiente de implementar)** — mismo patrón que `ventas.descuento_general_tipo` (ver esa fila para el detalle). |
| nota_cliente, nota_interna | text, nullable | |
| vendedor_id | FK → vendedores, nullable | **Corregido spec 020**: hasta la spec 020 apuntaba a `usuarios` y se autocompletaba en silencio con el usuario logueado; desde la spec 020 es un catálogo propio (tabla `vendedores`, ver §Vendedores) elegido explícitamente en el formulario — "Vendedor" (quién vendió) y "Usuario" (quién está logueado) son conceptos independientes, confirmado por el informe de relevamiento real (Vendedor y Usuario son dos filtros distintos). |
| formas_pago, metodos_envio | string, nullable | texto libre, sin autocompletado detectado |

### `presupuesto_items`
id, presupuesto_id (FK, cascade), producto_id (FK → productos, nullable — permite ítem libre sin
producto asociado), descripcion (string, del producto o libre), cantidad (decimal), precio_unitario
(decimal), descuento_pct (decimal, nullable), iva_pct (string(12), igual codificación que
`productos.iva_venta_pct`), subtotal (decimal), subtotal_con_iva (decimal).

### `presupuesto_conceptos` (Percepciones / Impuestos Internos / Intereses)
id, presupuesto_id (FK, cascade), tipo (enum `percepcion`,`impuesto_interno`,`interes`), concepto
(string, del selector "Seleccionar"), monto (decimal). Múltiples filas por tipo permitidas ("+
Percepciones" agrega N).

### `ventas`
Mismo esqueleto que `presupuestos` (mismos bloques Cliente/Categoría/Lista de Precios/Descuento/
Etiquetas/Notas/Formas de Pago/Métodos de Envío/Vendedor), con estos agregados:

| Campo | Tipo | Notas |
|---|---|---|
| presupuesto_id | FK → presupuestos, nullable | "Creada Desde": null = creada directamente como Venta |
| tipo_comprobante | enum(`A`,`B`,`C`,`E`) | sin validez fiscal real sin Facturación Electrónica (watermark "NO VÁLIDO COMO FACTURA") |
| nro_comprobante | string, nullable | autogenerado, formato observado `0001-00000003` |
| fecha_vto_cobro | date, nullable | |
| a_cobrar | decimal(14,2) | = total al momento de guardar |
| cobrado | decimal(14,2), default 0 | suma de `cobros.monto` asociados |
| creado_por_id | FK → users, nullable | **Columna nueva**: "Usuario" (quién cargó la Venta), distinto de `vendedor_id` (quién vendió) — dos filtros independientes confirmados por el informe real. Se setea con `auth()->id()` sólo en altas nuevas; Ventas anteriores a esta columna quedan con `null` (no reconstruible retroactivamente). |
| deposito_id | FK → depositos, nullable, `restrictOnDelete()` | **Columna nueva (spec 049)**: depósito del que descuenta stock esta Venta. Obligatorio a nivel de formulario para altas nuevas (Select2, catálogo de depósitos activos); nullable en DB porque Ventas previas a spec 049 quedan sin backfill (`deposito_id = null`, cambio sólo hacia adelante). Reemplaza a `Deposito::porDefecto()` como fuente del depósito para el movimiento de stock en Ventas de origen manual — Ventas de Mercado Libre/Tiendanube siguen resolviendo su depósito por `ml_configuracion`/`tn_configuracion` (sin cambios, spec 049 no las toca). Divergencia deliberada sin confirmación contra capturas reales de Contagram — ver `specs/049-deposito-ventas-compras/spec.md` § Assumptions. |
| descuento_general_tipo | enum(`porcentaje`,`monto`), NOT NULL default `porcentaje` | **Columnas nuevas (spec 060, pendiente de implementar)**: junto con `descuento_general_monto` (decimal(12,2), nullable), permiten cargar el descuento general del comprobante como monto fijo en pesos en vez de sólo porcentaje, vía un botón inline %/$ en el formulario. Cuando el tipo es `monto`, `descuento_general_pct` queda `null` y viceversa. `CalculoComprobante::calcular()` convierte internamente el monto a un porcentaje efectivo (`valor / subtotal_sin_descuento * 100`) antes de aplicar el mismo prorrateo proporcional a neto e IVA ya vigente (spec 044) — mismo criterio para `presupuestos` y `compras`, que comparten el servicio. Ver `specs/060-toggle-descuento-general/`. |
| descuento_general_monto | decimal(12,2), nullable | Ver fila anterior. |

`ventas.venta_items` y `ventas.conceptos` son análogos a `presupuesto_items`/`presupuesto_conceptos`
(mismas columnas, FK `venta_id`) — no se listan de nuevo.

### `cobros` ("Cobranzas")
id, venta_id (FK → ventas, cascade), fecha (date), cuenta_tesoreria_id (FK → `cuentas_tesoreria` —
**tabla dependiente, ver nota abajo**), monto (decimal(14,2)), nota (text, nullable).

### `aplicaciones_credito` (spec 072 — **divergencia deliberada respecto de Contagram**)
Imputa el saldo a favor de un comprobante (el que tiene la Nota de Crédito) a otro comprobante del
mismo cliente/proveedor. **No es dinero**: no tiene cuenta de tesorería y NO genera
`movimientos_tesoreria` — aplicar crédito es una transferencia entre documentos, no un ingreso.

id, origen_type + origen_id (morph → `App\Models\Venta` | `App\Models\Compra`, comprobante que cede
el crédito), destino_type + destino_id (morph, mismo tipo que el origen, comprobante que lo recibe),
nota_credito_debito_id (FK → `notas_credito_debito`, nullable — la NC que justifica el crédito;
la más antigua con remanente si el origen tuviera varias), monto (decimal(14,2), > 0), fecha (date),
nota (text, nullable), usuario_id (FK → usuarios, nullable, auditoría), timestamps, **deleted_at
(soft delete** — obligatorio por constitución III, impacto contable).

Índices: `(origen_type, origen_id)`, `(destino_type, destino_id)`, `nota_credito_debito_id`.

Reglas: `origen_type = destino_type`; `origen_id ≠ destino_id`; mismo cliente (o proveedor) en ambos
extremos; el origen debe tener al menos una NC vigente; `monto ≤ crédito disponible del origen` y
`monto ≤ saldo pendiente del destino`.

**Efecto en los saldos derivados** (siguen sin almacenarse):

```
aCobrar = total + ND − NC − cobrado − credito_recibido + credito_cedido
aPagar  = total + ND − NC − pagado  − credito_recibido + credito_cedido
```

`credito_recibido` = Σ aplicaciones donde el comprobante es destino; `credito_cedido` = Σ donde es
origen. **El término `credito_cedido` es imprescindible**: sin él el saldo a favor quedaría entero en
el comprobante de origen y además saldaría el destino (doble conteo), y el cliente aparecería con
$30.771,29 a favor en vez de $3.465,29 en el caso real que originó la spec.

**Crédito disponible de un comprobante** = `max(0, −(total + ND − NC − cobrado)) − credito_cedido`, y
sólo si tiene alguna NC vigente. Una NC sobre un comprobante impago da crédito disponible **cero**.

Todos los puntos que replican la fórmula de saldo en SQL deben incluir los dos términos nuevos:
`VentaController::sqlACobrar()` y `kpis()`, el filtro `estado_pago` y KPIs de `CompraController`,
`Tesoreria\CuentaCorriente` (`porCliente()`, `aging()`, `saldosPorEntidad()`), las dos
`Informes\CuentaCorriente*Controller::queryMovimientos()` e `Informes\VentasInformeQuery` /
`ComprasInformeQuery`.

**No se escriben a mano**: los dos términos salen de `App\Services\Ingresos\SqlCredito::terminos($tabla)`,
que además resuelve el escapado del tipo del morph (en MySQL la barra invertida de `App\Models\Venta`
es carácter de escape dentro de un literal, así que interpolarlo pelado nunca matchea). Cualquier
lugar nuevo que replique la fórmula tiene que tomarlos de ahí.

**Ojo con `ONLY_FULL_GROUP_BY`** (default de MySQL): estos términos son subselects correlacionados,
así que un `GROUP BY` que los use en un `SUM()` da error 1055. Hay que agrupar sobre una subconsulta
que ya calculó el saldo por comprobante, como hace `saldosPorEntidad()`. SQLite lo acepta, o sea que
la suite de tests **no** detecta este error.

### `notas_credito_debito`
Confirmado contra `help.contagram.com/es/articles/1319041` (24/07/2026) que el alta es un wizard de 2
pasos — el informe con capturas sólo había relevado el paso 1.

id, venta_id (FK → ventas, **nullable** — "Documento que Ajusta"), compra_id (FK → `compras`,
**nullable**, agregado en spec 009 — exactamente uno de `venta_id`/`compra_id` debe estar seteado),
tipo (enum `credito`,`debito`), afecta_stock (boolean, default false), mes_imputacion (date, NOT
NULL, agregado en spec 045 — se persiste con día fijado a `01`, representa "mes/año" de imputación
para el informe al Contador, independiente de `fecha_emision`; precargado por defecto con el
mes/año de `fecha_emision` al crear la nota, editable; **consumido por la spec 077**, que lo usa para
ubicar la nota en el período del Libro IVA — en **ambas** pestañas, Ventas y Compras), fecha_emision (date), monto
(decimal(14,2)), tipo_comprobante (string, igual al del comprobante original), descripcion (text,
nullable — obligatoria si no afecta stock), impuestos (json, nullable — array de `{tipo, concepto,
monto}`, mismo patrón que `presupuesto_conceptos`/`venta_conceptos`/`compra_conceptos` pero embebido
en JSON en vez de tabla propia; **conectado a la UI en spec 061** — hasta esa spec la columna existía
sin usarse, los bloques "+Percepciones/+Impuestos Internos/+Intereses" de la página completa de NC/ND
eran decorativos). `nota_credito_debito_items` (pivot `producto_id`
+ `cantidad` + `precio`, con flag `origen` = `venta_original`/`nuevo`) sólo si `afecta_stock = true`.

> **Columnas nuevas (spec 060, pendiente de implementar)**: `descuento_general_tipo`
> (enum(`porcentaje`,`monto`) NOT NULL default `porcentaje`), `descuento_general_pct` (decimal(5,2),
> nullable — no existía en esta tabla hasta ahora) y `descuento_general_monto` (decimal(12,2),
> nullable). A diferencia de `ventas`/`presupuestos`/`compras`, NC/ND no pasa por
> `CalculoComprobante` — el `monto` final sigue calculándose client-side
> (`resources/js/notas-credito-debito.js::recalcular()`) y se persiste tal cual; estas columnas nuevas
> sólo agregan la persistencia del desglose (tipo + valor) para poder reabrir el formulario mostrando
> el mismo modo/valor con el que se cargó, algo que hoy no ocurre (el descuento general de NC/ND es
> hoy sólo un input visual sin persistencia). Ver `specs/060-toggle-descuento-general/research.md` R4.

> **Ampliación spec 057 (11/08/2026, Edición/Eliminación de NC/ND)**: agrega `nro_comprobante`
> (string, nullable — número propio de la nota; `tipo_comprobante` ya existía y pasa a ser
> editable junto con éste) y `nota_ajustada_id` (FK auto-referencial nullable →
> `notas_credito_debito.id` — "Documento que Ajusta" cuando apunta a otra NC/ND en vez de al
> comprobante original; regla de negocio, no constraint de DB: exactamente uno de `venta_id`/
> `compra_id`/`nota_ajustada_id` seteado, y sólo puede apuntar a una nota de "nivel 0", sin su
> propio `nota_ajustada_id` — limita el encadenamiento a 1 nivel). `nota_credito_debito_items`
> gana `descuento_pct` (decimal 5,2, nullable, default 0) e `iva_pct` (decimal 5,2, nullable) y
> deja de estar condicionada a `afecta_stock = true`: se llena siempre que el usuario cargue
> ítems en el paso 2 del wizard, reproduciendo el desglose Cant./Precio Unit./%Bonif./Subtotal/
> Alícuota IVA/Subtotal c/IVA del PDF real de Contagram. Detalle completo en
> `specs/057-editar-eliminar-ncnd/data-model.md`.

> **Ampliación spec 062 (fidelidad estructural de la tabla NC/ND)**: agrega `nota_interna` (text,
> nullable — texto libre, mismo patrón que `nota_interna` en `ventas`/`compras`; no participa en el
> cálculo de montos ni en la lógica fiscal). Además, la tabla "Notas de Crédito y Débito" del detalle
> de Venta/Compra pasa a mostrar columnas derivadas de datos ya existentes (sin cambio de esquema):
> Estado real (de `comprobanteFiscal` de la propia nota, no un menú), Comprobante/N° Comprobante (de
> `tipo_comprobante`/`nro_comprobante`/`comprobanteFiscal`) y Documento que Ajusta (prioridad:
> `nota_ajustada_id` si existe, si no el `comprobanteFiscal` de la Venta/Compra original, si no
> vacío). Ver `specs/062-tabla-ncnd-fidelidad/data-model.md`.

### `retenciones` (Cobros y Pagos — **implementado en spec 009**)
Confirmado contra `help.contagram.com/es/articles/1319082` (24/07/2026): el campo vive dentro de la
sección de Cobranzas del Detalle de Venta (no en el modal simple de cobro), vía botón "Agregar
Retención". Simétrico en Compras/Pagos — spec 009 (Egresos) es la que finalmente puebla esta tabla,
vía el modal "Nueva Retención" del Detalle de Compra; el lado de Ventas/Cobros queda con la columna
lista pero sin flujo propio que la use todavía.

id, cobro_id (FK → cobros, nullable), pago_id (FK → `pagos`, nullable — tabla de Egresos/Compras,
documentada en §7), fecha (date), monto (decimal(14,2)), tipo_retencion (string — catálogo confirmado
en el relevamiento de Egresos, §7: Ganancias, IVA, Seguridad Social, Sellos, Ingresos Brutos por
jurisdicción), nro_comprobante (string, nullable), descripcion (text, nullable). Exactamente uno de
`cobro_id`/`pago_id` debe estar seteado.

### `remitos`
id, venta_id (FK → ventas, **nullable**), compra_id (FK → `compras`, **nullable**, agregado en spec
009 — exactamente uno de los dos), fecha (date), nro_remito (string, nullable).

**Ampliado por spec 064** con el relevamiento que faltaba (`docs/Contagram-Informe-Remitos.md` +
12 capturas, 12/08/2026): transportista_id (FK → `transportistas`, nullable), domicilio_entrega
(string, nullable — precargado del cliente en Ventas y del depósito que recibe en Compras, editable
sin alterar la ficha de origen), nota (text, nullable), monto_asegurado (decimal(14,2), **nullable**
— null = interruptor apagado; dato interno que **no se imprime** en el documento), tipo (string(1),
default `X` — letra informativa, el remito **no es fiscal**).

**`total_bultos` NO se persiste**: se deriva de la suma de `remito_items.cantidad`.

> ⚠️ **Bug de esquema corregido en spec 064**: `venta_id` estaba en la base como **NOT NULL** pese a
> documentarse acá como nullable, de modo que `CompraController::remitoStore()` —que setea sólo
> `compra_id`— **fallaba: era imposible crear un remito desde una Compra**. Es el mismo patrón del bug
> ya registrado para `notas_credito_debito.venta_id` (ver `docs/importacion_casos_a_revisar.md` §0).
> Nunca se detectó porque el camino de Compras jamás se ejercitó.

### `remito_items`
**Nueva en spec 064.** Qué se entrega en cada remito: id, remito_id (FK → `remitos`, cascade on
delete), producto_id (FK → `productos`, **nullable** — null para ítems libres sin producto),
codigo (string, nullable — snapshot al momento de remitir), descripcion (string — snapshot del nombre,
para que el remito siga imprimiéndose si el producto se da de baja), observacion (string, nullable —
texto libre por línea), cantidad (decimal(14,3), > 0).

**Sin precio, sin IVA, sin subtotal**: el remito es logístico, no fiscal.

### `transportistas`
**Nueva en spec 064.** id, nombre. Único atributo, por fidelidad al alta rápida de Contagram (no pide
CUIT, patente ni contacto). Reutilizable entre remitos; un nombre existente se reutiliza en vez de
duplicarse. Sin pantalla de ABM: sólo alta al vuelo desde el formulario del remito.

### `otros_ingresos`
id, fecha (date), monto (decimal(14,2)), categoria_id (FK → categorias, tipo=`ingreso` — el enum de
`categorias.tipo` ya contempla este valor, ver §2), cuenta_tesoreria_id (FK → `cuentas_tesoreria`,
nullable — dependencia, ver nota abajo), descripcion (text, nullable), pendiente (boolean, default
false — checkbox "Marcar como pendiente"), usuario_id (FK → usuarios, nullable).

### `abonos`
Mismo bloque Cliente/Categoría/Lista de Precios/Items que Presupuestos, más:

| Campo | Tipo | Notas |
|---|---|---|
| frecuencia | enum(`mensual`, …) | sólo `mensual` habilitado en el plan de la cuenta relevada; el resto queda modelado pero restringido en UI/negocio |
| dia_generacion | tinyint | día del mes en que se genera la próxima venta |
| finaliza | enum(`nunca`,`despues_de`,`fecha`) | default `nunca`; `despues_de`/`fecha` deshabilitados en el plan relevado, igual patrón de restricción que campos personalizados de Cliente/Proveedor |
| finaliza_repeticiones | int, nullable | usado si `finaliza = despues_de` |
| finaliza_fecha | date, nullable | usado si `finaliza = fecha` |
| estado | enum(`activo`,`inactivo`) | default `activo` |
| total | decimal(14,2) | |

`abono_items` (análogo a `presupuesto_items`, FK `abono_id`). Cada ejecución de un Abono genera una
fila en `ventas` con `abono_id` (FK, nullable) para trazar "Ventas Creadas"/"Venta Previa"/"Próxima
Venta" del listado de Abonos.

> **Dependencia `cuentas_tesoreria` — resuelta (spec 007):** `cobros.cuenta_tesoreria_id` y
> `otros_ingresos.cuenta_tesoreria_id` referencian la tabla `cuentas_tesoreria` implementada en la
> sección 6 de abajo. El punto de enganche para que Ingresos registre sus cobros como movimientos de
> tesorería es `App\Services\Tesoreria\Tesoreria::registrarMovimiento()`.

---

## 6. Tesorería: cuentas y movimientos (implementado — spec 007)

> **Estado:** implementado. Fuente: `docs/informe_contagram_tesoreria.md` (capturas 144-162) y
> `specs/007-tesoreria-cuentas-movimientos/data-model.md`. El saldo de una cuenta **nunca** se
> almacena — es siempre derivado por agregación SQL sobre `movimientos_tesoreria`.

### `cuentas_tesoreria`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | ej. "Caja del Local", "Banco Galicia" |
| tipo | enum(`a_cobrar`,`a_pagar`,`banco`,`efectivo`) | fijo tras crear. Determina el bloque de Saldos |
| visible | boolean, default true | oculta = no aparece en Saldos ni selectores, sí en config y su ficha |
| es_sistema | boolean, default false | true para Cheque de Terceros / Cheque Propio: no editable ni eliminable |
| saldo_inicial | decimal(14,2), default 0 | monto de apertura; se materializa como movimiento `saldo_inicial` |
| saldo_inicial_fecha | date, nullable | fecha de apertura del saldo inicial |
| orden | smallint, nullable | orden de despliegue dentro de su bloque/tipo |

Índices: `index(tipo)`, `index(visible)`.

### `movimientos_tesoreria`
Ledger. Una fila = un asiento en una cuenta. El signo de `monto` da ingreso/egreso.

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| cuenta_tesoreria_id | FK → cuentas_tesoreria, cascade | |
| fecha | date | |
| tipo | enum(`saldo_inicial`,`movimiento_entre_cuentas`,`cobro`,`pago`,`gasto`) | "Operación" del ledger |
| monto | decimal(14,2) | con signo: positivo = ingreso, negativo = egreso |
| detalle | string, nullable | contraparte de la transferencia / cliente / proveedor / subcategoría |
| nro_comprobante | string, nullable | "N° Factura"; sólo dato, sin validez fiscal |
| observacion | text, nullable | |
| transferencia_id | string (uuid), nullable | agrupa las 2 patas de una misma transferencia |
| origen_type / origen_id | nullable (morphTo) | documento que originó el movimiento (Cobro/Pago/Gasto/OtroIngreso), null en nativos |
| usuario_id | FK → users, nullable | |
| deleted_at | timestamp, nullable | SoftDeletes — movimientos con origen documental se soft-deletean; los nativos (Saldo Inicial, Movimiento entre Cuentas) se borran físicamente desde la ficha |

Índices: `index(cuenta_tesoreria_id, fecha, id)`, `index(tipo)`, `index(transferencia_id)`,
`index(['origen_type','origen_id'])`.

**Reglas de dominio**: saldo de una cuenta a fecha F = `SUM(monto) WHERE cuenta = ? AND fecha <= F`;
balance corrido de la ficha = `SUM(monto) OVER (PARTITION BY cuenta_tesoreria_id ORDER BY fecha, id)`;
una transferencia = 2 filas vinculadas por `transferencia_id`, creadas en una `DB::transaction()`
(partida doble atómica); `CuentaTesoreria::tieneOperaciones()` (existe movimiento con `tipo !=
saldo_inicial`) bloquea el borrado físico de la cuenta.

Servicio de dominio: `App\Services\Tesoreria\Tesoreria` (`registrarSaldoInicial()`, `transferir()`,
`registrarMovimiento()` — API pública para que Ingresos/Egresos registren cobros/pagos sin acoplarse
a Tesorería, `saldos()`, `flujo()`).

---

## 7. Egresos: compras y gastos

> **Estado: implementado** (spec 009-egresos-compras-gastos). Compras es el espejo de
> `ventas`/`presupuestos` (§5): mismo patrón de items/conceptos, con Proveedor en lugar de Cliente.
> Gastos es un modelo aparte, mucho más simple. **Nota de implementación:** `pagado`/`a_pagar` y el
> estado (Pagado/A Pagar) **no son columnas persistidas** — se derivan siempre de `Σ pagos` y
> `Σ notas_credito_debito` (mismo criterio "derivar, no guardar" que `ventas.a_cobrar`, ver §5 y
> Clarifications de la spec). **Corrección (11/08/2026, spec 056)**: Compras **sí** usa `etiquetas`
> — confirmado contra la captura real (filtro "Etiqueta" en el panel de Filtros de Compras), vía la
> misma relación polimórfica `Compra::etiquetas(): MorphToMany` (tabla pivote `etiquetables`, ya
> genérica, sin cambios de esquema) que usa `Venta::etiquetas()`.

### `compras`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| proveedor_id | FK → proveedores | |
| categoria_id | FK → categorias (tipo=`compra`), nullable | autocompletada desde `proveedores.categoria_id` al elegir el proveedor |
| tipo_comprobante | string, nullable | "Tipo" del formulario — sin validez fiscal real sin Facturación Electrónica, mismo patrón que `ventas.tipo_comprobante` |
| nro_comprobante | string, nullable | numeración asociada al Tipo. **Actualización (spec 049, 06/08/2026)**: antes se autogeneraba siempre server-side (`Compra::siguienteNroComprobante()`, correlativo interno, punto de venta fijo "0001"), sin input en el formulario. Ahora es un campo editable y obligatorio en "Nueva Compra"/"Editar Compra"; `siguienteNroComprobante()` sigue existiendo pero sólo como valor de precarga sugerido — el valor final que se persiste es el que confirma o edita el usuario. |
| fecha_emision | date | |
| fecha_vto_pago | date, nullable | "Vto. del Pago" |
| servicio_desde, servicio_hasta | date, nullable | |
| mes_imputacion_iva | date, nullable | campo **"Contador"**, exclusivo de Compras (sin equivalente en Ventas) — mes de imputación en el IVA Compras, independiente de `fecha_emision`. **Consumidor (spec 077, 24/08/2026)**: el Informe "Información para tu Contador" resuelve con esta columna el período del Libro IVA Compras, con respaldo en `fecha_emision` cuando está en NULL. Hasta la 077 el campo se le pedía al usuario sin que ninguna pantalla lo leyera. |
| subtotal_sin_descuento, descuento, subtotal_con_descuento, total | decimal(14,2) | mismo cálculo que `ventas`/`presupuestos`; `total` es un snapshot congelado |
| descuento_general_pct | decimal(5,2), nullable | **Columna nueva (07/08/2026, fix de bug)**: el % de descuento general ingresado en el formulario ya se aplicaba correctamente al cálculo de `descuento`/`total`, pero no se persistía (a diferencia de `ventas`/`presupuestos`, que sí tienen esta columna) — por eso el modal "Ver" no podía mostrarlo y el form de edición no lo precargaba. Mismo campo/semántica que `ventas.descuento_general_pct`. |
| descuento_general_tipo, descuento_general_monto | enum(`porcentaje`,`monto`) NOT NULL default `porcentaje`; decimal(12,2) nullable | **Columnas nuevas (spec 060, pendiente de implementar)** — mismo patrón que `ventas.descuento_general_tipo` (ver esa fila para el detalle). |
| nota_interna | text, nullable | |
| deposito_id | FK → depositos, nullable, `restrictOnDelete()` | **Columna nueva (spec 049)**: depósito al que suma stock esta Compra. Mismo criterio que `ventas.deposito_id` — obligatorio en el formulario, nullable en DB por retrocompatibilidad, reemplaza a `Deposito::porDefecto()` como fuente para `StockDeCompra`. |
| creado_por_id | FK → users, nullable, `nullOnDelete()` | **Columna nueva (spec 056)**: usuario que creó la Compra, seteada únicamente en `store()` (`auth()->id()`); sin backfill — Compras existentes quedan con `NULL`. Habilita el filtro "Usuario" del listado. Mismo criterio que `ventas.creado_por_id`. |
| deleted_at | timestamp, nullable | SoftDeletes (Principio III) |

`compras.venta_id`/`presupuesto_id` no aplican — Compras no deriva de otro documento. `pagado`
(`Σ pagos.monto`) y `a_pagar` (`total + Σ ND − Σ NC − pagado`) se calculan en `Compra::pagado()`/
`Compra::aPagar()`, nunca se guardan.

### `compra_items`
id, compra_id (FK, cascade), producto_id (FK → productos, nullable — ítem libre), descripcion,
cantidad (decimal), precio_unitario (decimal), descuento_pct (decimal, nullable), **iva_pct (string(12),
nullable, SIN default** — a diferencia de `venta_items`/`presupuesto_items` que autocompletan `21`, acá
el campo queda en "Elegir" hasta que el usuario lo setea; mientras esté null el panel de totales de la
ficha muestra "Importe Neto No Gravado" en vez de "Importe Neto Gravado"), subtotal (decimal),
subtotal_con_iva (decimal, nullable mientras `iva_pct` no esté seteado).

### `compra_conceptos` (Percepciones / Impuestos Internos / Intereses)
Idéntico a `presupuesto_conceptos`/`venta_conceptos` (§5): id, compra_id (FK, cascade), tipo (enum
`percepcion`,`impuesto_interno`,`interes`), concepto (string), monto (decimal).

### `pagos`
id, compra_id (FK → compras, cascade), fecha (date), cuenta_tesoreria_id (FK → `cuentas_tesoreria`,
**not nullable** — "Medio de Pago" siempre requerido), monto (decimal(14,2)), nota (text, nullable),
nro_comprobante (string, nullable), deleted_at (SoftDeletes). Resuelve la FK `retenciones.pago_id` que
quedó pendiente en §5: `retenciones` (tabla ya documentada en §5) puede ahora poblarse también del lado
de Compras, vía botón "Agregar Retención" en la ficha de Compra (exactamente uno de `cobro_id`/`pago_id`
seteado).

Al confirmar un pago, `App\Services\Egresos\Pagos::registrarPago()` llama a
`App\Services\Tesoreria\Tesoreria::registrarMovimiento()` (mismo punto de enganche que Cobros/Otros
Ingresos, §5-6, vía `Services\Ingresos\Cobranzas`) y genera el movimiento de tesorería tipo `pago`
(egreso, monto negativo) en la cuenta elegida; el estado "Pagado"/"A Pagar" de la compra se deriva en
el momento (`pagado >= a_pagar`), nunca se persiste.

### `gastos`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| fecha | date | default hoy en el formulario |
| monto | decimal(14,2) | |
| categoria_id | FK → categorias (tipo=`gasto`), nullable | jerárquico vía `categoria_padre_id` (ya soportado por `categorias`, §2) — taxonomía propia de Gastos (Empleados, Impuestos, Marketing, Oficina, Otros Gastos, Servicios Profesionales, con subcategorías), **independiente** del árbol tipo=`compra` de Proveedores aunque comparten la misma tabla genérica |
| cuenta_tesoreria_id | FK → cuentas_tesoreria, nullable | "Elija un medio de pago" — mismo catálogo que Compras |
| descripcion | text, nullable | |
| pendiente | boolean, default false | checkbox "Marcar como pendiente" — determina el estado "Pendiente" vs. "Pagado" (no hay pagos parciales como en Compras) |
| usuario_id | FK → usuarios, nullable | |

Gastos no tiene tabla de ítems ni de conceptos: es un registro atómico sin documento fiscal asociado,
sin ficha de detalle propia (el modal de alta se reabre en modo edición al hacer clic en el Id). Al
confirmarse, genera un movimiento de tesorería tipo `gasto` (egreso) vía el mismo servicio de
Tesorería.

---

## 8. Configuración & Ajustes: funciones avanzadas e integración Mercado Libre (spec 011)

Ver `docs/documentacion_principal_crm.md` §5.1 y §5.2 (esta última documenta la **divergencia
deliberada respecto de Contagram**). Detalle completo del esquema en
`specs/011-mercadolibre-conexion-oauth/data-model.md`.

### `funciones_avanzadas`

Una fila por función activable del CRM (las 10 relevadas de Contagram, sembradas por seeder idempotente).

| Campo | Tipo | Notas |
|---|---|---|
| clave | string(50), unique | `mercadolibre`, `depositos`, `abonos`, … — identificador estable en código |
| nombre | string(100) | Texto de la tarjeta |
| descripcion | string(255) | Descripción de una línea |
| icono | string(50), nullable | Clase de ícono del template |
| orden | smallint | 1..10, orden relevado de Contagram |
| disponible | boolean, default false | Si la función está construida en este CRM. Las no construidas se listan pero no se pueden activar (validado en servidor) |
| activa | boolean, default false | Estado del toggle |
| ruta_configuracion | string(150), nullable | Ruta a la que enlaza la tarjeta si tiene configuración propia |
| actualizada_por | FK → usuarios, nullable | Quién cambió el estado |
| actualizada_en | timestamp, nullable | Cuándo |

### `ml_configuracion`

Registro **único** (single-tenant) con los datos de la aplicación creada en el DevCenter de Mercado Libre.

| Campo | Tipo | Notas |
|---|---|---|
| client_id | string(100), nullable | App ID. No es secreto |
| client_secret | text, nullable | **Cifrado** (cast `encrypted`). Nunca se devuelve a la interfaz |
| site_id | string(5), default `MLA` | Sitio de operación |
| modo_solo_lectura | boolean, default false | Kill-switch: bloquea toda escritura hacia Mercado Libre |
| actualizada_por | FK → usuarios, nullable | |

Cambiar `client_id`/`client_secret` con una cuenta vinculada invalida esa vinculación (estado `caida`).

### `ml_cuentas`

La cuenta de Mercado Libre vinculada (single-tenant: una sola activa).

| Campo | Tipo | Notas |
|---|---|---|
| ml_user_id | bigint, unique | Identificador del usuario en Mercado Libre |
| nickname / email / tipo_cuenta | string, nullable | Datos traídos de `GET /users/me` al vincular |
| site_id | string(5) | Debe coincidir con el configurado, si no se rechaza la vinculación |
| access_token | text, nullable | **Cifrado**, `$hidden`. Vigencia 6 horas |
| refresh_token | text, nullable | **Cifrado**, `$hidden`. **De un solo uso** — cada renovación devuelve uno nuevo que reemplaza al anterior |
| token_expira_en | timestamp, nullable | Renovación anticipada 10 min antes |
| estado | string(20) | `desconectada` / `conectada` / `pendiente_confirmacion` / `caida` (+ `no_configurada`, derivado de `ml_configuracion`) |
| pendiente_expira_en | timestamp, nullable | Sólo en estado `pendiente_confirmacion`: vencimiento (+15 min) de una autorización retenida a la espera de que el usuario confirme el reemplazo de cuenta |
| vinculada_en / ultimo_refresh_en | timestamp, nullable | |
| ultimo_error | string(255), nullable | Motivo de la caída, para mostrar en el panel |
| vinculada_por | FK → usuarios, nullable | |

> **Regla crítica de la integración**: dos renovaciones concurrentes rompen la cadena del
> `refresh_token` y obligan a re-autorizar manualmente. La renovación se ejecuta bajo exclusión mutua
> (lock atómico con driver de base de datos, para que funcione también en hosting compartido).

> **Reemplazo de cuenta (FR-022)**: autorizar con una cuenta de Mercado Libre distinta de la ya
> vinculada NO la reemplaza directamente — queda en `pendiente_confirmacion` (tokens ya canjeados,
> cifrados igual que una cuenta activa) mientras la cuenta `conectada` sigue operando con normalidad.
> La confirmación del usuario activa la pendiente y desconecta la anterior en una única transacción
> (nunca dos filas `conectada` a la vez); si vence sin confirmar, se descarta junto con sus tokens.

### `ml_solicitudes_vinculacion`

Protección antifalsificación del retorno de OAuth (parámetro `state`). Es tabla y no sesión porque el
usuario vuelve desde un dominio externo y la cookie puede no acompañar el retorno.

| Campo | Tipo | Notas |
|---|---|---|
| state | string(64), unique | Token aleatorio de 40 caracteres |
| estado | string(20) | `pendiente` / `consumida` / `vencida` — **de un solo uso** |
| expira_en | timestamp | Emisión + 10 minutos |
| consumida_en | timestamp, nullable | Se marca **antes** de canjear, para que un retorno repetido no dispare un segundo canje |
| iniciada_por | FK → usuarios | |
| ip | string(45), nullable | Auditoría del intento |

### `ml_operaciones_log`

Historial de interacciones con la API, para diagnóstico. **Nunca contiene credenciales** (el saneado es
previo a persistir, no posterior).

| Campo | Tipo | Notas |
|---|---|---|
| operacion | string(100) | `vincular_cuenta`, `renovar_token`, `probar_conexion`, … |
| metodo / endpoint | string | Verbo HTTP y ruta, sin parámetros sensibles |
| sentido | string(10) | `lectura` / `escritura` — determina si aplica el kill-switch |
| resultado | string(20) | `exito` / `error` / `bloqueada` |
| codigo_http | smallint, nullable | Nulo cuando fue bloqueada (no hubo petición) |
| duracion_ms | int, nullable | |
| mensaje_error | text, nullable | Saneado |
| payload_bloqueado | text, nullable | Sólo si `resultado = bloqueada`: qué se habría enviado |
| usuario_id | FK → usuarios, nullable | Nulo si fue automática |
| created_at | timestamp | Sin `updated_at`: es un registro inmutable de auditoría |

Retención: 30 días o 5.000 registros, depurada de forma oportunista (sin depender de tarea programada,
por la restricción de portabilidad a hosting compartido).

---

## 8.bis Facturación Electrónica (ARCA/AFIP) — spec 034, implementada

Ver `specs/034-facturacion-electronica-arca/data-model.md` para el detalle completo de campos.
Resumen de entidades:

- **`puntos_venta`**: número asignado por ARCA, tipo de Web Service, flag `por_defecto` (exactamente
  uno activo), activo/inactivo.
- **`certificados_fiscales`**: CUIT del negocio, ambiente (`homologacion`/`produccion`), rutas al
  `.crt`/`.key` cifrados en disco (`storage/app/arca/`, fuera del webroot y de la DB), fechas de
  emisión/vencimiento, `SoftDeletes`.
- **`comprobantes_fiscales`**: relación polimórfica (`comprobantable_type`/`id`) hacia `ventas`,
  `compras` o `notas_credito_debito`; tipo de comprobante, número real asignado por ARCA, CAE,
  vencimiento de CAE, estado (`pendiente`/`aprobado`/`rechazado` — nunca `aprobado` sin CAE),
  `comprobante_ajustado_id` (auto-referencia para NC/ND), respuesta cruda de ARCA, `SoftDeletes`
  (nunca se borra físicamente un comprobante fiscal). `punto_venta_id` es **nullable**: en Compras el
  comprobante lo emite el Proveedor con su propio Punto de Venta, ajeno a la tabla `puntos_venta` (que
  sólo modela los WS propios del negocio) — sólo Ventas/NC-ND de Ventas lo completan.
- **`arca_logs_auditoria`**: log append-only (sólo `created_at`) de cada llamada a WSAA/WSFEv1
  (usuario, comprobante relacionado, operación, resultado, payloads).

`ventas` y `compras` ganan una relación `morphOne` hacia `comprobantes_fiscales`; sus columnas
`tipo_comprobante`/`nro_comprobante` existentes quedan como fallback sin validez fiscal (spec 008/030)
cuando no hay `ComprobanteFiscal` aprobado asociado — no se duplican columnas de CAE ahí.

> **Cardinalidad real (corrección 14/08/2026): es 1→N, no 1→1.** Cada intento contra ARCA persiste su
> propia fila en `comprobantes_fiscales` y **los rechazos se conservan** (`numero`/`cae`/
> `cae_vencimiento` en NULL + `motivo_rechazo`), así que una Venta reintentada con éxito termina con
> un registro rechazado **y** uno aprobado. Por eso el `morphOne` `comprobanteFiscal()` lleva un orden
> explícito (`CASE WHEN estado = 'aprobado' THEN 0 ELSE 1 END`, luego `id DESC`) para devolver el
> **vigente** — sin ese orden devuelve el rechazo más viejo y el sistema se comporta como si la Venta
> no tuviera CAE (ver el incidente de la Venta 24447 en `documentacion_principal_crm.md`). El
> historial completo se consulta con la relación `comprobantesFiscales()` (`morphMany`), que es la que
> deben usar los filtros y cualquier `whereHas`.

> **Nota (spec 037, 03/08/2026)**: la consulta al Padrón de ARCA (`ws_sr_padron_a13`) reutiliza el
> `CertificadoFiscal` activo y `ClienteWsaa` de este bloque para autenticarse (mismo Ticket de
> Acceso, distinto nombre de servicio). No agrega tablas nuevas: el resultado de cada consulta es
> transitorio y sólo completa columnas ya existentes de `clientes` (`razon_social`,
> `domicilio_fiscal`, `localidad_fiscal`, `condicion_iva_id`) cuando no estaban cargadas. Ver
> `specs/037-padron-arca-cuit/data-model.md`.

> **Nota (spec 039, 03/08/2026)**: se agrega `datos_empresa` (fila única, single-tenant) con los
> datos fiscales del propio negocio (`razon_social`, `cuit`, `domicilio_fiscal`, `condicion_iva`,
> `ingresos_brutos` opcional, `ruta_logo`), consumida como encabezado emisor por los PDFs de Venta y
> de NC/ND. Sin relación con `comprobantes_fiscales` — es metadata de presentación, no participa del
> circuito de emisión de CAE. Ver `specs/039-cierre-facturacion-electronica/data-model.md`.

---

## 9. Tablas descartadas (pendientes de re-relevamiento)

Las siguientes tablas existieron en una versión anterior del modelo (spec 003 a 015) y fueron
eliminadas junto con su código porque el relevamiento funcional que las originó no reflejaba con
precisión el negocio real. Se documentarán de nuevo cuando se retome cada módulo:

`empresa`,
`reportes_email_config`, `importaciones` (la de Contagram, no la
implementada en spec 006), `integraciones`, `integracion_eventos`, `producto_canal`, `ml_ordenes`.

> **Nota (spec 034, 02/08/2026)**: `puntos_venta`, `certificados_fiscales` y `comprobantes_fiscales`
> de aquel modelo descartado **no** se retoman tal cual — se rediseñaron desde cero con el esquema de
> §8.bis, específico para el flujo real WSAA/WSFEv1, y salen de esta lista.
>
> **Nota (spec 011)**: las tablas `integraciones`, `integracion_eventos` y `ml_ordenes` de aquel
> modelo descartado **no** se retoman. La integración con Mercado Libre se rehizo desde cero con el
> esquema de §8, que es más específico y refleja el flujo OAuth real. `producto_canal` y `ml_ordenes`
> se rediseñarán cuando se especifique la sincronización de publicaciones y el ingreso de ventas.
>
> **Actualización (spec 012, 27/07/2026)**: `ml_ordenes` **sí se retomó**, rediseñada desde cero — ver
> §10. `producto_canal` se reemplaza por `ml_publicacion_producto`, con cardinalidad 1:1 en lugar de la
> relación por canal del modelo descartado.

---

## 10. Ventas de Mercado Libre (spec 012) y sincronización de stock (spec 013)

Extiende §8 (integración Mercado Libre, spec 011). **Ambas specs están implementadas.** La 013 no agrega
tablas: sólo columnas de estado sobre `ml_publicacion_producto` y `ml_configuracion`, señaladas más
abajo. Detalle completo, con enums y transiciones de estado, en
`specs/012-ventas-mercadolibre/data-model.md` y `specs/013-stock-mercadolibre/data-model.md`.

**Convención terminológica**: "orden" = documento sincronizado desde Mercado Libre; "Venta" = documento
del CRM (§5).

### `ml_ordenes`
Órdenes sincronizadas. `ml_order_id` (string, **unique** — identidad e idempotencia), `estado_ml`
(valor crudo del proveedor), `estado_orden` (enum normalizado), `estado_conversion` (enum:
`pendiente_pago`, `lista`, `requiere_atencion`, `convertida`, `cancelada`), `motivo` (enum del bloqueo)
+ `motivo_detalle`, `fecha_creada`, `fecha_cerrada` (se usa como `fecha_emision` de la Venta), `total`,
`moneda`, datos del comprador (`comprador_ml_id`, `comprador_apodo` — se matchea contra
`clientes.apodo_ml`, `comprador_nombre`, `comprador_doc_tipo`, `comprador_doc_numero`,
`comprador_condicion_iva`), `es_prueba`, `venta_id` (FK → `ventas`, nullable, **unique**),
`creacion_automatica`, `convertida_en`, `convertida_por`, `payload` (json, sin datos sensibles),
`sincronizada_en`. **Sin soft delete ni purga** — es respaldo de documentos contables.

> **Conversión manual obligatoria para estados excepcionales (spec 066)**. Cuatro columnas nuevas:
> `en_mediacion` (boolean, default `false`) y el trío `forzada_motivo` (varchar 40, valor de
> `MotivoRequiereAtencion`) + `forzada_por_id` (FK → `users`, `nullOnDelete`) + `forzada_en` (timestamp),
> que se escriben juntos o quedan los tres en `null`.
>
> `en_mediacion` existe porque **la mediación no viene en el estado de la orden sino en el de los pagos**
> (`payments[].status = in_mediation`) y hasta ahora ese dato se perdía después de la sincronización: el
> evaluador de convertibilidad recibe el modelo, no el payload crudo, así que sin persistirlo no podía
> frenar una orden en mediación antes de convertirla.
>
> El trío `forzada_*` sostiene dos cosas: la auditoría de quién forzó la conversión y la comparación que
> evita duplicar el aviso de la spec 063 (no se avisa por el mismo motivo que la persona ya asumió). **La
> fuente de verdad de la auditoría es `ml_operaciones_log`**, no estas columnas: `forzada_por_id` es
> `nullOnDelete` y quedaría en `null` si se borra el usuario, mientras que la bitácora conserva el registro.
>
> **Sin backfill**: las órdenes existentes quedan en `en_mediacion = false` y se corrigen solas en la
> sincronización siguiente, que reevalúa toda su ventana. `estado_conversion` **no suma un sexto valor**:
> los casos excepcionales se expresan con `requiere_atencion` + motivo, o con `cancelada`, que ya existen.

> **Cancelación posterior a la conversión (spec 063, pendiente de implementar)**. Una orden ya
> convertida que después se cancela vuelve a `estado_conversion = requiere_atencion`, con uno de tres
> motivos nuevos: `orden_cancelada`, `orden_reembolso_parcial` u `orden_en_mediacion`. **El aviso vive
> en la orden, no en la Venta**: no hay entidad ni tabla nueva, y la idempotencia sale del
> `updateOrCreate` sobre `ml_order_id`. La marca **no modifica** importes, cobros, tesorería ni stock:
> una persona decide si anula la Venta o descarta el aviso.
>
> Esto obliga a dos cambios en los enums: `EstadoConversion` admite la transición
> `convertida → requiere_atencion` (hoy sólo `convertida → cancelada`), y `EstadoOrden` **deja de
> colapsar** `cancelled`, `pending_cancel` y `partially_refunded` en un único estado `Cancelada`, que
> hacía indistinguible un reembolso parcial de una cancelación firme. La **mediación** no viene en el
> estado de la orden sino en el del pago (`payments[].status = in_mediation`), así que el traductor
> debe leer también los pagos.

### `ml_orden_items`
`ml_orden_id` (FK, cascade), `ml_item_id` (publicación), `ml_variation_id` (nullable — **si viene con
valor, la orden se marca como no soportada**), `titulo`, `sku_vendedor`, `cantidad`, `precio_unitario`
(**precio FINAL con IVA incluido**), `total_linea`, `producto_id` (FK → productos, nullable — se
congela al convertir).

### `ml_publicacion_producto`
Vinculación **1:N** (un Producto puede tener varias publicaciones de ML vinculadas; spec 036,
03/08/2026 — hasta esa spec fue estrictamente 1:1), infraestructura compartida con la spec 013.
`ml_item_id` (string, **unique** — una publicación pertenece a un único producto), `producto_id` (FK →
productos, **sin índice único** desde spec 036 — un producto puede tener N publicaciones vinculadas),
`titulo_ml`, `vinculada_por`. El stock y el precio de un Producto se sincronizan hacia TODAS sus
publicaciones vinculadas, no sólo hacia una — ver `specs/036-vinculacion-multiple-ml/`.

> **Columnas nuevas (spec 013, implementada)** — estado de sincronización de stock CRM → Mercado Libre
> de este vínculo: `stock_pendiente` (bool, default false — con cambios de stock sin empujar todavía),
> `stock_sincronizado_en` (datetime, nullable — último envío exitoso), `stock_error` (string(255),
> nullable — motivo del último rechazo), `stock_error_en` (datetime, nullable). Son el **único estado
> persistente** de la sincronización de stock: no hay tabla de historial propia, los envíos se registran
> en `ml_operaciones_log` (§8). Detalle completo en `specs/013-stock-mercadolibre/data-model.md`.

> **Columnas nuevas (spec 063, pendiente de implementar)** — control de reintentos, para que un error
> permanente deje de golpear la API: `stock_intentos_fallidos` (entero, default 0 — fallas consecutivas
> con el **mismo** error), `stock_error_desde` (datetime, nullable — primera falla de la racha actual, a
> diferencia de `stock_error_en` que guarda la última), `stock_requiere_intervencion` (bool, default
> false). A los **5 intentos consecutivos** la publicación se marca y **queda excluida de las
> pendientes** hasta que alguien la reactive. Motivo: cinco publicaciones acumulaban 61 reintentos cada
> 6 horas —unas 305 llamadas fallidas— por bloqueos de Mercado Libre (`status:under_review`) que
> reintentar no puede resolver. Una sincronización exitosa limpia las tres columnas.

> **Columnas nuevas (spec 016, implementada)** — mismo patrón que las de stock, para
> precio: `precio_pendiente` (bool, default false — precio vigente en la Lista de Precios configurada sin
> confirmar como enviado, ya sea por un cambio reciente o por un envío que falló), `precio_sincronizado_en`
> (datetime, nullable), `precio_error` (string(255), nullable), `precio_error_en` (datetime, nullable).
> Detalle completo en `specs/016-lista-precio-mercadolibre/data-model.md`.

> **Columnas nuevas (spec 050, implementada)** — tipo de publicación por vínculo:
> `listing_type_id` (string(30), nullable — valor crudo informado por Mercado Libre, `gold_pro`/
> `gold_special`/etc.; `null` hasta la primera consulta exitosa), `listing_type_sincronizado_en`
> (datetime, nullable — último refresco exitoso; ante fallo de la API se conserva el último valor
> conocido en ambas columnas, no se pisan). Método `esPremium(): bool` en el modelo (único lugar que
> traduce el valor crudo a "Premium sí/no", comparando contra `gold_pro`) — usado por
> `SincronizadorPrecios` para resolver qué Lista de Precios (`lista_precio_id` vs
> `lista_precio_id_premium`) corresponde a cada publicación, evaluado por vínculo individual (no por
> producto, spec 036). Mantenido por el comando `mercadolibre:sincronizar-tipos-publicacion` (corrida
> diaria, independiente de la de stock) y completado al vincular una publicación nueva. Detalle
> completo en `specs/050-lista-precio-premium-ml/data-model.md`.

> **Columnas nuevas (spec 065, implementada)** — tipo de logística por vínculo, para distinguir las
> publicaciones **Full** (mercadería en el centro de distribución de Mercado Libre) de las de
> logística propia: `logistic_type` (string(40), nullable, indexada — valor crudo de
> `shipping.logistic_type` del body de `GET /items`: `fulfillment` = Full, `xd_drop_off` = colecta,
> `self_service` = Flex, `custom`, `not_specified`; `null` hasta la primera consulta exitosa),
> `inventory_id` (string(40), nullable, indexada — identificador de inventario de Mercado Libre) y
> `logistica_sincronizada_en` (datetime, nullable). Método `esFull(): bool` en el modelo, único lugar
> que traduce el valor crudo (compara contra `fulfillment`); **cualquier otro valor, incluido `null` y
> los tipos desconocidos, es no-Full — nunca se infiere Full**. Mantenidas por el mismo multiget de
> `SincronizadorTiposPublicacion` que ya trae `listing_type_id`, sin llamadas adicionales; ante fallo
> se conserva el último valor conocido.
>
> ⚠️ **`inventory_id` NO distingue Full**: verificado contra la cuenta real que también aparece en
> publicaciones `xd_drop_off`. Se usa **exclusivamente** como clave de deduplicación de existencias
> (publicaciones que comparten inventario computan una sola vez). El único indicador de Full es
> `logistic_type`. Detalle completo en `specs/065-ml-deposito-full/data-model.md`.

### `ml_configuracion` (columnas nuevas)
`creacion_automatica` (bool, default false), `frecuencia_sync_minutos` (default 15),
`deposito_id` (FK → depositos, nullable — null usa el depósito por defecto), `categoria_venta_id`
(FK → categorias, nullable), `dias_primera_sync` (default 30), `ultima_sync_en`,
`ultima_sync_resultado`.

> **Columnas nuevas (spec 013, implementada)**: `stock_ultima_sync_en` (datetime, nullable — última
> corrida del sincronizador de stock, comparada contra `frecuencia_sync_minutos`, reutilizado),
> `stock_ultima_sync_resultado` (string(255), nullable). No hay columna de activar/desactivar propia:
> sigue gobernado por la función avanzada "Mercado Libre" y el modo sólo lectura ya existentes.

> **Columna nueva (spec 016, implementada)**: `lista_precio_id` (FK → `listas_precio`,
> nullable, `nullOnDelete`). Lista de Precios que **gestiona los precios de las publicaciones de Mercado
> Libre vinculadas** — no clasifica nada (a diferencia de `deposito_id`/`categoria_venta_id`): cuando el
> precio de un producto vinculado cambia dentro de esta lista, el CRM sincroniza ese precio hacia la
> publicación correspondiente en el momento del cambio, sin cron. **No es fuente de precio de Ventas**:
> el precio de las líneas de las Ventas convertidas desde una orden sigue saliendo exclusivamente del
> importe pagado en Mercado Libre; esas Ventas tampoco quedan etiquetadas con esta lista. `null` = sin
> sincronización de precio; sin fallback "por defecto del CRM", a diferencia de `deposito_id` — ese
> concepto no existe para Listas de Precios en ningún otro punto del sistema. Detalle completo en
> `specs/016-lista-precio-mercadolibre/data-model.md`.

> **Columnas nuevas (spec 050, implementada)**: `lista_precio_id_premium` (FK → `listas_precio`,
> nullable, `nullOnDelete`) — segunda Lista de Precios, opcional, exclusiva para publicaciones de tipo
> **Premium** (`listing_type_id = gold_pro`); coexiste con `lista_precio_id` (la general, arriba).
> `tipo_publicacion_ultima_sync_en` (datetime, nullable) — última corrida del comando
> `mercadolibre:sincronizar-tipos-publicacion`, comparada contra un intervalo fijo de 24hs (no
> configurable, a diferencia de `frecuencia_sync_minutos`). Ver `ml_publicacion_producto.listing_type_id`
> abajo para la clasificación por vínculo. Detalle completo en
> `specs/050-lista-precio-premium-ml/data-model.md`.

> **Columna nueva (spec 065, implementada)**: `deposito_full_id` (FK → `depositos`, nullable,
> `nullOnDelete`). Depósito que representa la mercadería del negocio alojada en el **centro de
> distribución de Mercado Libre (Full)**. Convive con `deposito_id` sin reemplazarlo: `deposito_id`
> gobierna las publicaciones de logística propia, `deposito_full_id` las Full.
>
> Dos reglas que lo diferencian de `deposito_id`:
> 1. **Sin fallback**. A diferencia de `deposito_id`, no cae a `Deposito::porDefecto()` cuando está
>    vacío: si no está configurado, la funcionalidad de Full no opera y las Ventas Full caen al
>    depósito general. Caer a un depósito por defecto escribiría existencias de Mercado Libre sobre un
>    depósito físico real. Mismo criterio que `lista_precio_id` (spec 016).
> 2. **Debe ser distinto de `deposito_id`** (validación `different`). Si coincidieran, el reflejo de
>    stock ML → CRM sobrescribiría el stock real del negocio, y se abriría un ciclo de sincronización.
>
> Detalle completo en `specs/065-ml-deposito-full/data-model.md`.

> **Columna nueva (spec 020, implementada)**: `vendedor_id` (FK →
> `vendedores`, nullable, `restrictOnDelete`). "Vendedor por defecto" asignado a las Ventas que se
> crean automáticamente por sincronización de Mercado Libre — mismo patrón que `categoria_venta_id`,
> independiente del default de Tiendanube (sin fallback compartido entre integraciones). Detalle
> completo en `specs/020-vendedores/data-model.md`.

> **Mecanismo de vinculación (spec 021, implementada; corregida por spec 023) — sin columna nueva**:
> `ml_item_id`/`producto_id` se siguen creando igual que hoy, pero el `producto_id` deja de elegirse a
> mano — se resuelve automáticamente comparando un SKU contra el **`id`** (clave primaria) de `productos`,
> no contra `codigo`. Confirmado que no hace falta ningún campo nuevo: el negocio asigna a propósito ese
> mismo `id` al dar de alta los productos que hoy sólo existen en Mercado Libre. El SKU **ya no sale de
> `ml_orden_items.sku_vendedor`** (diseño original de spec 021, descartado por spec 023): sale de
> consultar el catálogo en vivo del vendedor conectado (atributo `SELLER_SKU` de cada publicación,
> recorrido completo vía el modo `scan` del buscador de Mercado Libre — sin el tope de 1000 resultados del
> paginado clásico, necesario porque el catálogo real tiene miles de publicaciones). `ml_orden_items` sigue
> existiendo y sincronizándose igual, simplemente ya no se lee para este mecanismo. Detalle completo en
> `specs/021-vinculacion-automatica-sku/data-model.md` y `specs/023-mercadolibre-catalogo-vivo/data-model.md`.

### `ventas` (columna nueva)
`origen` (enum `manual`/`presupuesto`/`mercadolibre`, default `manual`). Explicita el tercer origen;
"Creada Desde" hoy se deriva de `presupuesto_id`.

> **Cálculo clave**: los precios de Mercado Libre son **finales con IVA incluido**, así que el neto se
> obtiene como `precio_final / (1 + iva_pct/100)` usando el IVA del producto vinculado (tasa 0 para
> Exento/No Gravado). La diferencia por redondeo se absorbe en la última línea, para que el total de la
> Venta coincida **exactamente** con el monto de la orden.

> **Columnas nuevas (spec 038, implementada)**: `ml_order_id` y `tn_order_id` (string, nullable,
> **único cada una**, sin excluir Ventas soft-deleted del índice). Guardan el identificador estable del
> pedido de origen en Mercado Libre/Tiendanube respectivamente — no la PK local de `ml_ordenes`/
> `tn_ordenes`, que es la fila que se borra y regenera al resincronizar. Se completan al convertir
> (`ConversorOrdenAVenta::convertirBajoCandado()`), que además rechaza la conversión si ya existe una
> Venta (incluida una soft-deleted) con ese `ml_order_id`/`tn_order_id` — la red de seguridad que
> sobrevive al borrado+resincronización de la orden. Backfill de un solo uso para Ventas históricas:
> `php artisan ventas:backfill-referencia-pedido`. Ver `specs/038-evitar-ventas-duplicadas/data-model.md`.

---

## 11. Configuración & Ajustes: integración Tiendanube (spec 019 corrige a spec 015 — conexión)

Ver `docs/documentacion_principal_crm.md` §5.3 (documenta la **divergencia deliberada respecto de
Contagram**, y por qué spec 019 corrigió el mecanismo de conexión de spec 015: el modelo de Aplicación
personalizada requiere un plan de Tiendanube que la tienda real del cliente no tiene). Detalle completo
del esquema en `specs/019-tiendanube-conexion-mcp/data-model.md`.

Dos tablas, prefijadas `tn_` (mismo criterio que `ml_` en §8) — **spec 019 modifica `tn_configuracion`,
no crea tabla nueva**; `tn_operaciones_log` no cambia. **Sin tabla de "solicitud de vinculación
pendiente"**: el OAuth de Tiendanube ata la conexión a la cuenta que el usuario apruebe en el navegador,
no hay escenario de "reemplazo de cuenta" que gestionar (a diferencia de `ml_cuentas` en §8).

### `tn_configuracion`

Registro **único** (single-tenant) con las credenciales del cliente OAuth auto-registrado y del token
vigente, y el estado de la conexión, todo en una sola fila.

| Campo | Tipo | Notas |
|---|---|---|
| client_id | string(100), nullable | Id del cliente OAuth auto-registrado contra `admin-mcp.tiendanube.com` (Dynamic Client Registration) |
| client_secret | text, nullable | **Cifrado** (cast `encrypted`), `$hidden`. Se registra una sola vez y se reutiliza en conexiones/desconexiones posteriores |
| access_token | text, nullable | **Cifrado** (cast `encrypted`), `$hidden`. Nunca se devuelve a la interfaz. **No vence en la práctica** (~1 año, sin `refresh_token` emitido) |
| scopes_otorgados | string(255), nullable | Scopes efectivamente devueltos por el servidor al intercambiar el token |
| productos_total | unsignedInteger, nullable | Cantidad de productos informada al verificar la conexión (`list_products`) — reemplaza los datos de tienda que spec 015 mostraba, porque el servidor MCP no expone esa información |
| estado | string(20) | `no_configurada` (derivado, cubre tanto "nunca conectado" como "desconectado" — no hay datos parciales que distinguirlos) / `conectada` / `caida` — sin `desconectada` como valor propio, sin `pendiente_confirmacion` |
| ultimo_error | text, nullable | Motivo del último rechazo, para el panel cuando `estado = caida` |
| modo_solo_lectura | boolean, default false | Kill-switch, sin cambios respecto de spec 015 |
| conectada_en | timestamp, nullable | Fecha en que se completó el intercambio de token + verificación exitosa |
| token_expira_en | timestamp, nullable | `conectada_en` + `expires_in` (segundos) devuelto por `/token` (~1 año en la práctica, research.md §R3). El panel de estado muestra los días restantes calculados a partir de este campo — agregado el 29/07/2026 a pedido del usuario, migración `2026_08_07_060001` |
| actualizada_por | FK → usuarios, nullable | |

**Campos que existían en spec 015 y se quitan**: `store_id` (no aplica con OAuth), `nombre_tienda` /
`dominio` / `pais` / `moneda` (sin tool que los provea), `ultima_verificacion_en` /
`credenciales_guardadas_en` (reemplazados por `conectada_en`).

Desconectar borra `access_token` (deja `client_id`/`client_secret` intactos, para no tener que
auto-registrar de nuevo al reconectar) y pasa `estado` a `no_configurada`.

### `tn_operaciones_log`

Mismo esquema exacto que `ml_operaciones_log` (§8), tabla separada — historial propio de Tiendanube
(integraciones y credenciales distintas, no se mezclan).

| Campo | Tipo | Notas |
|---|---|---|
| operacion / metodo / endpoint / sentido / resultado / codigo_http / duracion_ms / mensaje_error / payload_bloqueado / usuario_id / created_at | — | Mismas columnas y semántica que `ml_operaciones_log` (§8) |

Retención: 30 días o 5.000 registros, depurada de forma oportunista — mismo criterio y mecanismo que
`ml_operaciones_log`.

### `tn_conexion_rest` / `tn_rest_operaciones_log` (spec 022 — conexión aislada, no reemplaza lo de arriba)

Dos tablas **nuevas y completamente independientes** de `tn_configuracion`/`tn_operaciones_log` — no las
modifican ni comparten filas. Guardan la conexión OAuth clásica contra una Application del Partner Portal
de Tiendanube (`www.tiendanube.com/apps/{app_id}/authorize`, REST API `api.tiendanube.com`), verificada
empíricamente (31/07/2026) como **no intercambiable** con el token del servidor MCP que usa
`tn_configuracion` (401 al probar el token de una contra el servidor de la otra). Detalle completo del
esquema en `specs/022-tiendanube-conexion-rest/data-model.md`.

`tn_conexion_rest` — registro único (single-tenant, mismo patrón que `tn_configuracion`):

| Campo | Tipo | Notas |
|---|---|---|
| access_token | text, nullable | **Cifrado** (cast `encrypted`), `$hidden`. Nunca se devuelve a la interfaz |
| store_id | string(50), nullable | `user_id` devuelto por el canje de código — va en la ruta de cada llamada REST |
| scopes_otorgados | string(255), nullable | Tal cual los informa Tiendanube en el canje |
| tienda_nombre | string(255), nullable | De `GET /{store_id}/store` (verificación de conexión) |
| tienda_dominio | string(255), nullable | `original_domain` de `GET /store` |
| estado | string(20) | `no_configurada` / `conectada` / `caida` — mismo enum PHP `EstadoConexion` que ya usa `tn_configuracion`, tabla separada |
| conectada_en | timestamp, nullable | |
| ultimo_error | text, nullable | |
| actualizada_por | FK → usuarios, nullable | |

**No guarda `client_id`/`client_secret`**: viven sólo en `.env`/`config('integraciones.tiendanube')` — son
credenciales de la Application (fijas), no de la conexión con una tienda particular, a diferencia del
auto-registro dinámico que sí persiste `tn_configuracion.client_id`/`client_secret` para el flujo MCP.

`tn_rest_operaciones_log` — mismo esquema y retención que `tn_operaciones_log` (30 días o 5.000 registros),
tabla separada para que no haya forma de confundir a qué conexión pertenece cada fila. Desde spec 024
también registra las operaciones de negocio de `ClienteTiendanubeRest` (`orders`, `products`, `variants`),
no sólo las de conexión.

> ✅ **Spec 024 (implementada, Historias 1 y 2): `tn_conexion_rest` gana la configuración de negocio.**
> `modo_solo_lectura` (bool, default false), `creacion_automatica` (bool, default false),
> `frecuencia_sync_minutos` (unsignedSmallInteger, nullable), `deposito_id`/`categoria_venta_id`/
> `cuenta_tesoreria_id`/`lista_precio_id`/`vendedor_id` (FK nullable, `nullOnDelete`), `dias_primera_sync`
> (unsignedSmallInteger, nullable), `ultima_sync_en`/`ultima_sync_resultado`,
> `stock_ultima_sync_en`/`stock_ultima_sync_resultado` — mismos campos y semántica que las columnas
> homónimas de `tn_configuracion` (ver §12), migrados acá porque los sincronizadores de negocio pasan a
> depender exclusivamente de esta conexión. Una migración de datos copió los valores vigentes de
> `tn_configuracion` a la fila única de `tn_conexion_rest` (idempotente, sobreescritura por `id=1`). Ver
> §13.

**Continuación (spec 017, implementada)**: tablas de órdenes de Tiendanube, líneas de orden y
vinculación variante↔producto — ver §12.

---

## 12. Ventas de Tiendanube (spec 017) y stock/precios hacia Tiendanube (spec 018)

> ✅ **Spec 017 implementada (30/07/2026)**: `tn_ordenes`, `tn_orden_items` y `tn_variante_producto` ya
> existen (migraciones `2026_08_09_*`), junto con las columnas de ventas de `tn_configuracion`,
> `clientes.tn_customer_id` y el valor `tiendanube` de `ventas.origen`. Las columnas listadas más abajo
> como "spec 018" (`tn_variante_producto.tn_product_id`/columnas de stock y precio, `tn_configuracion`
> columnas de stock y `lista_precio_id`) **todavía no existen** — spec 018 sigue especificada (ampliada
> 30/07/2026 con precios), no implementada.

Extiende §11 (integración Tiendanube, spec 019, corrige a la 015). Detalle completo, con enums y
transiciones de estado, en `specs/017-ventas-tiendanube/data-model.md` y
`specs/018-stock-tiendanube/data-model.md` — ambas corregidas post-019 contra el contrato real de las
tools del servidor MCP (verificado empíricamente 30/07/2026), no contra la documentación REST pública
que asumía la primera versión de estas dos specs.

**Convención terminológica**: "orden" = documento sincronizado desde Tiendanube; "Venta" = documento del
CRM (§5) — mismo criterio que §10 para Mercado Libre.

### `tn_ordenes`
Órdenes sincronizadas. `tn_order_id` (bigint, **unique** — mapea al campo `id` de la tool `list_orders`,
no a `number`, que es el correlativo visible al comprador; identidad e idempotencia), `status`
(`open`/`closed`/`cancelled`), `payment_status` (`pending`/`authorized`/`paid`/`partially_paid`/
`voided`/`expired`/`refunded`/`partially_refunded`/`abandoned`/`chargeback`), `fulfillment_status`
(informativo, no participa del estado de conversión — corrección post-019: la tool real la llama así, no
"shipping_status" como decían las primeras versiones de este documento y de la spec 017),
`estado_conversion` (enum propio `Tiendanube\EstadoConversion`: mismos 5 valores que Mercado Libre —
`pendiente_pago`/`lista`/`requiere_atencion`/`convertida`/`cancelada`, derivado de `status`+
`payment_status`), `motivo` (enum propio `Tiendanube\MotivoRequiereAtencion`) + `motivo_detalle`,
`fecha_creada`, `fecha_cerrada` (ambas mapean a `completed_at`, único campo de fecha que expone la tool
real — se usa como `fecha_emision` de la Venta), `total`, `moneda`, `storefront` (**nunca** `meli` — se
descarta antes de persistir, ver nota abajo), datos del comprador (`tn_customer_id`, `comprador_email`,
`comprador_nombre`, `billing_document_number` — corrección post-019: mapea a `customer.cpf_cnpj`, no
existe `billing_document_type` en la tool real, y está vacío en las 9 órdenes reales de la tienda),
`venta_id` (FK → `ventas`, nullable, **unique**), `creacion_automatica`, `convertida_en`,
`convertida_por`, `payload` (json, sin datos sensibles), `sincronizada_en`. **Sin soft delete ni purga**
— respaldo de documentos contables, mismo criterio que `ml_ordenes`.

> **Exclusión del canal Mercado Libre integrado a Tiendanube**: las órdenes con `storefront = "meli"`
> (importadas desde Mercado Libre a través de Tiendanube) **nunca** se persisten en `tn_ordenes` — se
> descartan en `TraductorOrdenes` antes de guardar. **Corrección post-019**: es una sola capa, no dos —
> la tool `list_orders` no tiene ningún parámetro para excluir el canal en la propia consulta. Para no
> duplicar lo que ya ingresa la integración directa de Mercado Libre (§10). Detalle en
> `specs/017-ventas-tiendanube/research.md` R2.

### `tn_orden_items`
`tn_orden_id` (FK, cascade), `tn_product_id` (agregado post-019: identificador del **producto**, no sólo
de la variante — necesario para que la spec 018 pueda actualizar stock), `variant_id` (identificador de
variante en Tiendanube — **siempre** presente, incluso en productos sin variantes reales, que tienen una
variante "virtual" única), `nombre_producto`, `nombre_variante` (nullable — corrección post-019: se arma
concatenando `variant_values`, no viene como campo suelto), `sku` (agregado post-019), `cantidad`,
`precio_unitario` (**precio FINAL con IVA incluido**, mismo criterio que Mercado Libre), `total_linea`,
`producto_id` (FK → productos, nullable — se congela al convertir).

### `tn_variante_producto`
Vinculación **1:N** entre variantes de Tiendanube y un producto del CRM (spec 036, 03/08/2026 — hasta
esa spec fue estrictamente 1:1) — equivalente a `ml_publicacion_producto` (§10), pero por **variante**,
no por publicación, porque la API de Tiendanube siempre expone `variant_id` por línea. `variant_id`
(**unique** — una variante pertenece a un único producto), `producto_id` (FK → productos, **sin índice
único** desde spec 036 — un producto puede tener N variantes vinculadas), `nombre_variante_tn`,
`vinculada_por`. El stock y el precio de un Producto se sincronizan hacia TODAS sus variantes
vinculadas — ver `specs/036-vinculacion-multiple-ml/`.

**Columnas nuevas (spec 018 — sincronización de stock hacia Tiendanube)**:

| Campo | Tipo | Notas |
|---|---|---|
| `tn_product_id` | string(50) | Identificador del **producto** de Tiendanube dueño de la variante. La tool `update_stock_and_price` (servidor MCP, corrección post-019) exige `product_id` por cada ítem del lote — el `variant_id` solo no alcanza. Se completa al crear el vínculo, a partir del dato ya disponible en su origen (`tn_orden_items.tn_product_id` si se vincula desde una línea de orden, o el catálogo de productos si se vincula desde la pantalla dedicada). |
| `stock_pendiente` | boolean, default `false` | `true` cuando hubo un movimiento de stock elegible sin empujar todavía a Tiendanube. Lo pone en `true` la rama Tiendanube de `MovimientoStockObserver` (spec 013/018); lo vuelve a `false` `SincronizadorStock` tras un envío exitoso. |
| `stock_sincronizado_en` | timestamp, nullable | Fecha del último envío **exitoso**. |
| `stock_error` | string(255), nullable | Motivo del último rechazo (o de un vínculo con `tn_product_id` incompleto). Se limpia (`null`) en el siguiente envío exitoso. |
| `stock_error_en` | timestamp, nullable | Fecha de ese rechazo. |

Mismo criterio que `ml_publicacion_producto` (§10): sin índice adicional (volumen esperado de decenas de
filas); eliminar el vínculo elimina estas columnas junto con la fila.

**Columnas nuevas (spec 018 — ampliación de precios, 30/07/2026)**, calcadas de las cuatro que la spec 016
agregó a `ml_publicacion_producto` para el mismo propósito:

| Campo | Tipo | Notas |
|---|---|---|
| `precio_pendiente` | boolean, default `false` | `true` cuando hay un precio vigente en la Lista de Precios configurada para Tiendanube sin confirmar como enviado (cambio reciente, envío fallido, o vínculo creado después de un cambio ya ocurrido). |
| `precio_sincronizado_en` | timestamp, nullable | Fecha del último envío de precio **exitoso**. |
| `precio_error` | string(255), nullable | Motivo del último rechazo de Tiendanube al actualizar el precio. |
| `precio_error_en` | timestamp, nullable | Fecha de ese rechazo. |

### `tn_configuracion` (columnas nuevas)
`creacion_automatica` (bool, default false), `frecuencia_sync_minutos` (default 15), `deposito_id` (FK →
depositos, nullable — null usa el depósito por defecto), `categoria_venta_id` (FK → categorias,
nullable), `cuenta_tesoreria_id` (FK → cuentas_tesoreria, nullable — **a diferencia de Mercado Libre**,
que resuelve la cuenta de cobranza por nombre fijo "Mercado Pago", acá es configurable porque Tiendanube
admite múltiples medios de pago sin una pasarela canónica), `dias_primera_sync` (default 30),
`ultima_sync_en`, `ultima_sync_resultado`.

**Columnas nuevas (spec 018)**: `stock_ultima_sync_en` (timestamp, nullable — cuándo corrió por última
vez la sincronización de stock), `stock_ultima_sync_resultado` (string(255), nullable — texto legible del
resultado de esa corrida, mismo patrón que `ultima_sync_resultado`). Sin columna de activar/desactivar
propia: gobernada por la función avanzada "Tiendanube" + modo sólo lectura, igual que el resto de la
integración.

**Columna nueva (spec 018 — ampliación de precios, 30/07/2026)**: `lista_precio_id` (FK →
`listas_precio`, nullable, `nullOnDelete()`) — Lista de Precios que gestiona los precios de las variantes
vinculadas de Tiendanube, mismo rol que `ml_configuracion.lista_precio_id` (spec 016, §10). Opcional: sin
ninguna configurada, no hay sincronización de precio. **Sin columna de "última sincronización" para
precio** (mismo criterio que Mercado Libre): a diferencia de stock, no hay corrida programada cuyo
resultado persistir acá.

**Columna nueva (spec 020, implementada)**: `vendedor_id` (FK →
`vendedores`, nullable, `restrictOnDelete`). "Vendedor por defecto" para las Ventas creadas
automáticamente por sincronización de Tiendanube, independiente del de Mercado Libre. Detalle
completo en `specs/020-vendedores/data-model.md`.

> ⚠️ **Retirado por spec 024 (Historia 1)**: el alta manual con selector (fuente: `tn_orden_items`) y el
> mecanismo de importación masiva por Excel descrito abajo se **reemplazan por completo** por
> `VinculadorAutomatico` (catálogo REST en vivo, SKU directo contra `sku` de cada variante — ver §13). Se
> deja el párrafo original como registro histórico de cómo se resolvía antes.
>
> **Mecanismo de importación masiva (spec 021) — sin columna nueva**: además del alta manual ya
> existente, `variant_id`/`tn_product_id`/`producto_id` se podían crear en lote importando el archivo de
> productos que Tiendanube exporta. `producto_id` se resolvía por `productos.codigo` (match exacto o por
> el número inicial); `variant_id`/`tn_product_id` se resolvían consultando el catálogo de Tiendanube en
> vivo (tool `list_products` del MCP) por el "Identificador de URL" de cada fila del archivo — el SKU de
> Tiendanube nunca viajaba por esa integración, sólo por el archivo. Detalle histórico en
> `specs/021-vinculacion-automatica-sku/data-model.md`.

**`tn_configuracion` (columnas de negocio)**: `creacion_automatica`, `frecuencia_sync_minutos`,
`deposito_id`, `categoria_venta_id`, `cuenta_tesoreria_id`, `dias_primera_sync`, `ultima_sync_en`,
`ultima_sync_resultado`, `stock_ultima_sync_en`, `stock_ultima_sync_resultado`, `lista_precio_id`,
`vendedor_id` — descritas abajo como referencia histórica de cuándo se agregó cada una (specs
017/018/018-ampliación/020). **Desde spec 024 (Historia 1/2) estos mismos campos ya no se leen de acá**:
se migraron a `tn_conexion_rest` (§11) y `tn_configuracion` queda pendiente de retiro completo (Historia
3, condicionada a validación manual en producción — ver §13).

### `clientes` (columna nueva)
`tn_customer_id` (bigint, nullable) — análogo a `ml_user_id` (§8): emparejamiento estable del comprador,
persistido la primera vez que un Cliente se empareja por email.

### `ventas` (valor de enum nuevo)
`origen` (§10) agrega el valor `tiendanube` junto a `manual`/`presupuesto`/`mercadolibre`.

> **Cálculo clave**: mismo criterio que Mercado Libre (§10) — precios finales con IVA incluido,
> desagregación con el IVA del producto vinculado, redondeo absorbido en la última línea.
>
> **Tipo de comprobante**: a diferencia de Mercado Libre (que informa la condición de IVA del
> comprador), Tiendanube no informa ni condición de IVA ni tipo de documento clasificado — sólo
> `cpf_cnpj` (documento crudo, corrección post-019: no existe `billing_document_type`). Se deriva primero
> de la condición de IVA que el Cliente ya tenga cargada en el CRM y, si no la tiene, se aproxima por
> longitud del documento (11 dígitos/CUIT → A, cualquier otro valor o ausencia → B) — corregible
> manualmente después. En la práctica, verificado contra la tienda real, casi ninguna orden trae este
> dato.

---

## 13. Migración de la integración Tiendanube del MCP a la Application REST (spec 024)

> ✅ **Implementada (Historias 1 y 2). Historia 3 (retiro del MCP) pendiente de confirmación manual en
> producción.**

Sin entidades nuevas: `tn_ordenes`, `tn_orden_items`, `tn_variante_producto` no cambian de esquema — sólo
cambia qué cliente HTTP los alimenta y qué proceso decide crear cada vínculo. Detalle completo en
`specs/024-tiendanube-migracion-rest/data-model.md`.

- **`tn_conexion_rest` (spec 022) se extiende** con la configuración de negocio que antes vivía sólo en
  `tn_configuracion` — ver §11. `TiendanubeConexionRest` gana los mismos métodos de conveniencia que tenía
  `TiendanubeConfiguracion` (`deposito()`, `categoriaVenta()`, `cuentaTesoreria()`, `vendedor()`,
  `listaPrecio()`, `depositoEfectivo()`/`depositoEfectivoONulo()`).
- **`tn_configuracion` / `tn_operaciones_log` (MCP, spec 019) quedan retiradas al final de la Historia 3**:
  `Schema::dropIfExists` sobre ambas, tras un backup de base de datos (operación destructiva sin reversión
  automática). Campos que **no** se migran (específicos del transporte MCP, sin equivalente en REST):
  `client_id`, `client_secret`, `access_token`, `token_expira_en`, `scopes_otorgados`, `productos_total`,
  `conectada_en`, `estado`, `ultimo_error` — se pierden junto con la tabla, no son "datos de negocio".
  `tn_operaciones_log` (historial de operaciones MCP) tampoco se migra: es auditoría de un transporte que
  deja de existir, no configuración viva.
- **`tn_rest_operaciones_log` (spec 022) pasa a registrar también operaciones de negocio** (`orders`,
  `products`, `variants`), no sólo las de conexión — ver §11.
- **Flujo de resolución de vínculo, reemplazado por completo** (spec 017 US2 + selector manual + spec 021
  importación por Excel → catálogo REST en vivo):

  ```text
  GET /products (paginado) → producto (id, status) → variants[] (id, sku)
    ├─ sku vacío ──────────────────► sin vincular: "sin_sku"
    ├─ sku no matchea Producto::id ─► sin vincular: "producto_no_encontrado"
    ├─ variante ya vinculada ───────► excluida antes de procesar (no cuenta)
    ├─ producto ya vinculado ───────► sin vincular: "ya_vinculado" (detalle: producto)
    └─ match limpio ────────────────► crea tn_variante_producto (variant_id, tn_product_id, producto_id)
  ```

**Datos de negocio conservados tras el retiro del MCP (FR-020, cuando corra la Historia 3)**: pedidos
(`tn_ordenes`/`tn_orden_items`), vínculos (`tn_variante_producto`) y los movimientos/Ventas ya derivados de
ellos — sólo se elimina la infraestructura de conexión MCP, no el historial de negocio generado mientras
estuvo activa.

## 14. Mensajería de Mercado Libre (spec 032 — Fase 0, sin IA)

> Alcance: leer Preguntas pre-venta y Mensajería post-venta de Mercado Libre en una bandeja unificada y
> responderlas manualmente, con auditoría. **Sin generación de IA** en esta fase — el bot con
> sugerencias es la Fase 1 (spec 033, especificada, ver más abajo). Detalle completo en
> `specs/032-bot-mensajeria-mercadolibre/data-model.md`.

### `ml_conversaciones`

Hilo de intercambio con un comprador. `tipo` (`pregunta` | `post_venta`) determina la clave de
agrupación: por comprador+publicación para Preguntas (ML no las agrupa nativamente), por
`pack_id_ml` para post-venta (ML ya agrupa por pack/orden). Columnas: `tipo`, `comprador_ml_id`,
`comprador_nickname`, `publicacion_id_ml` (nullable), `ml_publicacion_producto_id` (FK nullable →
`ml_publicacion_producto`), `pack_id_ml` (nullable — pack/order id crudo de ML, sólo `tipo=post_venta`),
`ml_orden_id` (FK nullable → `ml_ordenes`), `estado` (`pendiente`/`respondida`/`cerrada`),
`ultimo_mensaje_en`.

**Corrección post-implementación (02/08/2026, primer mensaje real en producción)**: la clave de
deduplicación de post-venta originalmente era `(tipo, ml_orden_id)`, pero esa FK sólo se completa si
la orden ya fue sincronizada al CRM (`ml_ordenes`) — el webhook de mensaje puede llegar antes que el
cron de sincronización de órdenes, o la orden nunca sincronizarse. Con `ml_orden_id` NULL, dos packs
distintos sin sincronizar pisaban la misma conversación. Se agregó `pack_id_ml` (dato crudo,
siempre presente — mismo patrón que `publicacion_id_ml` para Preguntas) y la clave única pasó a ser
`(tipo, pack_id_ml)`. `ml_orden_id` se completa cuando existe el vínculo, pero ya no es la clave de
deduplicación. También se corrigió el endpoint de envío (`EnvioRespuestaMercadoLibre`, que armaba
`from`/`to` invertidos y una URL con `pack_id` vacío cuando faltaba la orden) y se confirmó contra la
documentación oficial de ML (vía MCP) que `resource` de `topic=messages` es el `message_id` directo
(`GET /messages/{message_id}?tag=post_sale`), no un path como asumía la primera implementación — ver
`specs/032-bot-mensajeria-mercadolibre/contracts/webhook-mercadolibre.md` para el detalle completo.

### `ml_mensajes`

Cada mensaje individual de una conversación. Columnas: `ml_conversacion_id` (FK), `ml_id` (ID nativo
de ML — `question_id` o `message_id, clave natural para idempotencia ante reintentos de webhook,
índice único), `origen` (`comprador`/`negocio`), `texto`, `enviado_en`.

### `ml_respuestas_enviadas`

Auditoría de una respuesta efectivamente enviada al comprador. Columnas: `ml_mensaje_id` (FK),
`texto_enviado`, `usuario_id` (FK `users`, quién confirmó), `enviado_en`, `resultado`
(`exito`/`error`), `error_mensaje` (nullable). Índice único `(ml_mensaje_id)` con `resultado=exito`
para impedir dos respuestas exitosas al mismo mensaje (condición de carrera entre dos usuarios) —
**implementado** vía una columna generada `ml_mensaje_id_si_exito` (`STORED AS CASE WHEN resultado =
'exito' THEN ml_mensaje_id ELSE NULL END`) con índice único sobre ella, porque MySQL no soporta índices
únicos parciales nativos.

### `permisos` (módulo nuevo)

Se agrega el módulo `mensajeria` con acciones `ver` y `responder` (separadas, para poder dar acceso de
sólo lectura sin permitir enviar respuestas).

## 15. Bot de Mercado Libre con sugerencias de IA (spec 033 — Fase 1, ✅ implementada)

> Se apoya en el modelo de §14 (spec 032) sin romperlo. Detalle completo en
> `specs/033-bot-mercadolibre-ia/data-model.md`. Pendiente el gate operativo del VPS con colas reales
> antes de activar el switch en producción (`docs/bot_mensajeria_ml/infraestructura.md`).

### `ml_sugerencias`

Borrador generado por IA para un mensaje entrante. Columnas: `ml_mensaje_id` (FK → `ml_mensajes`),
`texto_sugerido` (nullable), `estado` (`generando`/`lista`/`error`), `error_mensaje` (nullable),
`generada_en` (nullable). El Job (`GenerarSugerenciaMercadoLibre`) marca `estado=error` si la respuesta
del proveedor viene vacía o supera los 350 caracteres (`seller_max_message_length` real de Mercado
Libre, confirmado contra su documentación oficial) — nunca se guarda un `texto_sugerido` fuera de ese
límite como `lista`.

### `ml_bot_configuracion`

Fila única (mismo patrón que `ml_configuracion`, §8). Columna: `instrucciones_tono` (nullable, system
prompt editable). El flag de activo/inactivo **no** vive acá — vive en `funciones_avanzadas.activa`
para la fila `clave='mercadolibre_bot'` (mismo mecanismo que `mercadolibre`/`tiendanube`, §8), para no
duplicar fuente de verdad.

### `ml_respuestas_enviadas` (columnas nuevas)

Se agregan `ml_sugerencia_id` (FK nullable → `ml_sugerencias`) y `sugerencia_editada` (boolean,
nullable) — sin tocar el índice único `(ml_mensaje_id)` con `resultado=exito` de §14.

### `funciones_avanzadas` (fila nueva)

`clave='mercadolibre_bot'`, con `ruta_configuracion` apuntando a la pantalla de configuración del bot.

## 16. Mi Perfil: datos fiscales del negocio emisor (spec 039, implementada)

### `datos_empresa`

Fila única (mismo patrón single-row que `ml_configuracion` §8 y `ml_bot_configuracion` §15), sin
`SoftDeletes` — es configuración, no un registro de negocio con historial fiscal. Acceso vía
`DatosEmpresa::instancia(): ?self`.

| Campo | Tipo | Notas |
|---|---|---|
| `razon_social` | string, nullable | — |
| `cuit` | string(11), nullable | sin guiones, mismo formato que `certificados_fiscales.cuit` (§8.bis) |
| `domicilio_fiscal` | string, nullable | — |
| `condicion_iva` | string, nullable | texto libre poblado desde el mismo catálogo `condiciones_iva` (§2) que usan Cliente/Proveedor, sin FK — Mi Perfil no es un Cliente/Proveedor |
| `ingresos_brutos` | string, nullable | opcional |
| `ruta_logo` | string, nullable | ruta relativa en disco `public` (`storage/app/public/empresa/`), validada como imagen (jpg/png/webp, máx. 2MB) antes de persistir |

Consumida por el partial `resources/views/pdf/partials/encabezado-emisor.blade.php`, incluido en los
PDFs de Venta (§5) y de Notas de Crédito/Débito (§5) — se omite sin bloquear la generación del PDF si
`DatosEmpresa::instancia()` devuelve `null`.

> **Actualización (spec 043, 04/08/2026)**: la pantalla "Mi Perfil" se renombra a "Empresa" y pasa a
> incluir también la tabla de usuarios (antes en la pantalla separada "Usuarios y Permisos", eliminada).
> Sin cambios de esquema en `datos_empresa` ni en `usuarios`/`roles`/`rol_usuario` — sólo cambia qué
> vista consume esos datos. El acceso a "Empresa" y a todo Configuración & Ajustes pasa a depender del
> rol `Admin` (`usuarios.roles` con `nombre='Admin'`, ya existente), no de permisos granulares.

## 17. Configuración & Ajustes → Ventas: valores por defecto de "Crear Venta" (spec 043)

### `configuracion_ventas`

Fila única (mismo patrón single-row que `datos_empresa` §16), sin `SoftDeletes` — es configuración
global del negocio, no un registro con historial fiscal.

| Campo | Tipo | Notas |
|---|---|---|
| `categoria_id` | bigint, nullable, FK → `categorias.id` `nullOnDelete` | Categoría de venta preseleccionada por defecto en "Crear Venta" |
| `vendedor_id` | bigint, nullable, FK → `vendedores.id` `nullOnDelete` | Vendedor preseleccionado por defecto |
| `lista_precio_id` | bigint, nullable, FK → `listas_precio.id` `nullOnDelete` | Lista de Precios por defecto (si `null`, sigue el fallback actual "Principal") |
| `tipo_comprobante` | enum(`A`,`B`,`C`,`E`), nullable | Tipo de Comprobante por defecto (si `null`, sigue el fallback actual "B") |
| `dias_vto_cobro` | unsigned smallint, nullable | Días a sumar a la fecha de Emisión para precalcular "Vto. del Cobro" en altas nuevas (si `null`, el campo se deja vacío) |
| `dias_validez_presupuesto` | unsigned smallint, nullable | **(spec 044)** Días a sumar a la fecha de Emisión para precalcular "Vto. de Validez" en "Crear Presupuesto" — reutiliza Categoría/Vendedor/Lista de Precios de la sección Ventas de arriba |
| `categoria_compra_id` | bigint, nullable, FK → `categorias.id` (`tipo=compra`) `nullOnDelete` | **(spec 044)** Categoría de Compra preseleccionada por defecto en "Crear Compra" |
| `tipo_comprobante_compra` | enum(`A`,`B`,`C`), nullable | **(spec 044)** Tipo de Comprobante por defecto de Compra (si `null`, sigue el fallback "B") |
| `dias_vto_pago_compra` | unsigned smallint, nullable | **(spec 044)** Días a sumar a la fecha de Emisión para precalcular "Vto. de Pago" en "Crear Compra" |
| `deposito_id` | bigint, nullable, FK → `depositos.id` `nullOnDelete` | **(spec 049)** Depósito por defecto de "Crear Venta" (sección "Ventas" de la pantalla). Si `null`, o si el depósito referenciado ya no está activo, cae al fallback global `Deposito::porDefecto()` |
| `deposito_compra_id` | bigint, nullable, FK → `depositos.id` `nullOnDelete` | **(spec 049)** Depósito por defecto de "Crear Compra" (sección "Compras" de la misma pantalla), mismo fallback |

Consumida por `VentaController@create`/`CompraController@create` sólo para altas nuevas (no edición,
no conversión desde Presupuesto): si el registro referenciado por una FK ya no existe o no está
activo en su catálogo, ese campo no se precarga (no rompe el formulario). El default de Tipo de
Comprobante de Venta es sólo una preselección inicial: sigue pisado por `cliente.tipo_comprobante_defecto`
cuando el usuario elige un Cliente (prioridad ya existente, sin cambios), y no altera la derivación
fiscal por condición de IVA ya vigente.

Ventas, Presupuestos y Compras comparten esta misma fila única de configuración (no hay tabla
`configuracion_compras` separada) — decisión ya tomada en spec 043/044 y confirmada al planificar
spec 049, que suma los campos de Depósito al mismo patrón en vez de crear infraestructura nueva.

## 18. Auditoría: log transversal de operaciones (spec 054, implementada)

Ver `specs/054-auditoria-operaciones/data-model.md` para el detalle completo. Resumen:

### `logs_auditoria`

Tabla de solo lectura desde la aplicación (nunca se expone UPDATE/DELETE), poblada por Observers de
Eloquent sobre las entidades transaccionales en alcance (Venta, Presupuesto, Cobro, Gasto, Compra,
Movimiento de Tesorería, Movimiento de Stock). Sin `updated_at`: es un registro inmutable.

| Campo | Tipo | Notas |
|---|---|---|
| `usuario_id` | FK → `usuarios`, nullable | Nulo cuando la acción fue automática (integración ML/TN) |
| `usuario_nombre` | string(150) | Desnormalizado al momento del evento (protege el historial si el usuario se renombra a futuro); si `usuario_id` es nulo, contiene el label de origen (ej. "Ventas Online") |
| `origen_sistema` | string(50), nullable | `mercadolibre` / `tiendanube` / null (acción humana) |
| `tipo_accion` | enum(`creo`,`modifico`,`elimino`,`anulo`) | Columna "Tipo" de la pantalla |
| `tipo_operacion` | enum(`venta`,`presupuesto`,`cobro`,`gasto`,`compra`,`movimiento_tesoreria`,`movimiento_stock`,`precio_producto`) | Columna "Operación". **`precio_producto` desde la spec 074** — cambios de precio de un producto en una lista de precios (ver nota abajo) |
| `entidad_tipo` / `entidad_id` | string(100) / bigint | Referencia de sólo lectura (sin FK física) a la entidad de origen — sobrevive al soft delete de esa entidad |
| `detalle` | string(255) | Texto libre humano-legible generado por el sistema según `tipo_operacion`, fijado en el momento del evento |
| `total` | decimal(12,2), nullable | Monto de la operación al momento del evento |
| `created_at` | timestamp | Orden por defecto (desc) |

Índices: `(created_at)`, `(usuario_id)`, `(tipo_operacion)`, `(entidad_tipo, entidad_id)`.

Retención indefinida (a diferencia de `ml_operaciones_log`/`tn_operaciones_log`, que son logs
técnicos de diagnóstico con depuración a 30 días/5.000 registros — ésta es un registro de negocio de
largo plazo). Acceso a la pantalla vía permiso nuevo `auditoria.ver` (mismo esquema `permisos`/`roles`
de §1), asignado por defecto al rol Admin.

Un solo evento de auditoría por acción humana: los Observers filtran los `updated` que sólo tocan
campos derivados/recalculados internamente (no cada `save()` interno genera una fila nueva).

> **`precio_producto` — auditoría de cambios de precio por lista (spec 074, 22/08/2026):** todo cambio
> de `precios_producto` hecho **a través del modelo** genera un evento. Lo captura
> `PrecioProductoObserver` (el mismo punto único por el que ya pasa el push de precios a Mercado
> Libre/Tiendanube), así que cubre de una sola vez los cuatro orígenes: **importación masiva**, **edición
> manual** desde la ficha, **edición masiva de precios/costos** desde el listado (`accionAjustarPrecios`)
> y **copia de producto**. Particularidades de esta operación:
>
> - `entidad_tipo`/`entidad_id` apuntan al **`Producto`**, no a la fila de `precios_producto`: hace que
>   "todo el historial de precios del producto X" sea una consulta indexada por `(entidad_tipo, entidad_id)`,
>   y el registro sobrevive a que el precio se borre y se vuelva a crear.
> - `total` = precio nuevo (`null` cuando la acción es `elimino`). El **precio anterior no tiene columna
>   propia**: va en `detalle`, con la forma `"{Producto} — {Lista}: {anterior} → {nuevo} ({origen})"`.
> - El **origen** del cambio va como rótulo dentro de `detalle`, **no** en `origen_sistema` — esa columna
>   sigue reservada para acciones sin usuario humano (`mercadolibre`/`tiendanube`).
> - No se registra evento si el precio guardado es igual al anterior (comparado a 2 decimales): sin eso,
>   reimportar una planilla sin cambios generaría miles de filas espurias.
> - **Volumen**: es la operación que más filas aporta a esta tabla. Una importación de 5.000 productos
>   con 3 listas activas puede generar ~15.000 filas en una sola corrida, contra el resto de las
>   operaciones que llegan de a una. Por eso el importador escribe la auditoría **en lote** (buffer en
>   memoria + INSERT múltiple cada 200 eventos y al cerrar cada tanda). No se agregaron índices nuevos:
>   los existentes cubren las consultas de la pantalla y del historial por producto.
>
> **Excepción documentada (no se audita):** `MigrarPuntoReposicion` borra precios con
> `DB::table('precios_producto')->...->delete()`. Un `DELETE` de query builder no instancia modelos y por
> lo tanto **no dispara eventos**: esos borrados no quedan auditados. Es un comando de migración de única
> vez y se deja así a propósito; queda asentado acá para que no se asuma cubierto.

---

## 19. Migración del histórico de Contagram 2021-2026 (ejecutada el 10/08/2026)

No es una feature: es la carga por única vez del histórico del sistema anterior. No agrega entidades
ni cambia reglas de negocio; sólo suma la columna que identifica los registros migrados y un valor
de enum que faltaba. El diseño está en `docs/importacion_2021_2026_plan_tecnico.md` y los pendientes
en `docs/importacion_casos_a_revisar.md`.

### `legacy_id` — columna nueva en 5 tablas

| Tabla | Formato | |
|---|---|---|
| `ventas` | `{año}-{familia}-{Id}` | ya existía (spec previa), se le dio el formato con familia |
| `compras` | `{año}-{familia}-{Id}` | |
| `productos` | `{Id}` | el Id del producto en Contagram |
| `gastos` | `GASTO-{año}-{Id}` | |
| `notas_credito_debito` | `COMPRA-…` para las de compra | el prefijo evita que la NC 1 de compras choque con la NC 1 de ventas: **comparten tabla y ambos Id arrancan en 1** |
| `movimientos_tesoreria` | `TES-{cuenta}-{operación}-{Id}-{fecha}-{centavos}` | el `Id` de Contagram **no** identifica un movimiento: tiene 22.823 colisiones sobre 48.222 filas |

`string(40)` nullable y **`unique`** en todos los casos (64 en tesorería). El `unique` no es
decorativo: es lo que hace que el importador sea idempotente y lo que cortó en seco una corrida con
la clave mal construida.

**Es un dato de consulta permanente, no sólo del importador**: los comprobantes en papel, remitos y
reclamos traen el número viejo, así que el filtro por Id de Ventas busca por el id del CRM **y** por
`legacy_id`, y el listado lo muestra junto al id.

### `movimientos_tesoreria.tipo` — valor de enum nuevo

Se agregó **`ingreso`** a `('saldo_inicial','movimiento_entre_cuentas','cobro','pago','gasto')`.
Corresponde a los "Otros Ingresos" (aportes de socios, préstamos financieros). Mapearlos a `cobro`
los habría mezclado con los cobros de ventas.

> **Corrección (spec 055, 2026-08-11):** la nota original de este párrafo estaba desactualizada — el
> circuito Otros Ingresos→Tesorería **ya existe** desde la spec 008 (`Cobranzas::registrarOtroIngreso()`
> /`conciliar()`/`anularOtroIngreso()`) y el saldo de cuenta sí se mueve al cargar uno, porque
> `CuentaTesoreria::saldoA()` suma todos los tipos de movimiento sin filtrar. El bug real detectado
> era otro: (a) `Cobranzas::registrarOtroIngreso()` generaba el movimiento con `tipo='cobro'` en vez de
> `'ingreso'`, y (b) el informe de flujo de caja (`Tesoreria::flujo()`, pestaña Movimientos) sólo sumaba
> `tipo IN ('cobro')` a la sección "Cobros", dejando afuera `'ingreso'` — invisibilizando ahí los 61
> movimientos históricos migrados ($34.570.442,27). Corregido en spec 055: `registrarOtroIngreso()` ya
> usa `tipo='ingreso'`, y `flujo()` ya incluye `'ingreso'` junto con `'cobro'` en "Cobros" (criterio ya
> declarado en el banner de esa pantalla). Sin cambios de esquema ni migración de datos.

### `notas_credito_debito.venta_id` — corrección de esquema

Pasó a **nullable**, como el código siempre asumió. La migración que creó la tabla ya lo declaraba
así, pero el `->nullable()` se agregó al archivo después de que la tabla existiera y nunca llegó al
esquema. Efecto real: **emitir una NC/ND de una compra fallaba** (ahí `venta_id` va vacío por
diseño). Nunca se había disparado porque no se había hecho ninguna.

### Índices para volumen

Todas las tablas transaccionales se listan ordenadas por `created_at` y **ninguna tenía índice en
esa columna**: cada carga resolvía con `type=ALL` + `filesort`. Se indexó `created_at` y las fechas
de filtro en `ventas`, `compras`, `presupuestos`, `cobros`, `pagos`, `gastos`, `movimientos_stock`,
`movimientos_tesoreria`, `notas_credito_debito` y `remitos`.

### Reglas que fija la migración

- Los registros migrados **no generan movimientos de stock**: esa mercadería ya entró y salió, y el
  saldo actual lo refleja. `VentaObserver` y `CompraObserver` tienen el guardarraíl del otro lado
  (no reintegran al borrar un registro con `legacy_id`), cubierto por tests.
- Los cobros y pagos migrados **no generan movimientos de tesorería**: éstos entran completos desde
  los archivos `Cuentas/`, que son la única fuente que incluye las transferencias entre cuentas.
- `created_at` de los registros migrados = **la fecha del comprobante**, no la del import.

---

## 20. Módulo Informes — Tanda 1 (spec 067): **sin cambios de esquema**

> **Implementada el 14/08/2026.** Se cumplió la restricción: cero migraciones. Toda la derivación
> vive en `App\Services\Informes\` (`ComprasInformeQuery`, `DesgloseImpositivoCompra`,
> `GastosInformeQuery`).

Los tres informes de la tanda 1 (Compras, Gastos, Cuenta Corriente Proveedores) **no crean, alteran
ni borran ninguna tabla ni columna**. Se deja constancia acá de cómo se deriva cada dato mostrado,
porque el desglose impositivo del Informe de Compras parece exigir campos nuevos y no los exige.

### Desglose impositivo AFIP de Compras — todo derivado

| Dato del informe | De dónde sale |
|------------------|---------------|
| IVA 2,5 / 5 / 10,5 / 21 / 27 % (una columna por alícuota) | agrupar `compra_items` por **`iva_pct`** y sumar `subtotal_con_iva − subtotal` |
| Importe Neto Gravado | Σ `compra_items.subtotal` con `iva_pct` ∈ {`2.5`,`5`,`10.5`,`21`,`27`} |
| Importe Neto No Gravado | Σ `compra_items.subtotal` con `iva_pct IS NULL` **o** `iva_pct = 'no_gravado'` |
| Importe Neto Exento | Σ `compra_items.subtotal` con `iva_pct = 'exento'` |
| Perc. IVA / Perc. IIBB / Otras Percepciones | `compra_conceptos` con `tipo='percepcion'`, clasificados por el **texto** de `concepto` |
| Imp. Internos | `compra_conceptos` con `tipo='impuesto_interno'` |
| Subtotales y descuento | `compras.subtotal_sin_descuento`, `descuento`, `subtotal_con_descuento` |
| Vencimiento / CUIT / Punto de Venta / N° Factura | `compras.fecha_vto_pago`, `proveedores`, `compras.nro_comprobante`, `compras.tipo_comprobante` |
| Código / Tipo de producto / Afecta Stock | `productos`, `tipos_producto` vía `compra_items.producto_id` |

> **Corrección medida durante la implementación (14/08/2026)**: `compra_items.iva_pct` es un
> **`string(12)`**, no un número. Guarda los marcadores de `Producto::OPCIONES_IVA`
> (`'5'`, `'10.5'`, `'21'`, `'27'`, `'exento'`, `'no_gravado'`) o `NULL`. Por eso las tres filas de
> arriba se clasifican por marcador y no por comparación numérica: escrito como `iva_pct > 0` /
> `= 0`, MySQL habría casteado `'exento'` a `0` y `'no_gravado'` a `0`, mezclando las dos columnas.
> `'2.5'` no es una opción que el CRM ofrezca hoy al cargar una compra, pero la columna existe igual
> (ARCA la contempla y puede venir en datos migrados): da 0 en vez de esconder importes en otra.

**Invariante fiscal a testear**: para toda compra,
`Neto Gravado + Neto No Gravado + Neto Exento + Σ(IVA por alícuota) + Σ(percepciones) + Imp. Internos + intereses = compras.total`.

### Deuda de modelo anotada (no se resuelve en spec 067)

- **`compra_conceptos.tipo` no distingue percepción de IVA de percepción de IIBB** — el enum es
  `percepcion|impuesto_interno|interes`. La clasificación se hace por coincidencia de texto sobre
  `concepto` (`iibb`/`ingresos brutos` → IIBB, `iva` → IVA, resto → **Otras Percepciones**, columna
  creada justamente para que ningún importe se pierda ni se impute mal). La coincidencia es por
  **palabra completa** y no por substring —si no, "Retención activa" caería en Perc. IVA— y IIBB se
  evalúa **antes** que IVA, porque "Percepción IIBB s/ IVA" contiene las dos palabras y es de
  Ingresos Brutos. El mismo criterio corre en PHP y en SQL, con un test que verifica que coinciden
  (`InformeComprasDesgloseImpositivoTest::test_clasificacion_php_y_sql_coinciden`). Si en datos reales "Otras
  Percepciones" resultara el caso mayoritario, la salida es agregar un `subtipo` a la tabla y
  tipificar el concepto en el formulario de Compra — spec propia, toca el alta de Compras.
- **~~`venta_items` no tiene `costo_unitario`~~ — RESUELTO por la spec 075 (24/08/2026).** La spec 068
  había "resuelto" esto sin migración derivando el CMV del promedio ponderado de compras, sobre la
  premisa —**refutada**— de que así lo calculaba Contagram. Medido contra datos reales, esa fórmula
  daba $24,6M contra $40,57M y dejaba el KPI "Resultado" inflado en ~$16M. La spec 075 agrega
  `venta_items.costo_unitario` y `nota_credito_debito_items.costo_unitario` (ver §23). El promedio
  ponderado queda como **fallback** para las líneas históricas, que no se backfillean.
- **`compra_items` no tiene `variante_id`** — los informes no pueden desagregar por variante. Brecha
  ya documentada en `documentacion_principal_crm.md §4.3`.
- **El caso "gasto sin categoría" no existe en la base** — la spec 067 lo contemplaba (rótulo
  "Sin categoría"), pero medido contra el esquema real `gastos.categoria_id` es `NOT NULL` con
  `restrictOnDelete` (`2026_07_31_070003_create_gastos_table`): todo gasto tiene categoría y una
  categoría en uso no se puede borrar. El rótulo se dejó implementado igual, como red de contención
  por si la columna se volviera nullable —el gasto seguiría apareciendo en el informe en vez de
  desaparecer—, y hay dos tests que fijan las dos mitades. El caso que **sí** ocurre y se maneja es
  el de **gasto sin subcategoría**: una `categoria_id` que apunta a una categoría raíz se agrupa
  bajo ella con el rótulo "Sin subcategoría".
- **Agregación del tab "Saldos" de Cuenta Corriente en PHP, no en SQL** — sin `LIMIT/OFFSET` real
  (brecha de §6.4, con antecedente de `memory_limit` agotado en producción el 03/08/2026). La spec
  067 la **hereda** en la pantalla de proveedores sin agravarla: no toca
  `App\Services\Tesoreria\CuentaCorriente`, que comparten el Dashboard y el informe de clientes.

---

## 21. Módulo Informes — Tanda 2 (spec 068): **sin cambios de esquema**

> **Resumen**: el Informe de Ventas y el Reporte Final **no agregan ni modifican ninguna tabla,
> columna, índice ni relación**. Son de sólo lectura. Toda la lógica vive en
> `App\Services\Informes\` (`VentasInformeQuery`, `CostoMercaderiaVendida`, `ReporteFinalQuery`).

Se documenta acá porque tres cifras del módulo **parecen** exigir campos nuevos y no los exigen.

### 21.1 Costo Mercadería Vendida — columna calculada, no persistida

> ⚠️ **SUPERADO por la spec 075 (24/08/2026).** Lo de abajo describe cómo funcionaba el CMV entre el
> 15/08 y el 24/08/2026, y **sigue vigente como fallback** para las líneas de venta sin costo
> congelado (todas las históricas). Pero **ya no es la regla**: el CMV real es el costo del producto
> congelado al crear la venta. Ver **§23** y `specs/075-cmv-costo-congelado/`.

`venta_items` no guarda el costo del momento de la venta (§Deuda de modelo). El CMV del informe se
deriva en SQL, sin migración:

```sql
-- una sola subconsulta agrupada por producto, no correlacionada por fila
SELECT ci.producto_id,
       SUM(ci.precio_unitario * ci.cantidad) / NULLIF(SUM(ci.cantidad), 0) AS costo_promedio
  FROM compra_items ci
  JOIN compras c ON c.id = ci.compra_id AND c.deleted_at IS NULL
 GROUP BY ci.producto_id
```

`CMV Total (línea) = costo_promedio × venta_items.cantidad`; producto sin compras → 0.

**No confundir con "Costo Actual"**, que es `productos.costo × cantidad` (valorización vigente) y es
otra columna del mismo informe. Que las dos difieran es esperado y es lo que reproduce el
relevamiento.

### 21.2 Estado del Cobro — derivado, no una columna

El filtro "Estado del Cobro" del Informe de Ventas se resuelve comparando `SUM(cobros.monto)` contra
`ventas.total` (Cobrado / Parcial / Pendiente), con subconsulta, no con un campo de estado. `ventas`
no tiene ni necesita una columna de estado de cobranza.

### 21.3 Reporte Final — dos bases contables sobre las mismas tablas

No hay tabla de "resultado". Cada vista es un conjunto de agregaciones:

| Vista | Fuente de fecha | Nivel más profundo | Gastos pendientes |
|-------|-----------------|--------------------|-------------------|
| Ventas Vs. Compras (devengado) | `ventas.fecha_emision`, `compras.fecha_emision`, `notas_credito_debito.fecha_emision`, `gastos.fecha`, `otros_ingresos.fecha` | Categoría (Gastos: → Subcategoría) | **incluidos** |
| Cobros Vs Pagos (caja) | `cobros.fecha`, `pagos.fecha`, `gastos.fecha`, `otros_ingresos.fecha` | Cuenta de Tesorería | **excluidos** |

En la vista caja, la **categoría** de agrupación es la de la venta o compra de origen, no una del
cobro/pago (`cobros` y `pagos` no tienen categoría propia, sólo `cuenta_tesoreria_id`). La jerarquía
Categoría → Subcategoría de Gastos usa `categorias.categoria_padre_id`, igual que en la tanda 1.

El servicio devuelve **todos los montos en positivo** con un campo `naturaleza` (`ingreso`/`egreso`).
La convención de signos invertida que exporta Contagram se aplica sólo al escribir el Excel
(réplica R2, ver `documentacion_principal_crm.md §6.6`), nunca en el modelo.

*Fuente(s): `specs/068-informes-ventas-reporte-final/` (`research.md` R2, R5, R6; `data-model.md`)*

## 22. Módulo Informes — Tanda 3 (spec 069): Rankings, "Arma tu Informe"

> **Resumen**: la única tabla nueva del módulo. Todo lo demás se lee de las tablas ya existentes,
> ampliando la proyección de `VentasInformeQuery` y `ComprasInformeQuery` (spec 067/068) con columnas
> de dimensión que el detalle no necesitaba.

### 22.1 `informes_vistas` — configuración de cruce, no datos

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | bigint PK | |
| `informe` | enum(`ventas`,`compras`) | a qué informe pertenece; una vista nunca se lista en el otro |
| `descripcion` | string | rótulo de la pestaña guardada |
| `config` | json | `{ filas[], columnas[], dato, accion, exclusiones{} }` — dimensiones y medidas, no valores |
| `creado_por_id` | FK nullable → `users` | auditoría; **no** restringe quién ve la vista (son compartidas) |
| `created_at` / `updated_at` | timestamps | |

**Sin `deleted_at`**: no es documento fiscal ni contable — es configuración de presentación de un
informe de sólo lectura. Eliminar una vista es un `DELETE` real y no afecta ningún dato de negocio.

### 22.2 Dataset del pivot — ampliación de columnas, no tabla nueva

`VentasInformeQuery` y `ComprasInformeQuery` (ya existentes) ganan columnas de dimensión al final de
su proyección, sin tocar el orden ni las columnas que usa el detalle de las tandas 1/2:

| Columna nueva | Ventas | Compras |
|----------------|--------|---------|
| `categoria` (raíz, con fallback "Sin categoría") | sí | sí |
| `vendedor` (fallback "Sin vendedor") | sí | **no existe** — Compras no tiene vendedor |
| `tipo_producto` (fallback "Sin tipo de producto") | sí | sí |
| `proveedor` (fallback "Sin proveedor") | sí | ya existía en el detalle |
| `descuento_pct` | sí | sí |
| `etiquetas` | sí | sí |
| `total_venta` / `total_compra` (con impuestos, a nivel línea) | sí | sí |
| `comprobante_id` (técnica, para contar comprobantes distintos) | sí | sí |

El dataset se corta a **50.000 filas**; superado, `422` pidiendo acotar el rango. El render del
pivot se corta a **1.000 columnas**; superado, aviso en vez de dibujar.

### 22.2b Corrección hecha al implementar: `comprobante_id` lleva el TIPO

El plan decía "`comprobante_id` (técnica, para contar comprobantes distintos)" usando el `id` a
secas. **No alcanza**: `ventas` y `notas_credito_debito` son tablas distintas con secuencias
propias y hoy **comparten 644 ids**. Contando sólo el id, una venta y una nota con el mismo número
se fusionaban en un solo comprobante.

Medido antes de corregirlo: en el rango junio–diciembre 2021 el conteo perdía **12 comprobantes**
(2.139 reales contra 2.127 contados). En rangos recientes no se notaba —las ventas tienen ids
~24.000 y las notas ~20— pero es suerte, no diseño.

La columna proyecta ahora `tipo-id` (`venta-24209`, `nc-88`), armado con
`ExpresionSql::concatPlano()` para que funcione igual en MySQL y en la SQLite de los tests.

### 22.3 Medidas ("Dato") — nunca el total del comprobante

"Total Venta"/"Total Compra" y su versión sin impuestos se miden **a nivel línea**. El total del
comprobante (`ventas.total`, `compras.total`) **no** es una medida ofrecida: se repite por línea y
sumarlo lo contaría de más — la misma trampa ya documentada para el detalle en la tanda 2 (§21.2).
"Cantidad de Ventas/Compras" cuenta `comprobante_id` **distintos**, no líneas; "Cantidad de
Productos" es la suma de `cantidad`.

*Fuente(s): `specs/069-informes-rankings-pivot/` (`research.md` R1-R9; `data-model.md`)*

---

## 23. Costo congelado en el ítem de venta (spec 075): **dos columnas nuevas**

> **Resumen**: se agrega `costo_unitario` a `venta_items` y a `nota_credito_debito_items`. Corrige el
> CMV del Informe de Ventas, que la spec 068 había derivado sobre una premisa refutada (§21.1).

### 23.1 Las columnas

| Tabla | Columna | Tipo | Nullable | Default |
|---|---|---|---|---|
| `venta_items` | `costo_unitario` | `decimal(14,2)` | **Sí** | **ninguno** |
| `nota_credito_debito_items` | `costo_unitario` | `decimal(14,2)` | **Sí** | **ninguno** |

Costo del producto vigente en el momento en que se creó el comprobante. Inmutable: ninguna edición
posterior ni cambio en la ficha del producto lo recalcula.

### 23.2 Por qué nullable y SIN default (lo más importante de este cambio)

- **`NULL`** = "esta línea no tiene costo congelado" ⇒ el CMV cae al promedio ponderado de compras
  (§21.1, que pasa a ser el fallback). Es el estado de **todas** las líneas históricas.
- **`0`** = "esta línea tiene costo congelado y vale cero" ⇒ el CMV es 0. Es el caso del producto sin
  costo cargado (227 productos hoy) o de la línea sin producto asociado.

Un `default 0` haría estos dos casos indistinguibles: el fallback nunca se activaría y **todas las
ventas históricas pasarían a aportar 0 al CMV**. Es la regresión que la spec 075 prohíbe
explícitamente (SC-003). Mismo tipo de trampa ya documentada para `compra_items.iva_pct`.

Por la misma razón está **prohibido** escribir `NULLIF(costo_unitario, 0)` en la expresión del
informe: convertiría el 0 legítimo en "sin congelar" y los productos sin costo tomarían el promedio
de compras, dejando de reproducir a Contagram.

### 23.3 Expresión del CMV

Una sola expresión para ventas, NC y ND, sin ramas por tipo de comprobante:

```
CMV_linea = COALESCE(<tabla>.costo_unitario, costo_compras.costo_promedio, 0) × <cantidad_firmada>
```

El `leftJoinSub` de `CostoMercaderiaVendida` se conserva tal cual: deja de ser la regla y pasa a ser
el segundo término del `COALESCE`. El costo se guarda **en positivo**; el signo lo aporta la cantidad.

### 23.4 Cuándo se congela

| Situación | Valor |
|---|---|
| Alta de venta, línea con producto | `productos.costo` vigente |
| Alta de venta, sin `producto_id` o producto sin costo | `0` |
| Edición, línea preexistente | **se conserva** el valor anterior |
| Edición, línea agregada en esa edición | `productos.costo` del día de la edición |
| Mercado Libre / Tiendanube | `productos.costo` al crear la venta en el CRM |
| Desde presupuesto | `productos.costo` al crear la **venta**, no el presupuesto |
| Línea de NC/ND cuyo producto está en la venta de origen | copia del costo congelado de esa línea de la venta |
| Línea de NC/ND cuyo producto no está en la venta, o nota sin `venta_id` | `productos.costo` al emitir la nota |
| Comandos de migración histórica | **no se toca** ⇒ `NULL` ⇒ fallback |

`nota_credito_debito_items` no guarda referencia al `venta_item` de origen: la correspondencia se
resuelve por `notas_credito_debito.venta_id` + `producto_id`, consumiendo cada costo una sola vez y
en orden de línea (una venta con el mismo producto en dos líneas revierte los dos costos).

**Corrección del 24/08/2026, durante la implementación**: la regla NO mira la columna `origen`,
aunque la spec 075 estaba redactada en función de ella. En `NotaCreditoDebitoController` `origen`
no distingue "revierte la venta original" de "ajuste nuevo": distingue si la nota **afecta stock**
o no. Una NC que anula una venta entera sin tocar stock guarda todas sus líneas como `nuevo`, así
que la regla original le habría aplicado el costo de hoy y anular una venta habría dejado un
residuo en el Resultado — justo lo que FR-008 quería evitar. La correspondencia por venta +
producto refleja la intención y no depende de ese flag.

Un `NULL` heredado también se conserva: la NC de una venta histórica cae al mismo fallback que la
venta que revierte, y el neto entre las dos sigue dando cero.

**Segunda corrección, de la validación en navegador (24/08/2026)**: el formulario de NC/ND **agrupa
por producto** los ítems de la venta original, así que una línea de nota con cantidad N puede estar
revirtiendo N líneas de venta con costos congelados distintos. El costo de la línea de nota es
entonces el **promedio ponderado** de las líneas de venta que consume hasta cubrir su cantidad, no
el de la primera. Sin esto, anular una venta con el mismo producto en dos líneas de costos
distintos dejaba residuo en el Resultado.

### 23.5 Sin backfill (y cómo se haría si algún día se quiere)

Las filas existentes quedan en `NULL` a propósito. El backfill es **viable y exacto** desde los
exports "Informe de Ventas Detallado" de Contagram: `costo_unitario = CMV Total ÷ Cantidad`, con
llave `Id` de venta + `Código` de producto. Limitaciones: 4,4% de las líneas tienen CMV 0 y no hay
nada que recuperar, y las ventas creadas en el CRM después del corte del 13/08/2026 no existen en
Contagram.

### 23.6 El Informe de Compras no cambia

Verificado contra `migracion-nueva/excel-origen/Compras/2026 Compras.xlsx`: su card "Costo Actual" ya
es costo vigente × cantidad (coincide al peso con la suma del export), el costo no varía por fecha de
compra (699 de 700 productos con un único valor en todo el año) y el informe no tiene card de CMV.
Además el costo real de una compra ya vive en `compra_items.precio_unitario`.

*Fuente(s): `specs/075-cmv-costo-congelado/` (`research.md` R1-R9; `data-model.md`; `contracts/cmv-api.md`)*

---

## 24. Importe por línea del Informe de Ventas (spec 076)

**Sin cambios de esquema.** Es una regla de cálculo, no una columna: el dato ya está guardado y lo
que faltaba era proyectarlo bien.

### 24.1 La regla

```
importe_linea = subtotal_neto_de_la_linea
              + IVA_de_la_linea
              + conceptos_extra_del_comprobante × (neto_de_la_linea / neto_del_comprobante)
```

`venta_items.subtotal` **ya viene neto de IVA y con los dos descuentos aplicados** (el de línea y el
general), así que el primer término no requiere cálculo adicional. Los conceptos extra
(`venta_conceptos`: percepciones, impuestos internos, intereses) viven a nivel comprobante y se
reparten **en proporción al neto de cada línea** —el mismo criterio que `CalculoComprobante` usa
para un descuento general cargado como monto fijo—. El residuo del redondeo lo absorbe la última
línea, como ya hacen los conversores de Mercado Libre y Tiendanube.

**Invariante**: la suma del importe de línea de un comprobante es igual a su total, **al centavo**.
Esa es la propiedad que hace sumable la columna, y tiene test.

### 24.2 Por qué esto figura como corrección y no como novedad

La documentación afirmaba que esta columna iba "repetido por fila, no sumable" (ver la corrección
del 24/08/2026 en `documentacion_principal_crm.md` § Informe de Ventas). Era falso. Lo llamativo es
que el motor **ya tenía la columna bien calculada**: `VentasInformeQuery::proyeccion()` emite
`total_venta` desde la spec 069, con un comentario que dice exactamente lo contrario de lo que decía
el doc. Se había aplicado sólo al motor de tablas dinámicas; la pantalla, los exports y el PDF se
quedaron con `total_comprobante`.

### 24.3 Desglose impositivo del export detallado — derivado, no guardado

Las columnas de neto por condición y de IVA por alícuota salen de `venta_items.iva_pct`, que es
texto y puede ser un porcentaje (`'21'`, `'10.5'`) o una condición (`'exento'`, `'no_gravado'`):

| `iva_pct` | Columna de neto | Columna de IVA |
|---|---|---|
| `'exento'` | Importe Neto Exento | — |
| `'no_gravado'`, nulo o vacío | Importe Neto No Gravado | — |
| numérico | Importe Neto Gravado | la columna de esa alícuota |

Cada línea imputa a **exactamente una** columna de neto y a **como mucho una** de alícuota. No se
agregan columnas a `venta_items`: sería duplicar información derivable y estrenar deuda de
sincronización sin ganar nada.

### 24.4 Lectura del comprobante fiscal desde el informe

`comprobantes_fiscales` es polimórfica y **una venta puede tener más de una fila** (un rechazo de
ARCA y su reintento aprobado). Un `LEFT JOIN` directo duplicaría la línea y rompería todos los
totales del informe. Se lee con una **subconsulta de una sola fila**, el mismo patrón que la
proyección ya usa para las etiquetas.

*Fuente(s): `specs/076-fidelidad-informe-ventas/` (`research.md` R1-R7; `data-model.md`;
`contracts/export-detallado.md`)*
