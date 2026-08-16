# Phase 0 — Research: Informes Tanda 3 (Rankings, Arma tu Informe)

Cada punto resuelve una incógnita técnica de la spec. Formato: Decisión / Motivo / Alternativas.

## R1 — Motor de tabla dinámica: PivotTable.js con un único renderer

**Decisión**: se adopta **PivotTable.js** (`pivot.js` + `jquery-ui` para el drag & drop), la misma
librería sobre la que Contagram monta estas pestañas (confirmado por inspección del DOM, §11.7 del
relevamiento). Se vendoriza en `public/vendor/pivottable/` como el resto de las librerías del
template, no por npm, para seguir el patrón de pagelevel de `config/dz.php`.

**Clave del recorte**: PivotTable.js tiene los renderers **enchufables** (`$.pivotUtilities.renderers`).
Se registra **únicamente el renderer "Table"**. Los otros 7 modos de Contagram (mapa de calor, sus dos
variantes por fila/columna, tabla con barras, líneas, barras, histograma) viven en paquetes aparte
(`pivot.plotly.js`, los renderers de heatmap) que **directamente no se cargan**.

**Motivo**: hace que FR-020/FR-021 sea una propiedad **estructural** y no una opción escondida — los
modos descartados no existen en el bundle, no se pueden habilitar por error ni por inspección del
DOM, y no pesan. Además la librería trae de fábrica exactamente lo que la spec pide y sería costoso
reescribir: arrastre de dimensiones entre ejes, ordenamiento por encabezado, el embudo de exclusión
por columna (FR-015) y los 7 agregadores que mapean 1:1 con las opciones de "Accion" (FR-013).

**Alternativas descartadas**: (a) motor propio — reimplementar drag & drop, agregadores y matriz
cruzada es varias veces el trabajo de esta spec y sin ninguna ganancia; (b) traer PivotTable.js
completo y ocultar los renderers por CSS o por configuración — deja el código muerto en el bundle y
convierte el recorte en algo reversible por accidente, justo lo contrario de lo que pidió el cliente.

## R2 — Dónde se agrega el cruce: en el cliente, sobre un dataset proyectado

**Decisión**: el servidor devuelve el **conjunto filtrado ya proyectado** (una fila por ítem, sólo
las columnas que son dimensión o medida) y el cruce lo arma PivotTable.js **en el navegador**.

**Motivo**: FR-011 y SC-002 exigen que arrastrar una dimensión rearme la tabla *al instante*. Si cada
arrastre fuera al servidor, la interacción central de la feature quedaría a merced de la latencia. El
mismo argumento que justificó el simulador del Reporte Final en la tanda 2: cuando la interacción es
de exploración, los datos tienen que estar en memoria del cliente.

**Guarda de volumen**: el endpoint aplica un tope de **50.000 filas** al dataset. Superado, responde
con un aviso pidiendo acotar el rango en vez de mandar un payload que congele el navegador. Es una
guarda distinta —y anterior— al tope de 1.000 columnas de FR-019b, que actúa al renderizar.

**Alternativas descartadas**: agregar en SQL por cruce (rompe la instantaneidad y obliga a generar
SQL dinámico con `GROUP BY` variable, difícil de auditar); mandar el detalle completo sin proyectar
(arrastra columnas que ninguna dimensión usa y multiplica el payload).

## R3 — Exportación: la matriz se calcula en el cliente y la escribe el servidor

**Decisión**: al presionar "Exportar Excel", el front envía por **POST** la **matriz ya calculada**
que está viendo (encabezados de fila y columna, celdas, totales) más el rótulo del cruce; el servidor
la escribe con `App\Exports\Informes\HojaInforme`, la misma clase que ya formatea los cinco Excel del
módulo.

**Motivo**: es el único camino que satisface FR-041 ("el archivo reproduce exactamente el cruce
visible, incluidos reacomodos, exclusiones de columna y la Acción elegida") **sin duplicar lógica**.
El estado real del cruce —qué dimensión quedó en cada eje, qué valores excluyó el embudo, qué
agregador está activo— vive en el navegador; el archivo sale exacto por construcción. Y al escribirlo
en el servidor, estos exports quedan con el mismo encabezado y formato que los demás del módulo, en
vez de ser los únicos con otra apariencia.

**Alternativas descartadas**: (a) export 100% client-side con SheetJS, como hace Contagram — suma una
dependencia nueva sólo para escribir un xlsx que ya sabemos escribir, y rompe la consistencia visual
del módulo; (b) reagregar en el servidor a partir de la configuración del cruce — obliga a
reimplementar los 7 agregadores en PHP, con la garantía de que tarde o temprano divergen de los del
navegador y el archivo deje de coincidir con la pantalla, que es exactamente lo que SC-004 prohíbe.

**Nota**: es un POST, y es el primer POST del módulo Informes (las tandas 1 y 2 son sólo GET). No lo
convierte en un módulo de escritura: no persiste nada, sólo recibe la matriz para devolver un archivo.

## R4 — Vistas guardadas: una tabla nueva, `informes_vistas`

**Decisión**: se agrega **una** tabla, `informes_vistas`, con el informe al que pertenece, la
descripción, la configuración del cruce en una columna JSON y el usuario que la creó. Es la **única
migración** de esta spec.

**Motivo**: FR-032 pide persistencia real (la pestaña sobrevive a recargar y a cerrar sesión) y
FR-034 que sea compartida por todo el negocio. Guardar la **configuración** y no los datos (FR-033)
hace que la vista siga siendo válida cuando cambian clientes o productos, y mantiene la tabla chica.

**JSON y no columnas**: la configuración es un objeto de forma variable (listas de dimensiones por
eje, en orden, más exclusiones por dimensión). Normalizarlo serían tres tablas para algo que nunca se
consulta por sus partes: siempre se lee entero para reconstruir el cruce.

**Sin soft delete**: una vista guardada es configuración de presentación, no un documento con impacto
contable. El principio III de la constitución exige borrado lógico para documentos fiscales; esto no
lo es, y conservar vistas borradas sólo ensuciaría la barra de pestañas.

**Alternativas descartadas**: guardar en `localStorage` (no sobrevive a otro navegador ni se comparte
con el equipo, contra FR-034 y SC-005); una vista por usuario (obligaría a que cada uno rearme el
mismo cruce, y el CRM es de un solo negocio con un equipo chico).

## R5 — De dónde sale el dataset: se reusan las queries de detalle de las tandas 1 y 2

**Decisión**: el dataset del pivot se arma con **los mismos filtros y el mismo conjunto** que el
detalle de cada informe, encapsulado en dos servicios nuevos —`VentasPivotDataset` y
`ComprasPivotDataset`— que reutilizan `VentasInformeQuery` y `ComprasInformeQuery` en vez de escribir
consultas paralelas.

**Motivo**: SC-006 exige que los totales del ranking concilien al centavo con los KPIs de la pestaña
de detalle. La única forma de garantizarlo es que salgan del mismo lugar; dos consultas distintas
sobre las mismas tablas divergen apenas alguien toque una.

**Consecuencia**: `VentasInformeQuery` expone hoy `detalle()`, `kpis()` y `rango()`, pero sus métodos
de filtro son privados y su proyección no incluye las columnas que estas pantallas necesitan como
dimensión (mes, año, nombre de categoría, de vendedor, de tipo de producto, de proveedor, etiquetas,
descuento). Hay que **ampliar la proyección** con esas columnas y exponer un punto de entrada para el
dataset. `ComprasInformeQuery` está en la misma situación pero su `aplicarFiltros()` ya es público.

**Riesgo declarado**: es la primera vez que se modifican las queries de las tandas 1 y 2, que hoy
están en verde con 161 tests. Toda tarea que las toque debe correr `--filter=Informes` completo antes
de darse por cerrada, y las columnas nuevas se **agregan** al final de la proyección sin reordenar ni
cambiar las existentes, para no romper el `UNION ALL` ni los índices de columna del export.

## R6 — Pestañas con URL real y sin recarga

**Decisión**: cada pestaña tiene su **ruta real** (`/informes/ventas`,
`/informes/ventas/ranking/{dimension}`, `/informes/ventas/vista/{vista}`), y el cambio de pestaña se
resuelve por AJAX actualizando la barra de direcciones con `history.pushState`.

**Motivo**: resuelve la tensión entre FR-002 (cambiar de pestaña no recarga) y FR-004 (URL real,
compartible, sin fragmentos `#`). Entrar directo a la URL de un ranking la renderiza del lado del
servidor; navegar entre pestañas dentro de la pantalla no recarga pero deja la URL correcta. Respeta
además la regla del proyecto de no usar `ruta#fragmento` para navegación.

**Alternativas descartadas**: pestañas Bootstrap puras sin URL (no se puede compartir el enlace de un
ranking, contra FR-004); una página por pestaña con recarga completa (contra FR-002 y contra la regla
#2 de CLAUDE.md).

## R7 — "Accion" depende de "Dato"

**Decisión**: los 7 agregadores se mapean a los de PivotTable.js
(`Sum`, `Average`, `Minimum`, `Maximum`, `Sum as Fraction of Total`, `Sum as Fraction of Rows`,
`Sum as Fraction of Columns`), y nuestro wrapper **recorta la lista ofrecida** según el Dato elegido:
con un Dato de conteo queda sólo "Suma" (FR-014). Si la Acción vigente deja de ser aplicable al
cambiar el Dato, se vuelve automáticamente a "Suma" en vez de quedar en un estado inválido.

**Motivo**: replica el comportamiento relevado (§3.4, "hallazgo no documentado antes") y evita el
sinsentido de pedir el promedio de un conteo de filas.

## R8 — Rendimiento y tope de columnas

**Decisión**: dos guardas independientes. En el **servidor**, el dataset se corta a 50.000 filas
(R2). En el **cliente**, antes de renderizar se cuenta la cantidad de columnas que produciría el
cruce y, si supera **1.000**, se muestra el aviso de FR-019b en vez de dibujar.

**Motivo**: son dos fallas distintas. El payload gigante mata la carga; la matriz de miles de
columnas mata el render aunque el payload sea chico (productos × clientes con catálogos grandes es
justamente eso). Las filas no llevan tope porque scrollean sin degradar la pantalla.

## R9 — Dimensiones y medidas de cada informe

**Decisión**: las 13 dimensiones relevadas para Ventas se mapean así, y Compras recibe el conjunto
equivalente, sin inventar dimensiones que su modelo no tenga.

| Dimensión (Ventas) | Sale de | ¿Existe en Compras? |
|--------------------|---------|---------------------|
| fecha de emisión | fecha del comprobante | sí |
| mes / año | derivadas de esa fecha | sí |
| categorías | categoría del comprobante (por su raíz) | sí |
| clientes | cliente de la venta | **no** → proveedores |
| tipos de factura | tipo de comprobante fiscal | sí |
| vendedores | vendedor de la venta | **no** (Compras no tiene) |
| productos | producto de la línea | sí |
| tipos de producto | tipo del producto | sí |
| proveedores | proveedor del producto | sí (proveedor de la compra) |
| cantidades | cantidad de la línea | sí |
| descuento en % | descuento de línea | sí |
| etiquetas | etiquetas del comprobante | sí |

Las medidas ("Dato") son las cuatro de FR-012b en Ventas y sus equivalentes en Compras (Total Compra,
Total Compra sin impuestos, Cantidad de Productos, Cantidad de Compras).

**Motivo**: el relevamiento sólo documenta el pool de Ventas; el de Compras se deriva por analogía y
la ausencia de "vendedores" queda declarada en vez de inventada.

## R10 — Reutilización y qué NO se toca

**Decisión**: se reutilizan sin modificar `resources/js/rango-emision.js`,
`App\Exports\Informes\HojaInforme`, el permiso `informes.ver` y los paneles de filtro ya construidos
de los dos informes. **No** se tocan `ReporteFinalQuery`, `GastosInformeQuery`,
`CostoMercaderiaVendida` ni ninguna clase de export existente.

**Motivo**: la superficie de cambio sobre código ya en verde se mantiene acotada a lo que R5 obliga
(la proyección de las dos queries de detalle), y nada más.
