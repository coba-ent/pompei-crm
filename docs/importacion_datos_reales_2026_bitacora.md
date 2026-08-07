# Bitácora: importación de datos reales al VPS (agosto 2026)

Sesión larga y continua (no se corta hasta terminar). Este documento se actualiza a medida que
avanza — es la fuente de verdad de qué se hizo, qué se decidió y qué falta. Todo el trabajo es
**sobre la base del VPS** (`contagram` en `46.202.146.102`, ver `CREDENCIALES_ACCESO.txt`), no local.

Contexto: el VPS venía con datos de prueba (ver `docs/../` memoria "Limpieza datos de prueba").
Se vació todo lo transaccional (05/08/2026) y desde entonces se está re-importando el catálogo y
el histórico real del negocio, módulo por módulo.

## Estado general (ir tildando)

- [x] Productos (`productos.xlsx`) — importado
- [x] Categorías de gastos (`Gastos categorias.xlsx`) — importado
- [x] Clientes (`clientes.xlsx`) — importado
- [ ] Ventas 2026 (`2026 Ventas c_ cobro.xlsx`) — **en análisis, NO importado todavía**
- [ ] Cobros detallados por venta — pendiente, el usuario va a pasar una hoja aparte
- [ ] Limpieza de clientes duplicados — pendiente, se hace DESPUÉS de importar ventas (matchean por nombre)

## Backups hechos en el VPS (por si hay que restaurar)

- `/root/backup_contagram_pre_limpieza_20260805_021044.sql` — antes de vaciar tablas de prueba (26M)
- `/root/backup_contagram_pre_import_productos_20260805_115147.sql` — antes de importar productos (715K, también descargado a la raíz del repo local)
- `/root/backup_contagram_pre_import_clientes_20260805_230251.sql` — antes de importar clientes (12M)
- `/root/backup_contagram_pre_import_clientes_nuevos_20260806.sql` — antes del incremental de clientes nuevos del 06/08

## 3bis. Clientes nuevos incremental (06/08/2026)

Re-export `public/imports/ACTUALIZADOS/Listado de Clientes 06-08-2026 0016 Hs.xlsx` (19.364 filas,
mismo formato/columnas que el original). Comparado por nombre exacto (`clientes.nombre`) contra los
19.257 clientes ya en el VPS: **108 filas nuevas**, con Id de origen 19228-19335, todas contiguas al
final del archivo (altas hechas en Contagram después del export del 05/08). Mismo mapeo de columnas
que `scripts/import_clientes_masivo.php`; condiciones de IVA de las 108 filas matchearon 100% sin
necesidad de "No Categorizado". Script: `scripts/import_clientes_nuevos_20260806.php` (correr con
`php artisan tinker <archivo> -n`, NO con `< archivo` — pipeado a stdin tinker tira un parse error
falso en psysh; con `include file` anda bien).

**Resultado:** 108 clientes creados, total 19.365 en la tabla. No se tocaron los duplicados
existentes (sigue pendiente la limpieza post-ventas, ver más abajo).

---

## 1. Productos (`public/imports/productos.xlsx`)

Origen: 9.197 filas, 34 columnas (export viejo de Contagram). Análisis completo con la skill xlsx.

**Problemas encontrados y resueltos:**
- 89 filas con `Id` duplicado (38 valores repetidos). El usuario notó que los primeros 5 caracteres
  del `Código` son el Id real en el 98.5% de los casos — se usó eso, bajó el conflicto a 10 filas.
- 15 filas con `Id=0` (placeholders tipo "Costo de envío", marmolería a medida) → **eliminadas**.
- De las 10 filas restantes con conflicto tras la corrección de Id, 8 se resolvieron solas
  (duplicados reales tipo "Generico" vs real, o typos de Código con un dígito de más/menos que el
  propio usuario corrigió a mano en el Excel).
- Quedó **1 colisión real irresoluble**: `Id=44804`, dos productos Peirano distintos (`BACHA EMB
  COCINA...` vs `LAVATORIO NICKEL...`) con el mismo Id de origen y sin forma de distinguirlos. Se
  **excluyeron** a `productos_excluidos_id44804.xlsx`, pendientes de decisión del usuario.
- Fila basura `Id=30622` ("99999 30622 30622", Costo/Precio $0) → eliminada.
- 5 filas con `Tipo de Producto/Servicio` vacío → completadas a mano por inferencia de
  proveedor/contexto (`Repuestos` para 2 de RAO, `Mano de obra` para ENVIO y una colocación
  Mauricio, `Bacha de baño` para un zócalo de Marmolería Muñiz, siguiendo el patrón de esos
  proveedores en el resto del archivo).
- Columna `Descripción` del Excel = Nombre+Código concatenados por el propio export (ruido, no
  texto real) → se descartó, `productos.descripcion` queda `null`.
- Costo/Precio en $0 (153 filas) y stock negativo (64 filas) → se importaron tal cual, decisión
  explícita del usuario (no son error, son productos sin precio cargado / sin control de stock).

**Mapeo de columnas → decisiones de catálogo:**
- `Tipo de Producto/Servicio` del Excel → `productos.tipo_producto_id` (NO `categoria_id`, que no
  existe en el schema real de `productos` — sólo existe en `clientes`/`proveedores`/`ventas`/etc.).
  La tabla `tipos_producto` ya venía sembrada con las categorías tipo Contagram (Griferías,
  Repuestos, Bacha de baño...) MEZCLADAS con el concepto real de "Tipo de Producto" (Compra y
  Venta, Consignación, Fabricado, Insumo) — son conceptos distintos que conviven en la misma tabla,
  raro pero es como está hecho. Faltaban 2 valores del Excel: `Bacha cocina` y `Griferia de
  cocina` → creados a mano antes de importar.
- **Depósitos**: el Excel trae stock en 3 columnas (`Local`, `Depósito Tiendanube`, `Full`=ML
  Fulfillment). La base sólo tenía `Principal` y `Depósito Tiendanube`. Decisión: **se borró
  "Principal"** (sin stock ni config que dependiera de él) y se crearon `Local` y `Full` para que
  coincidan 1 a 1 con las columnas del Excel. Depósitos finales: id2=Depósito Tiendanube, id5=Local,
  id6=Full.
- **Proveedores**: los 31 proveedores distintos del Excel **ya existían todos exactos** en la base
  (145 cargados) — 0 altas nuevas. Ojo, el primer conteo por diferencia de cantidad (31 vs 145) fue
  engañoso, el usuario preguntó "¿seguro que no existen?" y el match exacto dio 31/31. Lección: no
  asumir "faltan" sin comparar string exacto.
- **Punto Reposición**: quedó modelado como una `lista_precio` más (id 14, ya existía así en la
  base) en vez de una columna propia de `productos` — decisión del usuario: no tocar schema, se
  mantiene así aunque conceptualmente sea raro (no es un precio).
- Columnas descartadas por decisión del usuario: `ML C/IVA` y `ML Premium C/IVA` (no tenían lista
  de precio creada, se decidió no crearlas y no importar esos 2 precios).
- `Imagen` / `Sincronizado con Tiendanube` del Excel: descartadas, no traen dato real (sólo Si/No
  sin URL/estado real).

**Resultado del import:** 9.179 productos, 9.178 con proveedor (falta sólo "ENVIO", que es
servicio y no corresponde), 9.179 con categoría, 110.131 filas en `precios_producto`, 27.443 en
`stocks`. Script: `scripts/import_productos_masivo.php` (corrido vía tinker en el VPS).

### 06/08/2026 — Actualización de stock/precios + nueva normativa de depósito único

El usuario pasó una hoja nueva (`public/imports/ACTUALIZADOS/Listado de Productos y Servicios
06-08-2026 0122 Hs (1).xlsx`, mismo formato original de 9.197 filas) con stock y precios
actualizados. **No son productos nuevos** — nueva normativa del negocio: de ahora en más sólo se
usa el depósito **"Local"**; se decidió:
- Borrar depósito `Depósito Tiendanube` (y su stock).
- Desactivar `Full` (`activo=false`) y vaciarle el stock.
- Todo el stock queda en `Local` (Stock Total == Local a partir de ahora).

**Cruce por código** (no por Id, que sigue siendo poco confiable en el Excel crudo):
- 9.142 matchean exacto por `codigo`.
- **36 matchean por el sufijo del código pero con el número inicial distinto** — mismo cluster de
  accesorios FV de la limpieza original; Contagram renumeró esa tanda en su sistema real después
  del export viejo que usamos. Se actualizó también `codigo` al valor nuevo (decisión del usuario).
- 1 caso especial: producto Id 7116 ("KIT BACHA CON MUEBLE DE COLGAR - PERSIS") tenía `codigo`
  corrupto en la base (mezclado con el sufijo de otro producto), pero el `nombre` ya traía el
  código correcto embebido — matcheado por Id, código corregido de yapa.
- **Confirmado: la colisión de Id 44804 (ver sección de productos arriba) ya está resuelta del
  lado de Contagram** — "BACHA EMB COCINA" ahora es `44926 BAI01G` (Id/código nuevo), "LAVATORIO
  NICKEL" sigue `44804 60-100NI`. Ya no colisionan → se importaron como 2 productos nuevos.
- Los otros 3 productos excluidos en la limpieza original (Soporte Generico `26219`, Alacena
  Moderna `27203`, Bacha Maral `B703`) **siguen con `Id=0` en el propio Contagram** — no se
  resolvieron del lado de ellos tampoco, se mantienen afuera.
- El producto basura `30622 30622` que habíamos borrado **ya no existe** en este export nuevo,
  confirma que estaba bien eliminarlo.

**Gotcha post-ejecución**: el contador de "códigos actualizados" dio 7.663 en vez de 37 esperados.
Investigado: no fue un bug de datos — el `codigo` original se había guardado SIN recortar espacios
en blanco al final (arrastrado del import original), y la comparación contra el Excel nuevo (ya
recortado) detectó esa diferencia de whitespace en ~7.663 filas. Efecto colateral positivo (limpió
espacios sobrantes), contenido real sin cambios salvo los 37 casos genuinos. Verificado con
`SELECT COUNT(*) FROM productos WHERE codigo REGEXP ' $'` → 0 después del update.

**Resultado:** 9.181 productos totales (9.179 actualizados + 2 nuevos), 9.150 con stock en Local
(el resto son servicios, correctamente sin stock), 0 stock fuera de Local, 1 solo depósito activo.
Script: `scripts/actualizar_productos_stock_precios.php`. Backup previo:
`/root/backup_contagram_pre_actualizar_productos_20260806_093044.sql`.

### ⚠️ 06/08/2026 — BUG CRÍTICO encontrado y corregido: `productos.id` no coincidía con el SKU real de Mercado Libre

**Causa:** al importar productos (`scripts/import_productos_masivo.php`) se dejó que `productos.id`
se generara por `auto_increment` (1, 2, 3...) en vez de preservar el Id original derivado del
`código` (que sí se había preservado correctamente en el propio Excel, ver sección de arriba). El
Id de producto en Contagram **es el mismo valor que Mercado Libre guarda como `SELLER_SKU`** en
cada publicación — al no preservarlo, la vinculación automática de productos ML
(`VinculadorAutomatico`, que hace `Producto::find((int) $sku)`) daba **0 matches de 271**
publicaciones reales. El usuario lo detectó probando vincular productos en la app.

**Verificación antes de arreglar (pedido explícito del usuario, no asumir sin chequear contra la
API real):** se escribió `scripts/verificar_skus_ml_vs_ids.php` — recorre el catálogo real del
vendedor conectado en ML (mismo método que usa `VinculadorAutomatico`) y compara cada
`SELLER_SKU` contra el `productos.id` actual y contra el que resultaría del fix, **sin escribir
nada**. Resultado: 0/271 con el Id roto, 270/271 con el Id corregido (el único que no matchea
tiene SKU literal `"AAA"`, no numérico — error de origen en ML, no relacionado a este fix).

**Fix aplicado:**
1. Se encontró y corrigió 1 typo residual de código (`444010 755 CR 10X` → `44410 755 CR 10X`,
   producto Id 8704) que iba a arruinar la unicidad del remapeo.
2. Se remapeó `productos.id` de 9.181 filas al Id real (derivado de `codigo`, primer token
   numérico) vía `UPDATE ... JOIN` con `id_map` temporal y `FOREIGN_KEY_CHECKS=0`.
3. Se actualizó `precios_producto.producto_id` y `stocks.producto_id` en cascada con el mismo
   mapeo, para que seguir apuntando al producto correcto.
4. `AUTO_INCREMENT` de `productos` reseteado a 100000 (por encima del máximo real, 99999).

**Verificado después:** 270/271 SKUs de ML matchean contra el `productos.id` corregido. Backup
previo: `/root/backup_contagram_pre_fix_ids_productos_20260806_101941.sql`.

**Lección para no repetir:** cuando un campo de import es un identificador de negocio usado como
clave externa por una integración (ML/TN), **nunca dejar que auto_increment lo pise** sin
preguntar primero si hay una dependencia externa que lo requiera — a diferencia de `clientes`
(donde sí era seguro dejar auto_increment porque el matching con ventas es por nombre, no por
Id), acá el Id SÍ era una clave de negocio crítica y activa.

### ⚠️ 06/08/2026 — Corrupción de costo en 251 productos (bug de la app, ya arreglado)

Mientras se probaba la vinculación ML (con el Id ya corregido), el usuario detectó que 251
productos tenían `costo` con valores absurdos (2-3 órdenes de magnitud más altos, ej. $2.946.533
en vez de $9.967,05). **No fue causado por nuestros scripts de import** — se confirmó cruzando
`productos.updated_at` (12:33 y 15:37-15:42 del 06/08, en tiempo real mientras se investigaba) y
el historial de git del propio proyecto:

- Commit `865ea8b` (11:54): agregó reimport de productos por Id actualizando precio/stock.
- Commit `0e6b340` (14:12): **causa raíz** — el export de Productos generaba CSV en texto plano;
  un roundtrip CSV → Google Sheets/Excel → reimport reinterpreta mal el separador decimal/miles
  según la config regional, inflando los precios 2-3 órdenes de magnitud. Se arregló generando
  XLSX con celdas numéricas tipadas (inmune a esa reinterpretación).
- La corrupción de 251 productos ocurrió en dos tandas alrededor de esos commits (alguien probó
  el import/export nuevo con un archivo que pasó por ese roundtrip antes del segundo fix).

**Verificado**: el commit desplegado en el VPS al momento de la corrección (`4f607c8`, 16:01) ya
incluye ambos fixes (`git merge-base --is-ancestor` confirma que son ancestros) — el bug está
resuelto de raíz en el código, no debería repetirse.

**Fix de datos aplicado**: se restauró `costo` de los 251 productos afectados cruzando
`productos.id` contra el Excel de referencia (`Listado de Productos y Servicios 06-08-2026...`),
vía tabla temporal `tmp_costos_restaurar` + `UPDATE ... JOIN`. Verificado post-fix contra un
producto de muestra (Id 42782: $2.946.533 → $9.967,05 correcto).

**Segunda ronda de verificación (mismo día)**: el usuario pidió chequear TODOS los productos
contra un nuevo export (`Listado de Productos y Servicios 06-08-2026 0122 Hs (2) (1).xlsx`,
descargado en Downloads). Se encontró que `precio_venta` y 10 de las 12 listas de
`precios_producto` también estaban mal en **52-57 productos**, todos dentro del mismo cluster de
accesorios FV renumerado (Id 44454-44520) — este SÍ era un bug propio del script
`actualizar_productos_stock_precios.php` (mismatch de fila al matchear por sufijo de código en
ese cluster), no del bug de la app. Las listas **`ML` y `ML Premium`** mostraban diferencia
grande contra el Excel pero **el usuario confirmó que las administra manualmente y están bien
así** — no se tocaron.

**Fix aplicado**: `scripts/restaurar_precios_no_ml.php` — restaura `precio_venta` + las 10 listas
(todas menos ML/ML Premium) de los 9.180 productos matcheados, matcheando por el Id derivado del
`código` (no por la columna `Id` del Excel, que sigue siendo poco confiable para el mismo cluster
renumerado). Backup previo: `/root/backup_contagram_pre_restaurar_precios_*.sql`. **Verificado
post-fix: 0 diferencias** en las 10 listas contra el Excel (91.784 filas comparadas). Único
residual: Id 44410 (el typo `444010` corregido antes) no matcheó porque este export nuevo trae el
typo viejo sin corregir del lado de Contagram — 1 sola fila, no relevante.

---

## 2. Categorías de gastos (`public/imports/gastos/Gastos categorias.xlsx`)

Estructura simple: 8 categorías en columnas, subcategorías debajo de cada una. 90 pares
categoría/subcategoría en el Excel, se excluyó 1 subcategoría basura ("Prueba" bajo "Empleados"),
quedaron **89 subcategorías**.

De paso se aprovechó para borrar 4 categorías de prueba que ya estaban en la base de sesiones
anteriores de testing (`Prueba` tipo venta, `fdghdfgh` tipo ingreso, `Articulos de Limpieza` +
`pruebas` tipo gasto).

**Resultado:** 8 categorías padre + 89 subcategorías = 97 filas en `categorias` (tipo=`gasto`).
Script: `scripts/import_categorias_gastos.php`.

---

## 3. Clientes (`public/imports/clientes.xlsx`)

19.256 filas, 24 columnas. Bastante más sucio que productos:
- 57 filas con `Id` duplicado (26 grupos) — la mayoría (54) eran duplicados exactos por doble-submit
  (mismo cliente cargado 2-3 veces con milisegundos de diferencia). 372 filas con `CUIT` duplicado
  pero **nombre distinto** en 153 de esos 157 CUITs (probable misma persona con apodo/teléfono
  distinto cada carga). 3 clientes basura marcados a mano por el negocio (`NO USAR MAS`, `NO USAR
  MAS 2`, `BORRAR NEGATIVOS FV`).

**Decisión del usuario (clave para todo lo que sigue): NO limpiar nada ahora.** Se importan las
19.256 filas tal cual, sin fusionar duplicados ni borrar los 3 marcados basura. La limpieza se hace
**después de importar las ventas**, porque el Excel de ventas asocia clientes **por nombre** (no
por Id) — primero hay que tener las ventas asociadas para no perder esa trazabilidad al fusionar.

**Gotcha encontrado en el import:** la tabla `clientes` en el VPS **ya no estaba vacía** al momento
de correr el import — apareció una fila real (`id=1`, "TANIA 1157822317") creada por el cron de
sync de Mercado Libre/Tiendanube, que sigue activo en el VPS mientras se trabaja. Esto rompió el
primer intento (que insertaba con el `Id` explícito del Excel, chocó con PK). Se resolvió dejando
que MySQL asigne `auto_increment` en vez de forzar el Id viejo del Excel (no hace falta preservarlo
porque el matching futuro con ventas es por nombre). **Consecuencia**: "TANIA 1157822317" quedó
duplicada (`id=1` la real + `id=2191` la del Excel importado) — ejemplo típico a fusionar en la
limpieza pendiente.

**Otro gotcha:** 27 filas tenían un CUIT completo (con guiones, 13 caracteres) cargado por error en
la columna `DNI` del Excel — reventaba el `varchar(11)` de `clientes.cuit`. Se detecta por longitud
tras sacar los guiones (si quedan 11 dígitos, se guarda como CUIT aunque haya venido en la columna
DNI) — ver `scripts/import_clientes_masivo.php`.

**Mapeo de columnas:**
- `DNI`/`CUIT` → un solo campo genérico `clientes.cuit` (varchar 11) + `tipo_documento` ('CUIT' o
  'DNI'). Nunca vienen los dos a la vez en el Excel.
- `Condición de IVA` vacía (49% de los casos) → mapeada a "No Categorizado" (id 5 en
  `condiciones_iva`), decisión explícita del usuario en vez de dejar null.
- `Observaciones` → `clientes.nota` (nota interna), NO `nota_cliente` (que es la nota de cara al
  cliente en ventas, campo distinto en el schema).
- `Usuario de Mercado Libre` → `apodo_ml` (texto libre), NO `ml_user_id` (que es el id numérico
  real de la cuenta ML vía API, no aplica a un import de Excel).
- `Creado` del Excel → `created_at` (se preserva la fecha real de alta del sistema viejo).
- No hay columna de `categoria_id` / `lista_precio_id` en el Excel de clientes → quedan null.

**Resultado:** 19.256 clientes creados (+ 1 que ya existía por sync real = 19.257 total en la
tabla). 100% con condición de IVA asignada. Script: `scripts/import_clientes_masivo.php`.

---

## 4. Ventas 2026 (`public/imports/ventas/2026 Ventas c_ cobro.xlsx`) — EN CURSO, no importado

3.529 filas, 50 columnas, **una fila por venta completa** (no por ítem — los productos vienen
concatenados en una sola celda `Productos` con guiones separando cada línea).

### ⚠️ HALLAZGO IMPORTANTE: formato incompatible con el importador ya existente

Ya existe `app/Console/Commands/ImportarVentasHistoricas.php` (documentado en
`docs/importacion_ventas_historicas.md`), que el 03/08/2026 importó con éxito 2021-2025 (18.598
ventas) desde `public/imports/ventas/Ventas {año}.xlsx`. **Ese formato es distinto**: trae una fila
POR ÍTEM (`Cantidad`, `Precio Unitario`, `Producto/Servicio`, `Código` por línea, `Tipo de
Comprobante` FCA/FCB/FCC), matchea cliente por nombre exacto, producto por código, etc. — toda esa
lógica de matching ya está resuelta y probada.

El archivo 2026 (`2026 Ventas c_ cobro.xlsx`) es un **export distinto** (resumen por comprobante,
con columnas nuevas que 2021-2025 no tenía: `ARCA`, `Estado`, `Cobrado`, `A Cobrar`, `Medio de
Cobro`, `Etiquetas`). **Antes de armar el import de 2026 hay que decidir**: ¿se consigue el mismo
formato "por ítem" que usan los años anteriores (reutilizando el comando tal cual), o hay que
extender/adaptar el comando para parsear el formato resumen (parseando el texto de `Productos`,
lo cual es más frágil porque no trae precio/cantidad por línea, sólo el nombre)? **No decidido
todavía.**

### Tema 1: no duplicar órdenes de Mercado Libre ya convertidas

Preocupación del usuario: el Excel de ventas 2026 ya incluye ventas que se originaron como órdenes
de ML, y esas mismas órdenes están sincronizadas en la tabla `ml_ordenes` del VPS (57 filas, todas
`estado_conversion='requiere_atencion'`, `venta_id=null`, porque el cron de ML sigue activo tomando
pedidos reales mientras trabajamos). Si no se vinculan antes, el flujo tradicional de conversión
(cuando el negocio instale la app) las va a convertir de nuevo → venta duplicada.

**Método de matching (aprendido en el camino, por prueba y error):**
1. El apodo de ML (`ml_ordenes.comprador_apodo`) casi nunca se parece al nombre real → matchear
   sólo por apodo+monto da **falsos positivos graves** (se descartó esa primera pasada).
2. El Excel de ventas trae el nombre real del cliente en texto libre (a veces con el apodo de ML
   pegado, a veces no).
3. La forma confiable es conseguir un **export de Contagram con Nombre+Apellido reales + Orden ID
   exacto** ("Ordenes de Mercado Libre" del propio Contagram, pantalla `mercadolibre/orders`,
   filtro de fecha arriba a la derecha + botón "Exportar"). Con ese archivo
   (`Listado de Ordenes de mercado libre 05-08-2026 2346 Hs.xlsx`, 49 filas, rango 01/08-05/08):
   - 42 de las 49 matchean por Orden ID exacto contra `ml_ordenes` del VPS.
   - De esas 42, 31 están en estado "Venta" (ya cerradas del lado ML) → **las 31 matchean 100%**
     contra el Excel de ventas por nombre+monto (con tolerancia de $0,50 por redondeo). 11 son
     "Pendiente" → correctamente sin match (no son venta todavía).
   - 6 órdenes que YA están en "Venta" en Contagram **no aparecen en `ml_ordenes` del VPS** — el
     sync se las perdió (no investigado el motivo todavía, no es prioridad para el usuario).
4. **Quedan 15 órdenes viejas en `ml_ordenes` (22/07 al 31/07) sin cubrir** por ese export de 49
   filas (que sólo llega hasta 01/08). Para esas sólo hay apodo, no nombre real.
   - Se probó combinar monto+SKU (`ml_orden_items`)+fecha+apodo, TODO junto: sólo resuelve **5 de
     las 15** sin ambigüedad.
   - Las otras **10 son genuinamente irresolubles con los datos disponibles**: son el mismo
     producto (código `27198`, "Botiquín Tríptico 60x60 Blanco") vendido al mismo precio exacto a
     docenas de clientes distintos en la misma semana — ninguna combinación de monto+producto+fecha
     alcanza para diferenciarlas, y el apodo de ML muchas veces no se parece en nada al nombre real
     (son alias inventados de la cuenta, ej. `DAOT132397` = Darío Otero).
   - **Solución pendiente**: pedirle al usuario que reexporte el mismo listado de Contagram con
     rango de fechas ampliado (22/07 en adelante) para tener nombre real de esas 15 también. Todavía
     no lo hizo.

**Estado actual del cruce: ~45-50 de 57 órdenes ML resueltas con certeza. Quedan ~10 sin resolver
sin el export ampliado.**

### Tiendanube — mismo problema que ML pero resuelto al 100% (mucho más simple)

Sólo **4 órdenes** en `tn_ordenes` (vs 57 de ML). A diferencia de ML, `tn_ordenes` trae
`comprador_nombre` real directo (no un apodo), así que el matching fue directo por nombre+monto,
sin ambigüedad:

| Orden TN | Cliente | Monto | Fecha | Estado TN | Match en Excel ventas |
|---|---|---|---|---|---|
| 2021422587 | Alejandro viva | $180.861,88 | 17/07 | open | ✅ Excel Id 23842 (categoría "Web") |
| 1924064728 | Alejandro viva | $202.936,69 | 17/07 | **closed/cancelada** | No aplica, nunca fue venta |
| 2032274136 | Pompei Sanitarios | $262.252,00 | 30/07 | open | ❌ Sin match — pedido de prueba interno (email = cuenta propia del dueño, `pompei2sanitarios@gmail.com`) |
| 2032024787 | Leandro gorosito | $135.591,00 | 30/07 | open | ❌ Sin match — demasiado reciente, no facturado todavía en el sistema viejo |

**Sólo 1 orden (`2021422587`) necesita vincularse** a la venta Id 23842 para que el flujo
tradicional no la duplique. La cancelada no aplica. Las otras 2 se pueden convertir normal sin
riesgo (genuinamente no tienen factura todavía). **Tiendanube: tema cerrado, sin pendientes.**

### Tema 2: cobros y cuentas de tesorería

- `cuentas_tesoreria` tenía 10 cuentas. El Excel usa 6 "medios de cobro" que no tenían cuenta
  creada: `PAYWAY QR` (137 usos), `Mastercard` (100), `Juan USD Personal` (17, cuenta personal del
  dueño usada a veces para cobros), `Banco Credicoop` (13), `Galicia` (1, típo/abreviación de
  "Banco Galicia" que ya existe), `Retenciones` (1, no es una cuenta real). **Ya se crearon** las 4
  cuentas nuevas (`PAYWAY QR`, `Mastercard`, `Juan USD Personal`, `Banco Credicoop`) — quedan 14
  cuentas de tesorería en total. `Galicia` mapea a `Banco Galicia` existente cuando se cargue.
- El campo `Medio de Cobro` es texto libre, a veces con varios medios separados por `" - "`
  (ej. `"Mercado Pago - Caja del Local"`). 3.199 ventas tienen 1 solo medio (sin ambigüedad). De las
  229 con combo, 138 son el mismo medio repetido (pagos parciales al mismo destino, sin problema) y
  **91 son medios realmente distintos combinados, sin desglose de cuánto fue a cada uno** — el
  Excel sólo trae el total cobrado, no el split.
- **Decisión pendiente sobre esas 91**: quedó sin resolver porque el usuario aclaró que **mañana le
  van a pasar una hoja de cobros detallada por cada venta** — con eso se va a poder importar el
  detalle real de `cobros` (fecha, cuenta, monto) sin tener que inventar un reparto. Por ahora esto
  NO se resuelve con la hoja de ventas sola.

### Resumen de decisiones tomadas sobre ventas 2026 hasta ahora

1. Catálogo de cuentas de tesorería: **completo** (14 cuentas).
2. Matching ML: **en curso**, falta el export ampliado (22/07 en adelante) para cerrar el 100%.
3. Matching Tiendanube: **cerrado al 100%**, sólo 1 orden (2021422587) para vincular a venta Id 23842.
4. Cobros detallados: **se esperan mañana en una hoja aparte**, no se resuelven con este Excel.
5. Formato del Excel de ventas 2026 vs el importador existente: **incompatible tal cual**, falta
   decidir cómo conciliar (ver hallazgo arriba).
6. Todavía no se hizo el análisis estructural completo de calidad de datos de este Excel (nulos,
   duplicados, etc.) como sí se hizo con productos/clientes — quedó pausado por los temas 1 y 2.

## Próximos pasos (en orden)

1. Pedir al usuario el export ampliado de "Listado de Ordenes de Mercado Libre" (22/07 en
   adelante) para cerrar el matching de las 15 órdenes viejas.
2. Decidir formato de import de ventas 2026 (¿conseguir el export "por ítem" como 2021-2025, o
   adaptar el comando al formato resumen?).
3. Hacer el análisis de calidad de datos completo de `2026 Ventas c_ cobro.xlsx` (duplicados,
   nulos, columnas raras) antes de importar, mismo nivel que se hizo con productos/clientes.
4. Importar ventas 2026, vinculando las órdenes ML resueltas (`ml_ordenes.venta_id` +
   `estado_conversion='convertida'`) para que el flujo tradicional no las duplique.
5. Cuando llegue la hoja de cobros detallada: importar `cobros` con cuenta/monto/fecha reales.
6. Limpieza y fusión de clientes duplicados (recién ahí, con ventas ya asociadas).
