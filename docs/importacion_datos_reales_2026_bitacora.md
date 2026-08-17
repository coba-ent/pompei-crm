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
- [x] **Archivos de origen: COMPLETOS Y VALIDADOS (10/08/2026)** — ver más abajo
- [ ] Ventas 2021-2026 — archivos listos, **falta escribir el importador**
- [ ] NC/ND — archivos listos (vienen con detalle en el export por ítem)
- [ ] Cobros — archivos listos (91,2% vinculable a su venta)
- [ ] Compras + pagos — archivos listos (pagos 99,9% vinculables)
- [ ] Gastos — archivos listos (9.394)
- [ ] Limpieza de clientes duplicados — pendiente, se hace DESPUÉS de importar ventas (matchean por nombre)

### ✅ 10/08/2026 — TODOS LOS ARCHIVOS DE ORIGEN ESTÁN COMPLETOS Y VALIDADOS

| Fuente | Volumen | Validación |
|---|---|---|
| Ventas por ítem (6 años) | 23.563 ventas / 37.100 líneas | 99,94% reconcilia con el `c/ cobro` |
| Ventas c/ cobro (6 años) | totales, estados, NC/ND | control: $1.570.665.960,38 |
| Compras por ítem (6 años) | 2.526 comprobantes / 11.871 líneas | los 6 en formato canónico |
| Cuentas / tesorería | 48.222 movimientos, 23 cuentas | 0 celdas `######` |
| — cobros | 25.086 | **91,2%** vinculable a su venta |
| — pagos | 1.838 con referencia | **99,9%** vinculable a su compra |
| Gastos (6 años) | 9.394 | totales de control exactos |

**Encabezados por archivo** (para el lector del importador): Compras 2021, 2025 y 2026 tienen el
encabezado en la **fila 7** (bloque de resumen arriba); Compras 2022-2024 en la fila 1. Ventas 2023
tiene el **header al final** del archivo. Gastos 2021-2024 son informe agrupado; 2025-2026 planos.

**Único pendiente del usuario:** las 2 ventas dudosas de `public/imports/revision_duplicados_ml.xlsx`.

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

### 07/08/2026 — Compras (`public/imports/Compras/`): analizado, falta un export

6 archivos (2021-2026). **Mismo patrón que Ventas pero invertido**: hay dos formatos y ninguno
cubre los 6 años.

- **2021-2024** (35 columnas): formato **por ítem** — `Id, Fecha, Vencimiento, Categoría, Proveedor,
  CUIT / DNI, Tipo, Tipo de Comprobante, Punto de Venta, N° Factura, **Producto/Servicio, Código,
  Cantidad, Costo, Precio unitario**, …`. Una fila por línea de producto (2022: 2.300 filas / 529
  compras). **Sólo el archivo de 2021** trae el bloque de resumen arriba (`Total Compras Creadas`,
  `Cantidad de Productos`…) y el listado real arranca en la **fila 7**; 2022-2024 arrancan en la 1.
- **2025-2026** (40 columnas): formato **resumen con pago** — `Productos` concatenado en una celda
  (sin cantidad ni precio), pero con `Total ND, Total NC, Pagado, A Pagar, Estado, Medio de Pago,
  Usuario, Nota Interna`. Una fila por compra.

**FALTA PEDIR: Compras 2025 y 2026 en formato por ítem** (el de 35 columnas, con `Cantidad`) — misma
opción de exportación que se está usando para Ventas. Los pagos de 2021-2024 **no hace falta**
pedirlos: ya están en `Cuentas/` (3.323 movimientos tipo `Pago`). *(08/08: el usuario reemplazó
2025 y 2026 pero salieron otra vez en el formato resumen de 40 columnas — sigue pendiente.)*

**❌ ERROR (08/08) — CORREGIDO el 10/08: SÍ hace falta el export "c/ pago" de Compras.** Se había
concluido que no, porque los pagos de `Cuentas/` vinculan al 99,9% por comprobante. **Faltó mirar que
el formato por ítem (35 columnas) NO tiene columna `Pagado`**: sin ella no se sabe cuánto se pagó de
cada factura, no se pueden imputar los ~1.274 pagos sin referencia de comprobante, y no hay contra
qué validar la imputación. El equivalente exacto del `c/ cobro` de ventas es el **formato de 40
columnas** (`Pagado`, `A Pagar`, `Estado`, `Medio de Pago`, `Total NC`, `Total ND`). Guardado en
`public/imports/Compras c- pago/` — 2025 y 2026 recuperados de Descargas; **faltan 2021-2024**.

**Lección de método:** antes de dar por innecesario un archivo, verificar **contra qué columna
concreta** se lo descarta, listando las columnas del que se propone en su lugar. La conclusión de
abajo se sacó por el match de pagos sin revisar el resto del contenido. (Costo del error: se
sobrescribió el archivo de 40 columnas de 2026 al renombrar el por-ítem — recuperable sólo porque
seguía en Descargas. **Antes de un `mv`/`cp` sobre un nombre existente, mirar qué se está pisando.**)

**Los pagos de compras vinculan por comprobante desde `Cuentas/`** (pregunta del usuario, 08/08).
Salen de `Cuentas/` (3.323 movimientos `Pago`), que traen `Tipo de Factura` + `Punto de Venta` +
`N° Factura` — **ojo: es el punto de venta del PROVEEDOR**, no el nuestro (se ven 6, 110, 26, 6364…)
— más el nombre del proveedor en `Detalle`. Vinculan por `proveedor + punto de venta + n° factura`.
Medido contra las compras por ítem de 2021-2024: **1.156 de 1.158 = 99,8%** (2021 214/216, 2022
419/419, 2023 230/230, 2024 293/293). Los 418 pagos de 2025 y 262 de 2026 no matchean **sólo porque
faltan las compras por ítem de esos años**, no por un problema de datos. Quedan aparte **1.485 pagos
sin referencia** de comprobante (`-`), a vincular por proveedor + monto + fecha como se hizo con las
cobranzas.

**⚠️ Compras arrastra el mismo bug de fechas invertidas que `Cuentas/`** (ver BUG 2 más abajo):
columna `Emisión` mezclada entre celdas tipo fecha y tipo texto, con día y mes dados vuelta en las
ambiguas. Verificado sobre `2026 Compras.xlsx`: leído directo llega hasta `2026-12-06` con **46
compras de fecha futura** (imposible); aplicando la corrección el rango cierra en **2026-08-05 con 0
posteriores al corte**. Aplicar la misma regla de recuperación.

### ✅ 07/08/2026 — Gastos (`public/imports/Gastos/`): analizado, LISTO para importar

6 archivos (2021-2026), **9.394 gastos**. Vienen en **dos formatos distintos**, los dos usables:

- **2025-2026**: listado plano, encabezado en fila 1 — `Estado, Id, Emisión, Categoría,
  Subcategoría, Descripción, Medio de pago, Monto`. Directo.
- **2021-2024**: informe **agrupado**. Filas 1-2 = bloque de resumen (`Desde, Hasta, Gasto Total`);
  después, por cada categoría, una fila suelta con su nombre, otra con la subcategoría, el
  encabezado `Id, Fecha, Subcategoría, Descripción, Medio de pago, Total`, las filas de gasto y un
  `Total <categoría>:`. **No traen columna `Categoría` ni `Estado`** — la categoría hay que tomarla
  de la última fila separadora.

**No se puede deducir la categoría desde el nombre de la subcategoría.** Verificado contra
`categorias` del VPS (8 padres / 89 subcategorías): **6 nombres existen bajo más de un padre** —
`Otros` (bajo Impuestos, Logística envíos fletes, Mercado Pago - Mercado Libre, Otros Gastos y
Servicios Profesionales) y `ABL`, `Alquiler`, `Aysa`, `Edenor`, `Personal Flow` (bajo **Juan
Personal** y **Oficina Pompei** a la vez, o sea gasto personal del dueño vs gasto de la oficina).
Deducir por nombre asignaría mal **607 gastos de 6.181 (9,8%)**, justo en la distinción
personal/empresa. Hay que leer la categoría por posición.

**Y leerlo por posición funciona: probado y verificado.** El parser reproduce **exacto** el total de
control que trae cada archivo en la fila 2: 2021 $8.669.775,46 · 2022 $23.474.840,65 · 2023
$75.798.822,81 · 2024 $156.036.390,25. Los cuatro al centavo. Como cada archivo trae su propio
total, un error de parseo no puede pasar silencioso. **No hace falta reexportar Gastos.**

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

### 07/08/2026 — Numeración de comprobantes: análisis y decisión

Carpeta nueva y organizada por el usuario en `public/imports/`: `Clientes/`, `Compras/` (2021-2026),
`Cuentas/` (23 archivos de tesorería), `Gastos/` (2021-2026), `Productos/`, `Ventas c- cobro/`
(2021-2026, los 6 años, mismo formato de 50 columnas + índice = 51). **23.564 filas de ventas** en
total. Este formato "c/ cobro" es el resumen por comprobante (no por ítem), distinto del que usó el
importador de 2021-2025.

**Clientes: no hay nada nuevo que subir.** `Clientes/Clientes hasta 05_08.xlsx` (19.364 filas, Id
hasta 19335) es el mismo corte ya importado el 06/08. La base del VPS va más adelantada (19.406
clientes, 117 creados del 01/08 en adelante por el sync de ML y altas manuales) — el CRM ya está en
uso y genera los clientes nuevos solo.

**⚠️ Gotcha de matching por nombre (crítico para importar ventas):** 377 nombres de la base tienen
**doble espacio** entre nombre y teléfono (`'Alberto Diaz  1164854451'`) mientras el export nuevo los
trae con espacio simple. Matchear por igualdad exacta falla en esos 377 y crearía duplicados. **Hay
que colapsar espacios en ambos lados** en todo el matching de ventas. (12 filas más del Excel no
están en la base y está bien: 5 con nombre `#ERROR!`, 2 convertidas a número, 2 con mojibake, etc.)

**Numeración fiscal del sistema viejo (punto de venta 5):** de las 10.946 filas con comprobante,
sólo las **7.971 con `ARCA = Aprobado`** tienen número fiscal genuino — y son **100% únicas, sin un
solo duplicado**. Las 5.411 `Sin Enviar`/`Error` reusan números de la serie aprobada (por eso el
import de 2021-2025 había dejado `nro_comprobante = null`; ahora sabemos que para las aprobadas sí
se puede preservar). La serie corre continua entre años:

```
Tipo A:  2021: 3–191   2022: 192–546   2023: 547–995   2024: 996–1577   2025: 1578–2033   2026: 2034–2304
Tipo B:  2021: 1–289   2022: 290–490   2023: 491–1679  2024: 1680–3535  2025: 3536–5123   2026: 5124–5676
```

Huecos en toda la serie: **1 en A** (2241) y **6 en B** (459, 1655, 2328, 3165, 4855, 5539) —
probablemente comprobantes anulados. Serie sana.

**Hallazgo clave — los dos mundos NO chocan: son puntos de venta distintos.** El CRM emite en el
**punto de venta 9** (`POMPEI CRM`, creado 03/08/2026), y ARCA ya autorizó con CAE real
`0009-00000001/2/3` (B) y `0009-00000001` (A). Esa serie arranca en 1 **por diseño** y es
legítimamente independiente de la del PV 5. **No hay que "correr" las ventas del CRM para que sigan
al histórico** — sería falsear un número ya autorizado, y además el próximo número no lo decide
nuestra base sino ARCA (`FECompUltimoAutorizado` del PV 9, ver `EmisorComprobante.php:64`).

**Bug real detectado en `Venta::siguienteNroComprobante()`** (`app/Models/Venta.php:187`):
```php
$n = static::withTrashed()->where('tipo_comprobante', $tipoComprobante)->count() + 1;
return '0001-'.str_pad((string) $n, 8, '0', STR_PAD_LEFT);
```
Dos problemas: (a) el prefijo `0001-` está **hardcodeado** y apunta a un punto de venta que no
existe — ni el 5 viejo ni el 9 del CRM (hoy hay 91 ventas con ese número fantasma y sólo 1 con el
número fiscal real); (b) usa `count()` en vez de `max()`, así que importar el histórico haría saltar
el próximo número ~22.000 posiciones, y borrar una venta hace que se repita un número.
`Presupuesto::siguienteNumero()` y `Remito::siguienteNumero()` tienen el mismo problema de fondo
(usan `max(id) + 1`, atado al auto_increment). **Compras no**: su `nro_comprobante` es input
obligatorio del usuario (es la factura del proveedor).

**Plan acordado:**
1. Fiscal (PV 9): no se toca, ARCA manda.
2. Histórico (PV 5): importar `nro_comprobante` real (`0005-00002304`, etc.) **sólo** para las 7.971
   aprobadas; el resto con `null`.
3. Arreglar `siguienteNroComprobante`: `max()` acotado a la serie interna, en vez de `count()`.

**✅ RESUELTO (07/08) — el prefijo `0001-` SE MANTIENE (opción A, decisión del usuario).** Aparecía
una contradicción entre `specs/008-ingresos-ventas-presupuestos/research.md §5` (que define la serie
interna `0001-00000003` y dice espejar Contagram) y el export de la cuenta real (que muestra `-` en
las ventas no facturadas). **Se resolvió: las dos son fieles, pero a cuentas distintas** — el
relevamiento con capturas (`docs/informe_contagram_ingresos.md §3.1`) confirma textualmente
"**N° de comprobante**: autogenerado (ej. '0001-00000003')" en la cuenta de prueba, que **no tenía
facturación electrónica** (documento con sello "NO VÁLIDO COMO FACTURA"); la cuenta real, con ARCA,
usa PV 5 y deja `-` en las no facturadas. Dato relevante: **el listado de Ventas no tiene columna de
N° de comprobante** (19 columnas, ninguna es esa) — el número sólo aparece en el formulario y en el
documento imprimible.

Se eligió **no tocar la numeración existente**: la serie interna `0001-` vive en un espacio de
nombres separado que **nunca puede colisionar** con la serie fiscal del PV 9. La colisión sólo
aparecería si se "corrieran" las ventas del CRM hacia `0009-`.

**Cambios de código aplicados (sin commitear todavía):**
- `Venta::siguienteNroComprobante()` — `count()+1` → `max()+1` **acotado por `LIKE '0001-%'`**. El
  filtro es imprescindible, no cosmético: verificado contra la base real, un `max()` sin filtrar
  devuelve `0009-00000001` para tipo B (el número fiscal de la venta 1 ordena por encima de la serie
  interna) y habría generado `0009-00000002` como siguiente — **exactamente el número con CAE real
  de la venta 68**. Se agregó la constante `Venta::PREFIJO_SERIE_INTERNA`.
- `Presupuesto::siguienteNumero()` — `max(id)+1` → `max(CAST(nro_presupuesto AS UNSIGNED))+1`.
- `Remito::siguienteNumero()` — `max(id)+1` → `max(CAST(nro_remito AS UNSIGNED))+1`. El CAST hace
  falta porque la columna es varchar sin padding y el máximo saldría alfabético (`"9" > "10"`).

**Verificado contra la base del VPS**: siguiente A `0001-00000002`, B `0001-00000092`, presupuesto
`00000020`, remito `2` — idénticos a lo que produce el código actual, o sea **cero cambio de
comportamiento hoy** e inmune al import histórico mañana. Suite de tests: 68 fallan / 149 pasan
**igual que antes del cambio** (los 68 son fallos de entorno preexistentes, 403 de permisos;
comprobado corriendo la suite con los cambios guardados en stash).

**Sobre los `id` auto-incrementales — DECIDIDO (07/08/2026): no se remapean.** El listado **no
ordena por `id`** sino por `created_at` desc (`resources/js/ventas.js:315`, `presupuestos.js:226`),
así que si el import preserva la fecha real en `created_at` el histórico cae abajo solo. Lo único
que queda "raro" es la columna `Id` visible (las 91 ventas del CRM tienen id 1-91 y el histórico va
a 92+), y es puramente estético: **el `id` no tiene relación con el número de comprobante** (esa
coincidencia ya está rota de todas formas), así que remapear sería riesgo sin beneficio. Decisión
del usuario: se deja el auto_increment como está.

Se relevó igual el alcance completo por si algún día hace falta (inventario sacado de
`information_schema`, no de memoria): apuntan a `ventas.id` por FK `venta_items` (114), `cobros`
(91), `ml_ordenes.venta_id` (69), `presupuestos.venta_id` (9), `notas_credito_debito` (2), `remitos`
(1); y **sin FK, por referencia polimórfica** (el riesgo real de un remapeo) `movimientos_stock`
(`origen_type=Venta`, 112) y `comprobantes_fiscales` (`comprobantable_type=Venta`, 5), más
`logs_auditoria.entidad_id` (73, cosmético). `movimientos_tesoreria` apunta a `Cobro`, no a `Venta`.
En todo el esquema hay sólo 4 tablas polimórficas y 2 apuntan a `Venta`.

**Punto de venta — DECIDIDO (07/08/2026): el CRM sigue emitiendo en el PV 9.** No se pasa a
continuar la serie del PV 5 del sistema viejo. Las dos series conviven independientes.

### ✅ 07/08/2026 — RESUELTO el bloqueante de los ítems: llegó el export "por ítem"

`public/imports/Ventas/Ventas {2021..2025}.xlsx` — el formato de 44 columnas que ya usa
`ImportarVentasHistoricas`, con **`Cantidad`, `Precio Unitario`, `Código`, `Producto/Servicio`** por
línea. 31.544 líneas. (2023 tiene el header al final, gotcha ya conocido y manejado.)

**El `Id` une los dos exports al 100%**: para 2021-2025, todas las ventas del "c/ cobro" tienen sus
ítems acá (2.123/2.123, 4.029/4.029, 4.845/4.845, 4.393/4.393, 4.645/4.645). Las **532 filas que
sobran** en el "por ítem" son exactamente las 532 NC/ND que habían quedado afuera del import de
2021-2025 — coincidencia exacta con lo documentado.

**Reconciliación verificada**: `suma(Cantidad × Precio Unitario)` por venta vs `Subtotal Sin
Descuento` del export "c/ cobro" → **19.138 de 19.150 = 99,94% cuadran** (tolerancia 0,5%).

**✅ DECIDIDO (07/08) — productos faltantes: NO se crean productos fantasma.** Del export por ítem,
27.871 de 31.544 líneas (88,4%) matchean un producto del catálogo por el Id del `Código`. Las otras
**3.673 (11,6%)** no: 1.832 con Id inexistente (**313 productos distintos**, mayormente mercadería
discontinuada que Contagram borró después de venderla — griferías FV, cajoneras SANDRA, repuestos
RAO — más los servicios de Mauricio, `AHORA 12` código 9999 con 151 líneas, y el comodín `30622`
"99999" con **258 líneas**, que habíamos borrado como basura en el import de productos y resulta ser
un "código libre" para ventas sin código propio, igual que `CODIGO LIBRE` código `$$$`), y 1.841 con
`Código` en otro formato (`ID:26230 SKU 7032 AB-7072`) o vacío.

**Solución (decisión del usuario: "no pueden crear productos fantasmas"):** esas líneas entran como
**renglón libre en `venta_items` con `producto_id = NULL`** — la columna es nullable y la tabla tiene
su propio `descripcion` (NOT NULL), más `cantidad`, `precio_unitario` y `subtotal`. La venta queda
completa y la caja cierra exacta sin ensuciar el catálogo. Se descartó `venta_conceptos`: existe
pero es para percepciones/impuestos (`tipo = 'percepcion'`), no para renglones de producto.
Contrapartida aceptada: esas líneas no generan movimiento de stock ni entran en la estadística de
productos más vendidos. El nombre y el código quedan en la línea, así que se pueden vincular más
adelante sin rehacer el import.

**✅ 08/08/2026 — llegó `Ventas 2026.xlsx` por ítem** (5.556 líneas, 3.676 comprobantes). Validado:
44 columnas correctas, rango **02/01 a 05/08/2026 con 0 filas posteriores al corte**, `Cantidad` y
`Precio Unitario` sin nulos, 0 celdas `######`, y **reconcilia 3.527 de 3.528 (99,97%)** contra el
`c/ cobro` de 2026. Se renombró de `Ventas 2026 (1).xlsx`.

**Gotcha (ya conocido, no es un defecto):** en los exports por ítem la columna `Emisión` viene como
**serial numérico de Excel** (`46239.0`), no como fecha — igual que en 2021-2025. Convertir con
`Date::excelToDateTimeObject()` (o epoch 1899-12-30), como ya hace `ImportarVentasHistoricas`.

**Resuelto de paso: la venta 24267** ($211.581,06 del 04/08) **no está en este export** → se borró
de verdad en Contagram. Rige la foto nueva: queda afuera del import.

**Nota sobre la antigüedad de los archivos:** los por-ítem de 2021-2024 son de **mayo de 2025** (14
meses), y aun así reconcilian al 99,94% contra los `c/ cobro` exportados esta semana. Confirma que
los años históricos están congelados y que sólo 2026 se movía.

### ✅ 10/08/2026 — VENTAS COMPLETO Y VALIDADO (los 6 años, listo para importar)

Llegaron `Ventas 2022.xlsx` reexportado (los 1.362 huecos de `Cantidad`/`Precio Unitario`
desaparecieron) y `Compras 2025` por ítem. Reconciliación final `suma(Cantidad × Precio Unitario)`
vs `Subtotal Sin Descuento` del `c/ cobro`, **23.550 de 23.563 = 99,94%**:

| Año | Ventas | Cuadran | % |
|---|---|---|---|
| 2021 | 2.123 | 2.111 | 99,43% |
| 2022 | 4.029 | 4.029 | **100%** |
| 2023 | 4.845 | 4.845 | 100% |
| 2024 | 4.393 | 4.393 | 100% |
| 2025 | 4.645 | 4.645 | 100% |
| 2026 | 3.528 | 3.527 | 99,97% |

**⚠️ 10/08 — PROBADO que el `c/ cobro` de 2026 está vencido y hay que reexportarlo.** La venta
**24300** aparecía como "descuadrada"; contrastada contra Contagram resultó que **no es un error de
datos sino la comparación de dos fotos distintas**: el `c/ cobro` (07/08 09:27) la tiene como
"Agarradera 45 cm, cód. 27851, $68.115,28, sin cobrar", mientras el por-ítem (08/08 00:12) y
**Contagram hoy** coinciden exacto en "Agarradera 30 cm, cód. 27850, cant. 2, **$99.123,29**", ya
cobrada el 07/08. La venta fue editada entre ambos exports. **El por-ítem está al día; el `c/ cobro`
de 2026 no.**

**Ventas borradas en Contagram (rige la foto nueva, van afuera del import):** la **24267**
(confirmado por el usuario: no aparece) y la **2140** (ídem). Ambas estaban en nuestros exports pero
ya no existen en el origen.

**37.100 líneas de ítem.** Una sola venta del `c/ cobro` sin detalle: la 24267 (la borrada en
Contagram). **Las 13 que no cuadran** suman $643.924,56 sobre $1.570.665.960 (**0,04%**): 12 son de
2021 —11 con Id 4 a 16, las primerísimas cargas de junio 2021 cuando armaban el sistema, 6 del
cliente `NO USAR MAS`— y 1 de 2026 (Id 24300). En todas la suma de ítems es **mayor** que el
subtotal. Listarlas en el reporte del import; no bloquean.

**Compras 2025 por ítem validado**: 2.253 filas / **519 comprobantes = 472 compras + 47 NC/ND**,
coincide con el "Cantidad Compras Creadas: 472" del propio bloque de resumen del archivo.
Encabezado en la **fila 7** (bloque de resumen arriba, igual que 2021). Trae 44 notas de crédito y 7
de débito con su detalle de productos.

**Único archivo que falta: `Compras 2026` por ítem** (el que hay sigue en formato resumen de 40
columnas).

**⚠️ `Ventas 2022.xlsx` venía roto (RESUELTO 10/08, ver arriba)**: 1.362 líneas (que afectan a **885 ventas**) tienen `Cantidad`,
`Precio Unitario`, `Código`, `Subtotal` y `Total` **todos vacíos**, sólo queda el nombre del
producto. **Ninguna de esas 885 reconcilia.** Los otros 5 años están perfectos → es un defecto del
archivo, no del formato. **Pedir re-export de 2022.** Faltaba también 2026 (el usuario lo estaba
descargando).

**❌ NO REINTENTAR: reconstruir ítems desde la celda `Productos` del export "c/ cobro".** Se
investigó a fondo el 07/08 y no es viable — queda documentado para no repetir el trabajo:

- La celda tiene formato `-<nombre> <PROVEEDOR> <Id> <código>`, o sea **trae el Id del producto**
  (no hace falta matchear por nombre). Resuelve el **88,7%** de las 36.500 líneas.
- El 11,1% que no resuelve **sí tiene Id, pero ese producto no existe en `productos`**: son
  mayormente los servicios de mano de obra de Mauricio (`30517`, `30520`, `36013`, `43135`…, patrón
  `id id`) y conceptos como `Ahora 12` (recargo de financiación, no un producto). **Hay que crear
  esos productos/servicios faltantes en el catálogo.** Armar un diccionario nombre→Id sólo suma 69
  líneas más: el problema no es el nombre, es el catálogo incompleto.
- **Pero la celda NO tiene cantidad ni precio unitario**, y ese es el bloqueante real. 72,9% de las
  ventas son de 1 ítem (50,7% de la plata), pero el 27,1% restante son **$774.825.423 (49,3%)** que
  no se pueden desglosar.
- Se probó derivar el precio de las ventas de 1 ítem del mismo producto en fecha cercana (idea del
  usuario). Resultado medido: ±1 día → 0,1%; ±7d → 0,7%; ±30d → 2,5%; ±90d → 5,6%; ±365d → 13,6%.
  Dos techos insalvables: (a) sólo **1.286 de 2.500** ventas multi-ítem tienen todos sus productos
  vistos alguna vez solos (techo estructural ~51%); (b) con la inflación argentina 2021-2026 un
  precio de ±365 días no significa nada (producto 23892: $6.956 en 2022, $68.361 en 2026).
- Hallazgo lateral útil: en las ventas de 1 ítem del mismo producto el mismo día, el 30,6% tiene
  importes distintos, y la dispersión se concentra en **múltiplos exactos** (p75 = +100%, p90 =
  +200%) → **no son precios distintos, son cantidades distintas**. Confirma que en el export
  "c/ cobro" la cantidad es irrecuperable.

### 07/08/2026 — Movimientos de cuentas (`public/imports/Cuentas/`): análisis de viabilidad

23 archivos, mismo formato de 11 columnas (`Id, Fecha, Operación, Detalle, Ingreso, Egreso, Saldo,
Tipo de Factura, Punto de Venta, N° Factura, Descripción`), **48.222 movimientos**. No es sólo
cobranzas — es la tesorería completa: `Cobro` 25.086, `Movimiento entre Cuenta` 10.458, `Gasto`
9.274, `Pago` 3.323, `Ingreso` 61, `Saldo Inicial` 20.

**Viable asociar cobros a ventas**: el export trae el vínculo explícito al comprobante
(`Tipo de Factura` + `Punto de Venta` + `N° Factura`). Resolución medida:

- **Cobros CON referencia de comprobante (14.811)**: 4.611 resueltos por clave única; 5.968 más
  desambiguando por nombre de cliente (`Detalle`); 70 por monto → **10.649 (71,9%)**. Sólo **4
  genuinamente ambiguos**. Los 4.136 restantes se pierden por el bug `######` (ver abajo).
  Ojo: la clave (PV, Tipo, N°) NO es única por sí sola porque las ventas `Sin Enviar` reusan
  números — hay que desambiguar sí o sí con cliente+monto.
- **Cobros SIN comprobante (10.275)** contra las 9.284 ventas sin comprobante: **7.607 (74%)**
  únicos por cliente+monto exacto, 615 múltiples, 2.053 sin match. De estos últimos buena parte son
  del cliente sentinela (`NO USAR MAS` 2.091 + `NO USAR MAS 2` 311 cobros), que ya se descartan.
**✅ ACTUALIZADO tras el re-export del 07/08 (ver BUG 1): los números finales son mucho mejores.**
Con los 9 archivos corregidos, el camino A pasa de 71,9% a **99,8% (14.780 de 14.811)** — sólo 8
ambiguos y 23 sin match. Camino B: 5.899 únicos (57,4%), 52 múltiples, 1.922 sin match, y 2.402
(23,4%) del cliente sentinela que se descartan. **Total: 20.679 cobros asociables = 91,2% de los
cobros útiles.** Números de arriba (71,9% / 74%) quedan sólo como referencia del estado previo.

**⚠️ BUG 1 — `######` en `N° Factura` (necesita re-export, dato irrecuperable).** 4.147 celdas
contienen el string literal `######` (el artefacto de "columna angosta" de Excel: el export escribió
el texto mostrado en vez del valor; verificado con openpyxl, `data_type='s'`). **El número real no
está en el archivo**, no es un problema de lectura. Afecta sólo a la columna `N° Factura` y sólo a 9
de los 23 archivos, todos de tarjetas: **Visa (3.116), Mastercard (576), QR (266), Amex (132),
Maestro (23), Cabal (14), Visa Credicoop A pagar (10), Nulo (6), Retenciones (4)**. Ventas, Compras,
Gastos, Clientes y Productos están limpios (scan completo de la carpeta: 0 celdas afectadas).
Mismo *tipo* de problema que la corrupción de costos del 06/08: un roundtrip por Excel/Sheets que
pisa valores con lo que se ve en pantalla.

**✅ RESUELTO (07/08/2026):** el usuario re-exportó los 9 archivos. Verificado: **0 celdas `######`**
en toda la carpeta, y los conteos de números recuperados coinciden exactamente con lo que faltaba
(Visa 3.116, Mastercard 576, QR 266, Amex 132, Maestro 23, Cabal 14, Visa Credicoop 10, Nulo 6,
Retenciones 4). La regla de fechas del BUG 2 se revalidó sobre los archivos nuevos: sigue dando
**0 violaciones de orden en los 25 archivos**.

**⚠️ BUG 2 — fechas con día y mes invertidos (recuperable, NO necesita re-export).** En los archivos
de `Cuentas/` la columna `Fecha` viene mezclada: celdas tipo fecha (`d`) y celdas tipo texto (`s`).
Excel convirtió a fecha real sólo las ambiguas (día ≤ 12) **interpretándolas al revés**, y dejó como
texto las que no podían serlo (día > 12, ej. `7/31/2026`). Regla de recuperación:

- celda tipo texto → parsear como `M/D/Y`;
- celda tipo fecha con `day <= 12` → **intercambiar mes y día**;
- celda tipo fecha con `day > 12` → tomar tal cual.

**Verificado con test decisivo**: los archivos vienen ordenados por fecha descendente; con la regla
aplicada los **25 archivos quedan con 0 violaciones de orden**, contra 10-110 violaciones cada uno
leyéndolos directo. No es una hipótesis, es determinístico.

**Los Excel de Ventas NO tienen este problema** (verificado aparte: `Emisión` es 100% tipo fecha,
rango 2026-01-02 a 2026-08-05, los 31 días del mes presentes, volúmenes coherentes por mes y el `Id`
correlaciona con la fecha).

**Efecto colateral a corregir: el import de clientes ya hecho.** `Clientes/…xlsx` columna `Creado`
tiene la misma mezcla (1.446 celdas tipo fecha + 2.552 tipo texto), y esa columna se mapeó a
`clientes.created_at`. Los clientes creados un día ≤ 12 quedaron con **día y mes invertidos** en la
base (confirmado: los últimos del archivo, dados de alta el 05/08/2026, figuran como `2026-05-08`).
Es cosmético pero conviene arreglarlo con un UPDATE aplicando la misma regla.

### ⚠️⚠️ 07/08/2026 — REGLA QUE MANDA SOBRE TODO LO DEMÁS: no se excluye NINGUNA venta

**Requisito explícito del usuario: "no quiero perder ninguna venta, porque me tiene que cerrar la
caja. Todo lo que está en los Excel tiene que estar."** Esta regla **revierte todas las decisiones
previas de exclusión** de esta bitácora y de `docs/importacion_ventas_historicas.md`:

- ❌ Ya NO se excluyen las 4.063 filas del cliente sentinela (`NO USAR MAS`, `NO USAR MAS 2`,
  `BORRAR NEGATIVOS FV`) — son plata que pasó por la caja.
- ❌ Ya NO se excluyen las ventas sin comprobante fiscal (9.284 con `ARCA = ---`).
- ❌ Ya NO se excluyen las NC/ND (ver abajo, ahora además se pueden vincular).
- ❌ Ya NO se excluyen las filas sin `Tipo de Comprobante`.
- Se importan las **23.564 filas** de ventas 2021-2026, completas.

**Cifras de control del Excel** (el import tiene que reproducirlas EXACTAS, es el criterio de
aceptación): total facturado **$1.570.665.960,38**, cobrado **$1.506.014.720,12**, a cobrar
**$9.882.255,64**.

**Notas de crédito/débito — problema resuelto por el formato nuevo.** El export "c/ cobro" trae
`Total NC` y `Total ND` **como columnas de la fila de la venta**: 629 NC ($56.972.371,64) y 54 ND
($2.203.385,37), ya vinculadas a su venta. Esto desbloquea lo que en el import de 2021-2025 había
dejado 532 NC/ND afuera (no se sabía a qué venta corregían, y `notas_credito_debito.venta_id` es
NOT NULL).

**Tensión con el anti-duplicado de ML, y su medición real.** "No excluir nada" y "no duplicar las
ventas de ML ya convertidas en el CRM" no pueden cumplirse ingenuamente las dos. Medido contra las
92 ventas que hoy tiene el CRM:

- **38 son posteriores al 05/08** (corte del Excel) → no pueden duplicar, quedan como están.
- **54 caen en el solapamiento (26/07–05/08)**, y de esas **31 NO están en el Excel de ninguna
  forma** (ni por monto). Sólo 4 matchean fecha+monto exacto, 17 por monto ±3 días y 2 por monto con
  fecha lejana.

**✅ ACLARADO por el usuario (07/08) y verificado contra la base — el corte es limpio:** hasta el
05/08 **la aplicación no se usaba**; lo que existe en el CRM se cargó del 06/08 en adelante y no hay
que duplicarlo. Verificado por `created_at`: **1 sola venta creada antes del 06/08** (la venta de
prueba con CAE real de ARCA), 72 creadas el 06/08 y 19 el 07/08; los cobros igual (71 el 06/08, 20
el 07/08). **Regla operativa definitiva:**

- **Excel hasta el 05/08 → se importa TODO**, sin excluir nada (no hay riesgo de duplicar).
- **Lo cargado en el CRM desde el 06/08 → ya existe, no se toca ni se re-importa.**

**Cabo suelto RESUELTO (07/08):** de las 72 ventas cargadas el 06/08, **54 tienen `fecha_emision`
anterior al 05/08** — órdenes de ML convertidas después, tal como lo describió el usuario ("hay
algunas de ML de antes del 5/8 que ya están hechas en la plataforma porque se transformaron las
órdenes en venta posteriormente"). Cruzadas contra el Excel 2026 **por nombre de cliente + monto,
con asignación uno a uno** (cada fila del Excel se reclama una sola vez, si no el monto repetido
$171.818,78 genera falsos positivos en cadena):

- **39 → EXCLUIR del import** (duplicadas, nombre + monto exactos).
- **13 → IMPORTAR** (no están en el Excel). 12 son del 05/08 con el **apodo de ML como nombre de
  cliente** (`GLOOLIVARES`, `METCESAR`, `FALVAR2009`…): órdenes del último día convertidas en el CRM
  que nunca se facturaron en Contagram. La 13ª es la venta id 1 (prueba de ARCA con CAE real).
- **2 → REVISAR a mano**: venta 61 `BGHCDBAFE15709` $52.473,80 vs Excel Id 24185 `CAROLINA BELEN
  QUAGLIA` (mismo monto y fecha, ¿apodo vs nombre real?); venta 4 `FRANCISCO FAVERO` $171.818,78 vs
  Excel Id 24121 $176.611,63 del 29/07 (mismo nombre, probablemente compras distintas).

Detalle completo en **`public/imports/revision_duplicados_ml.xlsx`** (venta del CRM vs fila del
Excel enfrentadas, con `Orden ML`). **Salvedad**: las 13 "importar" no se pueden confirmar por
nombre (su cliente es el apodo de ML); la evidencia es que no quedó ninguna fila del Excel sin
reclamar con ese monto y fecha. Para certeza total, contrastar el `Orden ML` contra un export de
órdenes de Contagram.

**Método a NO repetir:** el primer cruce se hizo por `fecha_emision` + monto y dio "31 de 54 no
están en el Excel", conclusión equivocada — la fecha de emisión del CRM no coincide con la de
Contagram. El cruce bueno es por **nombre + monto con asignación uno a uno**.

**Conclusión clave: ninguna de las dos fuentes es superset de la otra.** Al Excel le faltan 69
ventas que sí están en el CRM. Para que la caja cierre hacen falta las dos fuentes. El riesgo real
de duplicación son **~23 ventas**, que se resuelven de a una. **Ojo**: el monto $171.818,78 se
repite en decenas de ventas (mismo producto), así que ahí el match por monto es evidencia débil —
para esas usar `ml_order_id`, que es dato duro.

### ⚠️ 07/08/2026 — CAMBIO DE ENFOQUE: las órdenes ML ya están convertidas en el CRM

Todo lo que sigue en "Tema 1" quedó **obsoleto**. Ya no hay que vincular `ml_ordenes` a ventas
importadas ni preocuparse por que el flujo tradicional las duplique: **las órdenes de ML ya se
convirtieron en ventas reales dentro del CRM del VPS**.

El problema ahora es el inverso: el Excel de ventas 2026 **también** contiene esas mismas ventas
de ML. Si se importa tal cual, quedan **duplicadas** (una versión ya en el CRM vía conversión de
orden + otra creada por el import).

**Tarea real:** identificar en el/los Excel las filas que corresponden a ventas de ML que ya
existen en el CRM y **excluirlas del import** (no importar esas filas). Las ventas ya convertidas
en el CRM son la versión buena y se dejan como están.

Se mantiene abajo el detalle del cruce viejo sólo como referencia histórica del método de
matching (nombre real vs apodo ML, monto, fecha, SKU), que sigue siendo útil para identificar qué
filas del Excel son de ML.

### Tema 1 (OBSOLETO — ver arriba): no duplicar órdenes de Mercado Libre ya convertidas

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

## Próximos pasos (en orden, actualizado 07/08/2026)

1. Recibir la carpeta de Excel organizada que va a dejar el usuario y hacer el análisis
   estructural/calidad de datos completo (duplicados, nulos, columnas raras), mismo nivel que se
   hizo con productos/clientes.
2. **Identificar las ventas de Mercado Libre que YA existen en el CRM (órdenes ya convertidas) y
   excluir esas filas de los Excel antes de importar** — es el requisito bloqueante para no
   duplicar. Ver el cambio de enfoque del 07/08 más arriba.
3. Decidir formato de import de ventas 2026 (¿conseguir el export "por ítem" como 2021-2025, o
   adaptar el comando al formato resumen?).
4. Importar ventas 2026 ya sin las filas de ML.
5. Cuando llegue la hoja de cobros detallada: importar `cobros` con cuenta/monto/fecha reales.
6. Limpieza y fusión de clientes duplicados (recién ahí, con ventas ya asociadas).

---

## 16/08/2026 — Descuento por línea no traducido: 27 ventas con el desglose inconsistente

Detectado al implementar el pivot de la spec 069, comparando el total del cruce contra el KPI del
Informe de Ventas: daba $294.162 de más sobre un período de 12 días.

**Causa**: Contagram registra el descuento **por línea** (columna "Bonif." de su detalle de venta).
Nuestro modelo lo tiene en la cabecera (`ventas.descuento`), y el importador sumó todas las
bonificaciones de línea ahí **sin descontarlas de los subtotales de línea**. El síntoma visible en
pantalla es `Descuento General (0%)` con un importe distinto de cero: el porcentaje no existe
porque nunca hubo un descuento general.

**Alcance**: 27 ventas de 23.785 (0,11%), $1.724.324,55 de descuento declarado. Se detectan con:

```sql
SELECT id, fecha_emision, descuento, total FROM ventas
WHERE deleted_at IS NULL AND descuento > 0.005
  AND subtotal_sin_descuento = subtotal_con_descuento;
```

**Los totales están BIEN — las cajas cierran.** Verificado contra Contagram en la venta 24209:
$1.902.809,94 allá contra $1.902.809,97 acá (3 centavos), con las mismas cobranzas y el mismo saldo
a cobrar. Lo que no cierra es `neto de línea × 1,21` contra el total.

| Sale de | Estado |
|---|---|
| Totales, cobranzas, cajas, cuenta corriente (`ventas.total`) | correctos |
| Columnas derivadas por línea: "Precio Neto" y "Resultado" del Informe de Ventas, y las medidas del pivot | arrastran el desglose mal |

**De las 27, 26 son ventas 100% bonificadas con total $0** (la plata está bien igual). **Sólo la
24209 tiene descuento parcial** y es la única con impacto real en un informe.

### 17/08/2026 — Corregido, y el alcance real era 6 veces mayor

**Verificado contra Contagram antes de tocar nada**: la venta 15253 (05/11/2024, $398.000 de
descuento) se abrió en Contagram y su PDF muestra **`% Bonif. 100%` y `Subtotal $0,00` en las dos
líneas**, con Total $0. Confirma que el total nuestro está bien y lo que estaba mal era el renglón.

**Causa raíz encontrada** — `ComprobantesContagram::armarItem()`:

```php
$subtotal = $L->numero($f['Subtotal con Descuento'] ?? null);
if ($subtotal === null || abs($subtotal) < 0.005) {   // ← el bug
    $subtotal = $cantidad * $precio;
}
```

El fallback a `cantidad × precio` existe porque en 2021 la columna viene **vacía**. Pero trataba
también un **cero** como hueco, y en un renglón bonificado al 100% ese cero es el dato: se reponía el
precio de lista. `RefrescarVentasEditadas::item()` tenía el mismo defecto en otra forma — calculaba
el subtotal como `cantidad × precio` sin mirar nunca las columnas de descuento.

Los dos quedaron corregidos, y ambos comandos ahora graban `venta_items.descuento_pct`.

**El alcance real no eran 27 sino 136 + 27.** El criterio de detección original
(`subtotal_sin_descuento = subtotal_con_descuento`) era demasiado angosto: dejaba afuera las ventas
donde el importador sí había separado los subtotales de **cabecera** pero igual no había bajado la
bonificación al renglón. El criterio correcto es el síntoma: `descuento > 0` y
`SUM(venta_items.subtotal_con_iva) ≠ ventas.total`. Con ese criterio apareció, entre otras, la venta
20288 ($1.346.035 — la más grande de todas), que la primera pasada no había visto.

**Comando**: `migracion:corregir-bonificacion` (`--aplicar`, `--dir=` para los detallado,
`--imports=`). Idempotente, con backup en `storage/app/`, y escribe con `DB::table` para no despertar
los observers de venta. Cubierto por `tests/Feature/BonificacionPorLineaTest.php` (8 tests).

Reconstruye por dos caminos: el export por ítem cuando trae los subtotales del renglón y alinea con
los ítems guardados; y la regla del 100% (total 0 y descuento igual al neto) cuando el archivo no
tiene el dato. **Si no puede resolver una venta, no la toca**: repartir un descuento adivinado sobre
renglones equivocados sería inventar datos.

**Resultado en la base nueva** (dos pasadas, 17/08/2026):

| | Ventas | Líneas | Baja del Precio Neto |
|---|---:|---:|---:|
| 1ª pasada (criterio angosto) | 27 | 66 | $1.733.682,37 |
| 2ª pasada (criterio por síntoma) | 28 | 107 | $2.013.261,64 |
| **Total corregido** | **55** | **173** | **$3.746.944,01** |

Control después de cada pasada: **total de ventas, cobros, movimientos de tesorería y movimientos de
stock idénticos al centavo**. Sólo bajó lo derivado de la línea (Precio Neto y Resultado del Informe
de Ventas, y las medidas del pivot).

### Lo que queda pendiente

**108 ventas por $963.441 siguen con el desglose sin cerrar**, y no se pueden reconstruir con los
archivos que hay:

- **91 son de 2021**, cuyo export por ítem **no pobla ninguna columna de subtotal** (§3.10). El dato
  simplemente no está en el archivo.
- Las otras 17 (2023-2026) tampoco alinean contra su export.

Para cerrarlas hace falta pedirle a Contagram un **"Informe de Ventas Detallado" de esos períodos**,
que sí trae `Subtotal sin Descuento` / `Subtotal con Descuento` por renglón — el mismo archivo que se
usó para el tramo 06→13/08/2026.

**Aparte, 5 ventas por $600.286 no cierran y NO tienen descuento**: es otra causa, no ésta. Las dos
más grandes son la 24477 y la 24478 (13/08/2026), donde el desglose queda **corto** contra el total en
vez de largo. Sin diagnosticar.
