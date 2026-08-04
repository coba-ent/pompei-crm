# Data Model: Reorganización de Configuración & Ajustes

## Entidad nueva: `ConfiguracionVentas`

Fila única (single-tenant, mismo patrón que `datos_empresa`). Tabla `configuracion_ventas`.

| Campo | Tipo | Nullable | Descripción |
|---|---|---|---|
| `id` | bigint PK | — | — |
| `categoria_id` | bigint FK → `categorias.id` | sí | Categoría de venta preseleccionada por defecto en "Crear Venta" |
| `vendedor_id` | bigint FK → `vendedores.id` | sí | Vendedor preseleccionado por defecto |
| `lista_precio_id` | bigint FK → `listas_precio.id` | sí | Lista de Precios preseleccionada por defecto (si es null, sigue aplicando el fallback actual "Principal") |
| `tipo_comprobante` | enum(`A`,`B`,`C`,`E`) | sí | Tipo de Comprobante preseleccionado por defecto (si es null, sigue aplicando el fallback actual "B") |
| `dias_vto_cobro` | unsigned smallint | sí | Días a sumar a la fecha de Emisión para precalcular "Vto. del Cobro" (si es null, el campo se deja vacío, igual que hoy) |
| `created_at` / `updated_at` | timestamp | — | — |

**Validaciones**:
- `dias_vto_cobro`: entero, `>= 0`, sin tope superior impuesto por el sistema (validación de negocio, no técnica).
- `categoria_id` / `vendedor_id` / `lista_precio_id`: si se envían, deben existir en su catálogo respectivo al momento de guardar la configuración (validación estándar `exists:`). Una vez guardados, si el registro referenciado se borra después, el consumo en `VentaController@create` debe tolerarlo (ver Regla de aplicación).
- Es una fila única: se usa `firstOrNew()`/`updateOrCreate([], [...])` — no hay alta de múltiples filas ni endpoint de borrado de la configuración en sí (sólo "guardar", que puede limpiar un campo a null).

**Relaciones**:
- `belongsTo(Categoria::class)`, `belongsTo(Vendedor::class)`, `belongsTo(ListaPrecio::class)` — todas opcionales (nullable FK, sin `nullOnDelete` estrictamente necesario porque el controlador ya debe verificar existencia antes de aplicar el default; se recomienda igual `nullOnDelete()` en la migración para que la fila de configuración no quede con una FK huérfana).

## Regla de aplicación en "Crear Venta" (no persiste nada nuevo en `Venta`)

`VentaController@create`, sólo cuando `!$venta && !$presupuestoOrigen` (alta nueva, no edición, no
conversión desde presupuesto):

1. Carga `ConfiguracionVentas::first()` (puede no existir fila todavía → tratar como todos los campos null).
2. Para cada uno de `categoria_id`, `vendedor_id`, `lista_precio_id`: si el default está seteado y el
   registro referenciado sigue existiendo (y activo, si aplica `activo`/`es_sistema`) en su catálogo, se
   pasa a la vista como preselección; si no, se pasa como si no hubiera default (mismo comportamiento que
   hoy sin configurar nada).
3. Para `tipo_comprobante`: si está seteado, reemplaza el hardcodeo actual "B" como valor inicial del
   `<select id="f-tipo-comprobante">`. Este valor inicial es igualmente sobrescribible por
   `cliente.tipo_comprobante_defecto` cuando el usuario elige un Cliente (comportamiento ya existente en
   `resources/js/ventas.js:414`, no se modifica ese orden de prioridad).
4. Para `dias_vto_cobro`: si está seteado, se calcula `fecha_vto_cobro = hoy->addDays(dias_vto_cobro)` y
   se pasa a la vista para precargar `#f-fecha-vto-cobro` (hoy ese campo se carga vacío).
5. No se persiste nada en la tabla `ventas` por este mecanismo — es sólo precarga de formulario; la
   Venta guardada refleja lo que quedó en el formulario al momento de "Guardar" (igual que hoy).

## Entidad existente sin cambios de esquema: `DatosEmpresa` (tabla `datos_empresa`)

Sin cambios de campos. Cambia únicamente el rótulo de pantalla ("Mi Perfil" → "Empresa") y qué otra
información se muestra en la misma vista (sección de usuarios).

## Entidad existente sin cambios de esquema: `FuncionAvanzada` (tabla `funciones_avanzadas`)

Sin cambios de campos. Se reutilizan los campos ya existentes `clave`, `activa` e `icono` para decidir,
en la nueva pantalla `configuracion/index.blade.php`, qué tabs además de "Funciones Avanzadas" y "Ventas"
se muestran: el tab de Depósitos/Mercado Libre/Tiendanube/Facturación Electrónica sólo aparece si
`FuncionAvanzada::activa('depositos'|'mercadolibre'|'tiendanube'|'facturacion_electronica')` es `true`,
y usa el mismo `icono` (clase Font Awesome) ya guardado en esa fila para el ícono del tab.

## Entidad existente sin cambios de esquema: `Rol` / relación `User::roles()`

Sin cambios. Se reutiliza `User::esAdmin()` (ya implementado) como única condición de acceso. No se
agrega ningún campo ni tabla nueva para el gate de Admin.
