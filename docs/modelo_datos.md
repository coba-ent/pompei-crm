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

### `permiso_rol` (pivot)
rol_id, permiso_id.

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
| cuit | string, nullable, único (ignora NULL) | N° de documento fiscal. Validación de DV sólo si tipo_documento es CUIT/CUIL |
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
| saldo_inicial_fecha | date, nullable | Fecha de apertura de la cuenta corriente. Sin uso funcional hoy más allá del aging del dashboard (spec 010, ver `App\Services\Tesoreria\CuentaCorriente::aging()`) — las pantallas completas de Cuenta Corriente por Cliente/Proveedor siguen sin implementar; se conserva el dato para cuando se retomen |
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
| codigo | string, nullable, único | SKU del producto base |
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

> **Historia de `proveedor_id` (spec 003):** la migración de `productos` corrió (2026-07-19) **antes**
> de que existiera `proveedores` (2026-07-20 originalmente), así que su `if (Schema::hasTable(...))`
> nunca se cumplió y la columna quedó sin FK real. Al reincorporar Proveedores se agregó una migración
> aparte sólo para la FK (`ON DELETE SET NULL`), como defensa en profundidad — la regla de negocio real
> ("no eliminar proveedor con productos asociados") se aplica en `ProveedorController::destroy()` vía
> `Proveedor::tieneOperaciones()`, no depende del constraint de base.

> **Stock inicial al crear (24/07/2026):** el formulario "Nuevo Producto" acepta `stock_inicial`
> (numérico) y `stock_inicial_deposito_id` como campos de **request únicamente** — no son columnas de
> `productos`. Si `stock_inicial > 0` y el producto controla stock, el controller genera un movimiento
> en `movimientos_stock` (tipo `ajuste`, descripción "Registro inicial") y actualiza `stocks` a través
> de `StockService::ajustar()`, igual que un ajuste manual posterior.

### `producto_variantes`
Variantes de un producto (ej. talle, color) — infraestructura conservada para una futura integración
con canales externos, que exigiría **SKU único por producto y por variante**. Un producto sin
variantes no lleva filas acá. La UI de alta está oculta en el modal (ver doc principal §2.2).

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| producto_id | FK → productos | cascade on delete |
| sku | string, nullable, único | código único de la variante |
| talle | string, nullable | |
| color | string, nullable | |
| nombre | string, nullable | etiqueta libre si no aplica talle/color |
| precio_extra | decimal(14,2), nullable | diferencia de precio respecto del producto base (opcional) |
| activo | boolean | default true |

> El stock (`stocks` / `movimientos_stock`) puede referenciar opcionalmente `variante_id` (nullable):
> si el producto tiene variantes, el stock se lleva por variante+depósito; si no, por producto+depósito.

### `listas_precio`
id, nombre (ej. Mayorista, Minorista, Tarjeta), activo.

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

### `movimientos_stock`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| producto_id | FK → productos | |
| variante_id | FK → producto_variantes, nullable | si el producto tiene variantes |
| deposito_id | FK → depositos | |
| tipo | enum(`entrada`,`salida`,`ajuste`,`transferencia`) | `transferencia` = movimiento entre depósitos (2 filas: salida negativa + entrada positiva). `ajuste`/`transferencia` = ajuste manual. **`salida`/`entrada` = Ventas, desde la spec 012** (`salida` al crear la Venta, `entrada` al eliminarla o reintegrar por edición). **Compras todavía no genera movimientos** |
| cantidad | decimal | |
| origen_type / origen_id | polimórfico | **`Venta` desde la spec 012**; `compra_items` sigue reservado. En los ajustes manuales queda nulo |
| fecha | date | |
| usuario_id | FK → usuarios, nullable | quién generó el movimiento |

> **"Stock Saldo" del Informe de Stock (spec 003, 24/07/2026):** columna **calculada, no persistida** —
> `SUM(cantidad) OVER (PARTITION BY producto_id, variante_id, deposito_id ORDER BY fecha, id)` sobre el
> histórico **completo** de `movimientos_stock` (nunca sobre el subconjunto filtrado por pantalla). Se
> proyecta como columna adicional de `InformeStockController::data()`, análoga a los `addSelect` de
> subconsulta ya usados en `ProductoController::queryFiltrada()` para las columnas dinámicas de lista de
> precio. El filtro "Operación" del informe expone `ajuste`/`transferencia` y —**desde la spec 012**—
> `salida`/`entrada` generados por Ventas. Compras aún no genera movimientos de stock.

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
- Las relaciones polimórficas (`movimientos_stock.origen`) se implementan con `morphTo`/`morphMany` estándar de Eloquent; hoy sólo se usan para ajustes manuales.
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

`ventas.venta_items` y `ventas.conceptos` son análogos a `presupuesto_items`/`presupuesto_conceptos`
(mismas columnas, FK `venta_id`) — no se listan de nuevo.

### `cobros` ("Cobranzas")
id, venta_id (FK → ventas, cascade), fecha (date), cuenta_tesoreria_id (FK → `cuentas_tesoreria` —
**tabla dependiente, ver nota abajo**), monto (decimal(14,2)), nota (text, nullable).

### `notas_credito_debito`
Confirmado contra `help.contagram.com/es/articles/1319041` (24/07/2026) que el alta es un wizard de 2
pasos — el informe con capturas sólo había relevado el paso 1.

id, venta_id (FK → ventas, **nullable** — "Documento que Ajusta"), compra_id (FK → `compras`,
**nullable**, agregado en spec 009 — exactamente uno de `venta_id`/`compra_id` debe estar seteado),
tipo (enum `credito`,`debito`), afecta_stock (boolean, default false), fecha_emision (date), monto
(decimal(14,2)), tipo_comprobante (string, igual al del comprobante original), descripcion (text,
nullable — obligatoria si no afecta stock), impuestos (json, nullable — conceptos de impuesto
aplicados, mismo patrón que `presupuesto_conceptos`). `nota_credito_debito_items` (pivot `producto_id`
+ `cantidad` + `precio`, con flag `origen` = `venta_original`/`nuevo`) sólo si `afecta_stock = true`.

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
009 — exactamente uno de los dos), fecha (date), nro_remito (string, nullable). **Estructura interna
no relevada en detalle** (el informe sólo confirma el botón "Crear Remito"; falta un relevamiento
específico antes de implementar contenido más allá del encabezado).

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
> Clarifications de la spec). Compras **no** usa `etiquetas` (no confirmado en el relevamiento para
> este documento).

### `compras`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| proveedor_id | FK → proveedores | |
| categoria_id | FK → categorias (tipo=`compra`), nullable | autocompletada desde `proveedores.categoria_id` al elegir el proveedor |
| tipo_comprobante | string, nullable | "Tipo" del formulario — sin validez fiscal real sin Facturación Electrónica, mismo patrón que `ventas.tipo_comprobante` |
| nro_comprobante | string, nullable | numeración asociada al Tipo |
| fecha_emision | date | |
| fecha_vto_pago | date, nullable | "Vto. del Pago" |
| servicio_desde, servicio_hasta | date, nullable | |
| mes_imputacion_iva | date, nullable | campo **"Contador"**, exclusivo de Compras (sin equivalente en Ventas) — mes de imputación en el IVA Compras, independiente de `fecha_emision` |
| subtotal_sin_descuento, descuento, subtotal_con_descuento, total | decimal(14,2) | mismo cálculo que `ventas`/`presupuestos`; `total` es un snapshot congelado |
| nota_interna | text, nullable | |
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

## 9. Tablas descartadas (pendientes de re-relevamiento)

Las siguientes tablas existieron en una versión anterior del modelo (spec 003 a 015) y fueron
eliminadas junto con su código porque el relevamiento funcional que las originó no reflejaba con
precisión el negocio real. Se documentarán de nuevo cuando se retome cada módulo:

`empresa`,
`puntos_venta`, `certificados_fiscales`, `comprobantes_fiscales`,
`reportes_email_config`, `importaciones` (la de Contagram, no la
implementada en spec 006), `integraciones`, `integracion_eventos`, `producto_canal`, `ml_ordenes`.

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

### `ml_orden_items`
`ml_orden_id` (FK, cascade), `ml_item_id` (publicación), `ml_variation_id` (nullable — **si viene con
valor, la orden se marca como no soportada**), `titulo`, `sku_vendedor`, `cantidad`, `precio_unitario`
(**precio FINAL con IVA incluido**), `total_linea`, `producto_id` (FK → productos, nullable — se
congela al convertir).

### `ml_publicacion_producto`
Vinculación **estrictamente 1:1**, infraestructura compartida con la spec 013. `ml_item_id` (string,
**unique**), `producto_id` (FK → productos, **unique**), `titulo_ml`, `vinculada_por`. Los dos índices
únicos son los que garantizan la cardinalidad a nivel de datos, no sólo en la UI.

> **Columnas nuevas (spec 013, implementada)** — estado de sincronización de stock CRM → Mercado Libre
> de este vínculo: `stock_pendiente` (bool, default false — con cambios de stock sin empujar todavía),
> `stock_sincronizado_en` (datetime, nullable — último envío exitoso), `stock_error` (string(255),
> nullable — motivo del último rechazo), `stock_error_en` (datetime, nullable). Son el **único estado
> persistente** de la sincronización de stock: no hay tabla de historial propia, los envíos se registran
> en `ml_operaciones_log` (§8). Detalle completo en `specs/013-stock-mercadolibre/data-model.md`.

> **Columnas nuevas (spec 016, implementada)** — mismo patrón que las de stock, para
> precio: `precio_pendiente` (bool, default false — precio vigente en la Lista de Precios configurada sin
> confirmar como enviado, ya sea por un cambio reciente o por un envío que falló), `precio_sincronizado_en`
> (datetime, nullable), `precio_error` (string(255), nullable), `precio_error_en` (datetime, nullable).
> Detalle completo en `specs/016-lista-precio-mercadolibre/data-model.md`.

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

> **Columna nueva (spec 020, implementada)**: `vendedor_id` (FK →
> `vendedores`, nullable, `restrictOnDelete`). "Vendedor por defecto" asignado a las Ventas que se
> crean automáticamente por sincronización de Mercado Libre — mismo patrón que `categoria_venta_id`,
> independiente del default de Tiendanube (sin fallback compartido entre integraciones). Detalle
> completo en `specs/020-vendedores/data-model.md`.

> **Mecanismo de vinculación (spec 021, planificada) — sin columna nueva**: `ml_item_id`/`producto_id`
> se siguen creando igual que hoy, pero el `producto_id` deja de elegirse a mano — se resuelve
> automáticamente comparando `ml_orden_items.sku_vendedor` (más reciente para ese `ml_item_id`) contra
> el **`id`** (clave primaria) de `productos`, no contra `codigo`. Confirmado que no hace falta ningún
> campo nuevo: el negocio va a asignar a propósito ese mismo `id` al dar de alta los productos que hoy
> sólo existen en Mercado Libre. Detalle completo en
> `specs/021-vinculacion-automatica-sku/data-model.md`.

### `ventas` (columna nueva)
`origen` (enum `manual`/`presupuesto`/`mercadolibre`, default `manual`). Explicita el tercer origen;
"Creada Desde" hoy se deriva de `presupuesto_id`.

> **Cálculo clave**: los precios de Mercado Libre son **finales con IVA incluido**, así que el neto se
> obtiene como `precio_final / (1 + iva_pct/100)` usando el IVA del producto vinculado (tasa 0 para
> Exento/No Gravado). La diferencia por redondeo se absorbe en la última línea, para que el total de la
> Venta coincida **exactamente** con el monto de la orden.

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
Vinculación **estrictamente 1:1** entre una variante de Tiendanube y un producto del CRM — equivalente a
`ml_publicacion_producto` (§10), pero por **variante**, no por publicación, porque la API de Tiendanube
siempre expone `variant_id` por línea. `variant_id` (**unique**), `producto_id` (FK → productos,
**unique**), `nombre_variante_tn`, `vinculada_por`. Los dos índices únicos garantizan la cardinalidad.

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

**Mecanismo de importación masiva (spec 021, planificada) — sin columna nueva**: además del alta
manual ya existente, `variant_id`/`tn_product_id`/`producto_id` se pueden crear en lote importando el
archivo de productos que Tiendanube ya exporta. `producto_id` se resuelve por `productos.codigo` (match
exacto o por el número inicial); `variant_id`/`tn_product_id` se resuelven consultando el catálogo de
Tiendanube en vivo (tool `list_products` del MCP ya conectado) por el "Identificador de URL" de cada
fila del archivo — el SKU de Tiendanube nunca viaja por esa integración, sólo por el archivo. Detalle
completo en `specs/021-vinculacion-automatica-sku/data-model.md`.

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
