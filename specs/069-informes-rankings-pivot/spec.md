# Feature Specification: Módulo Informes — Tanda 3 (Rankings y "Arma tu Informe")

**Feature Branch**: `069-informes-rankings-pivot`

**Created**: 2026-08-15

**Status**: Draft

**Input**: User description: "Módulo Informes — Tanda 3: Rankings y 'Arma tu Informe' (tablas dinámicas) para los informes de Ventas y Compras. Rankings en los dos informes. Se conserva el arrastre de dimensiones. 'Arma tu Informe' con guardado de vistas persistentes. Dato y Acción completos. **'Mostrar Como' queda fijo en Tabla**: se descartan las otras 7 opciones (mapa de calor, gráficos, histograma). No se construye la vista consolidada /graphs."

## Contexto y fuente de verdad

Las tandas 1 (spec 067) y 2 (spec 068) construyeron los **7 informes tabulares** del módulo. Falta lo
que el relevamiento llama el "motor de tablas dinámicas": las pestañas **Rankings** y **Arma tu
Informe** que Contagram monta sobre los informes de Ventas y de Compras.

| Tanda | Alcance | Estado |
|-------|---------|--------|
| 1 | Compras, Gastos, Cta Cte Proveedores | spec 067 ✅ |
| 2 | Ventas, Reporte Final | spec 068 ✅ |
| **3** | **Rankings + Arma tu Informe (Ventas y Compras)** | **esta spec** |

**Relevamiento base**: `docs/Informe-Modulo-Informes-2026-08-14/informe_modulo_informes_texto.md`
(§3.4, §3.5, §4.2, §10, §11.7) y sus capturas:

| Fuente | Cubre |
|--------|-------|
| `Capturas/05_ranking_clientes_pivot_inicial.gif` | Ranking de Clientes: cruce inicial y los 3 selectores |
| `Capturas/06_ranking_mostrar_como_dropdown_8opciones.gif` | Las 8 opciones de "Mostrar Como" (de las que se conserva 1) |
| `Capturas/09_ranking_tabla_grafico_barras_y_dato_dropdown.gif` | Las 4 opciones de "Dato" |
| `Capturas/10_ranking_accion_dropdown_7opciones.gif` | Las 7 opciones de "Accion" |
| `Capturas/11_ranking_drag_drop_clientes_a_columnas.gif` | Arrastre de una dimensión de filas a columnas |
| `Capturas/12_arma_informe_pool_13dimensiones.gif` | El pool de 13 dimensiones sin asignar |
| `Capturas/13_arma_informe_tabla_cruzada_productos_x_clientes.gif` | Cruce productos × clientes armado a mano |
| `Capturas/14_modal_guardar_informe.gif` | Modal "Guardar Informe" con campo Descripción |
| `Capturas/15_arma_informe_dropdown_pestana_guardada_persistente.gif` | La vista guardada convertida en pestaña |
| `Ranking de Clientes 14-8-2026.xlsx` | Export: volcado fiel del cruce visible |

### Recorte deliberado: "Mostrar Como" queda fijo en Tabla

Contagram ofrece **8 modos de render** para el mismo cruce: Tabla, Tabla con Gráfico de Barras, Mapa
de Calor, Mapa de Calor por Fila, Mapa de Calor por Columna, Gráfico de Líneas, Gráfico de Barras e
Histograma.

**Se construye únicamente "Tabla".** Es una decisión explícita y reafirmada del cliente (15/08/2026):
esos modos no se usan nunca, y multiplicar formas de ver el mismo dato sólo agranda la aplicación sin
aportar valor. Por el mismo motivo **no se construye la vista consolidada de gráficos `/graphs`**
(§10 del relevamiento: 4 paneles de gráfico apilados).

Es la **primera divergencia funcional** del módulo respecto de Contagram —las de las tandas 1 y 2
eran estructurales o de formato de archivo—, así que queda registrada como tal: no es una omisión ni
una simplificación por costo, es alcance recortado a pedido.

### Otras divergencias, heredadas de las tandas anteriores

1. **No se construye la landing de tarjetas `/reports`**: cada informe es un ítem propio del
   desplegable "Informes" del sidebar, con URL real y sin fragmentos `#`.
2. **Export con el patrón del módulo**: el "Exportar Excel" de Contagram para estas pestañas es 100%
   del lado del cliente (§11.7); acá el archivo debe reflejar el cruce visible, y cómo se produce se
   resuelve en el plan.

## Clarifications

### Session 2026-08-15

Las cuatro decisiones de alcance las tomó el cliente antes de especificar y son firmes: (a) Rankings
en Ventas **y** Compras; (b) se conserva el arrastre de dimensiones; (c) "Arma tu Informe" va con
guardado persistente; (d) "Dato" y "Accion" completos. Las siguientes son ambigüedades residuales
resueltas con criterio propio durante la cadena, sin interrumpir al cliente (regla "cadena completa
sin preguntar" de CLAUDE.md).

- Q: Si "Mostrar Como" queda con una sola opción, ¿se muestra el selector? → A: **no se renderiza**.
  Un desplegable con una única opción es ruido y sugiere que hay algo más para elegir. Los selectores
  "Dato" y "Accion" sí se muestran siempre.
- Q: ¿Las vistas guardadas de "Arma tu Informe" son por usuario o compartidas por todo el negocio? →
  A: **compartidas**. El CRM es single-tenant y de un solo negocio; un informe que alguien armó le
  sirve a cualquiera del equipo, y separarlas por usuario obligaría a rearmar el mismo cruce varias
  veces. Se registra **quién** la creó, para poder auditar y para mostrarlo en el listado.
- Q: ¿Una vista guardada en Ventas aparece también en Compras? → A: **no**. Cada vista pertenece al
  informe donde se armó, porque sus dimensiones y medidas son las de ese informe (un cruce por
  Vendedor no existe en Compras). Las pestañas guardadas de un informe sólo se listan en ese informe.
- Q: ¿Qué pasa si se borra una categoría, cliente o producto usado en una vista guardada? → A: **la
  vista sigue funcionando**: lo guardado son las **dimensiones** del cruce (p. ej. "productos ×
  clientes"), no los valores concretos, así que el cruce se recalcula con los datos vigentes.
- Q: ¿Los rankings respetan el panel de filtros del informe? → A: **sí**. La pestaña de Rankings vive
  dentro del informe y comparte su rango de emisión y su panel de filtros; cambiar un filtro
  recalcula el cruce. Es lo que hace que el ranking sea consistente con el detalle de la misma
  pantalla.
- Q: ¿Qué comprobantes alimentan los rankings de Ventas? → A: los **no eliminados**, con el mismo
  criterio de signo que el Informe de Ventas de la tanda 2 (NC en negativo, ND en positivo).
- Q: ¿Se replica el ícono de embudo por columna del pivot? → A: **sí**, es parte de la interacción
  relevada (§3.4) y no un modo de visualización: permite excluir valores puntuales del cruce sin
  tocar el panel de filtros.

### Session 2026-08-15 (segunda pasada — barrido de ambigüedades)

Ambigüedades detectadas al revisar la spec contra el modelo de datos y contra las specs 067/068,
resueltas con el mismo criterio.

- Q: ¿Cuál es la **unidad de fila** del conjunto que alimenta el cruce? → A: **una fila por ítem**,
  el mismo conjunto que ya produce el detalle de cada informe (specs 067 y 068). Es lo que hace
  posible cruzar por producto o por tipo de producto —dimensiones que sólo existen a nivel línea— y
  lo que obliga a que "Cantidad de Ventas" sea un **conteo de comprobantes distintos** y no de
  líneas, mientras "Cantidad de Productos" es la **suma de las cantidades** de las líneas. Sin esta
  distinción los dos conteos darían lo mismo, que es justamente lo que no son.
- Q: ¿"Total Venta" y "Total Venta sin impuestos" a nivel línea, si "Total Comprobante" se repite por
  fila? → A: se miden **a nivel línea**: "Total Venta" es el importe de la línea con impuestos y
  "Total Venta sin impuestos" su neto. El total del comprobante **no** es una medida disponible,
  porque sumarlo por fila lo contaría una vez por ítem — la misma trampa ya documentada en la tanda 2
  (data-model 068 §Invariantes, punto 3).
- Q: "cantidades" y "descuento en %" figuran entre las 13 dimensiones, pero son números continuos.
  ¿Se agrupa por cada valor distinto? → A: **sí, por valor exacto**, tal como hace el origen. Son
  dimensiones de baja cardinalidad en la práctica (las cantidades suelen ser 1, 2, 3…; los descuentos,
  0%, 10%, 20%). No se construyen rangos ni buckets: sería inventar una regla que el relevamiento no
  muestra.
- Q: ¿Cuál es el cruce inicial de cada ranking? → A: la **dimensión propia del ranking en filas** y
  **fecha de emisión → año → mes en columnas**, como se relevó en "Ranking de Clientes" (§3.4). El
  usuario puede reacomodarlo desde ahí.
- Q: ¿Cuántas dimensiones se pueden apilar en un eje? → A: **sin límite fijo**; el límite real es el
  tamaño del cruce resultante, que ya tiene su propia regla (abajo).
- Q: "advierte cuando el cruce supera un tamaño razonable" — ¿cuál es el número? → A: **1.000
  columnas**. Pasado ese punto se muestra un aviso y no se renderiza el cruce, invitando a acotar el
  rango o a mover una dimensión de columnas a filas. Las filas no tienen tope porque scrollean
  verticalmente sin degradar la pantalla; las columnas sí la rompen.
- Q: ¿Crear y borrar vistas guardadas exige un permiso aparte? → A: **no**, alcanza con el mismo
  permiso de informes. Una vista guardada es configuración de presentación, no información contable:
  no expone ni modifica ningún dato que ese permiso no habilite ya a ver.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver un ranking predefinido (Priority: P1)

Como responsable del negocio quiero entrar a Informes → Ventas → Rankings → Clientes y ver de un
vistazo quién me compró más en el período, sin tener que armar nada.

**Why this priority**: es el uso concreto y cotidiano de la feature ("¿quiénes son mis mejores
clientes?"). Los 9 rankings predefinidos entregados solos ya son un MVP con valor completo.

**Independent Test**: cargar ventas de varios clientes en un período, entrar a la pestaña y verificar
que el cruce muestra un cliente por fila con su total, y que cambiar el rango lo recalcula.

**Acceptance Scenarios**:

1. **Given** ventas de 5 clientes en el mes, **When** el usuario abre Informes → Ventas → Rankings →
   Clientes, **Then** ve una tabla cruzada con los clientes en filas, el desglose temporal en
   columnas y una fila/columna de Totales.
2. **Given** el ranking abierto, **When** el usuario cambia el rango de emisión a "Año actual",
   **Then** el cruce se recalcula sin recargar la página.
3. **Given** el ranking abierto, **When** el usuario aplica un filtro del panel del informe,
   **Then** el cruce refleja el mismo conjunto filtrado que el detalle.
4. **Given** un período sin ventas, **When** se consulta un ranking, **Then** se ve un mensaje de
   vacío, sin errores ni una tabla rota.
5. **Given** el Informe de Compras, **When** el usuario abre Rankings → Proveedores, **Then** ve el
   ranking equivalente construido sobre las compras del período.

---

### User Story 2 - Reacomodar y exportar un ranking (Priority: P2)

Como usuario quiero cambiar qué mide el ranking y cómo está cruzado —arrastrando una dimensión de
filas a columnas, cambiando el dato o la agregación— y llevarme el resultado en Excel.

**Why this priority**: es lo que convierte un ranking fijo en una herramienta de análisis. Depende de
US1 para tener un cruce del que partir.

**Independent Test**: partir de un ranking, mover una dimensión, cambiar Dato y Acción, y verificar
que la tabla se rearma y que el Excel descargado coincide con lo que está en pantalla.

**Acceptance Scenarios**:

1. **Given** el Ranking de Clientes con clientes en filas, **When** el usuario arrastra la ficha
   "Clientes" al área de columnas, **Then** la tabla se reconstruye al instante con los clientes como
   columnas.
2. **Given** un ranking con Dato "Total Venta", **When** el usuario elige Acción "Suma como Fracción
   del Total", **Then** cada celda pasa a mostrarse como porcentaje y los porcentajes suman 100%.
3. **Given** un ranking con Dato "Total Venta" y las 7 acciones disponibles, **When** el usuario
   cambia Dato a "Cantidad de Ventas", **Then** la lista de Acción se reduce a "Suma" únicamente.
4. **Given** un cruce cualquiera en pantalla, **When** el usuario presiona "Exportar Excel",
   **Then** el archivo descargado reproduce exactamente ese cruce, con sus encabezados y totales.
5. **Given** un ranking con varias columnas, **When** el usuario usa el embudo de una columna para
   excluir un valor, **Then** ese valor sale del cruce y los totales se recalculan.
6. **Given** cualquier ranking, **When** el usuario busca cómo mostrarlo, **Then** **no** encuentra
   opciones de mapa de calor, gráficos ni histograma: la única presentación es la tabla.

---

### User Story 3 - Armar y guardar un informe a medida (Priority: P3)

Como usuario quiero armar un cruce que no está entre los rankings predefinidos —por ejemplo productos
× clientes— y guardarlo con un nombre para volver a consultarlo sin rearmarlo.

**Why this priority**: es la parte más flexible y la que menos se usa a diario, pero la que evita
tener que pedir un informe nuevo cada vez que aparece una pregunta distinta. Depende del mismo motor
de US1/US2.

**Independent Test**: abrir "Arma tu Informe", arrastrar dos dimensiones, guardarlo con un nombre,
recargar la pantalla y verificar que la pestaña sigue ahí con el mismo cruce.

**Acceptance Scenarios**:

1. **Given** el Informe de Ventas, **When** el usuario abre "Arma tu Informe" → "Crear Informe",
   **Then** ve las 13 dimensiones como fichas sueltas en una zona sin asignar, y las áreas de filas y
   de columnas vacías.
2. **Given** el builder abierto, **When** el usuario arrastra "productos" a filas y "clientes" a
   columnas, **Then** se arma la tabla cruzada con el dato de cada combinación y sus totales.
3. **Given** un cruce armado, **When** el usuario presiona "Guardar informe" y escribe una
   descripción, **Then** la vista queda guardada y la pestaña pasa a llamarse con esa descripción.
4. **Given** una vista guardada, **When** el usuario recarga la pantalla o entra al día siguiente,
   **Then** la pestaña sigue disponible y al abrirla reproduce el mismo cruce con datos actualizados.
5. **Given** una vista guardada que ya no sirve, **When** el usuario la elimina, **Then** la pestaña
   desaparece y ningún dato de ventas se ve afectado.
6. **Given** una vista guardada en el Informe de Ventas, **When** el usuario abre el Informe de
   Compras, **Then** esa vista **no** aparece entre las pestañas de Compras.

---

### Edge Cases

- **Período vacío**: los rankings y el builder muestran un mensaje de vacío, nunca un error ni una
  tabla con encabezados sin filas.
- **Registro sin la dimensión cruzada** (venta sin categoría, sin vendedor; producto sin tipo): cae
  en un rótulo explícito "Sin categoría" / "Sin vendedor" / "Sin tipo de producto", no se omite.
- **Cruce que genera muchísimas columnas** (p. ej. productos × clientes con catálogos grandes): la
  tabla scrollea horizontalmente; superadas las **1.000 columnas** no se renderiza y se avisa al
  usuario que acote el rango o mueva una dimensión a filas, en vez de colgar la pantalla.
- **Dimensión numérica continua** ("cantidades", "descuento en %"): se agrupa por valor exacto, sin
  rangos. Si eso produjera demasiadas columnas, aplica el tope de arriba.
- **Ninguna dimensión asignada** en el builder: se muestra un único total general, sin error.
- **Descripción duplicada al guardar**: se acepta, pero el sistema avisa que ya existe una vista con
  ese nombre en ese informe.
- **Descripción vacía**: no se guarda; se pide una descripción.
- **Acción no aplicable al Dato elegido**: al cambiar el Dato a un conteo, si la Acción vigente
  dejaba de tener sentido, se vuelve automáticamente a "Suma" en vez de quedar en un estado inválido.
- **Ítem de venta sin producto asociado** (concepto libre): entra al cruce con su descripción.
- **Notas de crédito y débito**: aportan al cruce con el mismo signo que en el Informe de Ventas
  (NC negativo, ND positivo), para que el ranking concilie con los KPIs de la pestaña de detalle.

## Requirements *(mandatory)*

### Estructura de pestañas

- **FR-001**: Los informes de Ventas y de Compras DEBEN pasar a tener una **barra de pestañas** con:
  el detalle (activo por defecto, con el nombre del informe), **Rankings** (desplegable) y **Arma tu
  Informe** (desplegable), más una pestaña por cada vista guardada de ese informe.
- **FR-002**: Cambiar de pestaña NO DEBE recargar la página ni perder el rango de emisión ni los
  filtros aplicados.
- **FR-003**: El desplegable **Rankings** DEBE ofrecer, en el Informe de Ventas, 5 vistas: Clientes,
  Categorías, Productos, Tipo de Producto y Vendedores; y en el Informe de Compras, 4: Proveedores,
  Categorías, Productos y Tipo de Producto.
- **FR-004**: Cada pestaña DEBE tener su propia URL real, sin fragmentos `#`, de modo que se pueda
  compartir el enlace de un ranking o de una vista guardada.

### Motor de tabla dinámica

- **FR-010**: Toda tabla dinámica (rankings y vistas armadas) DEBE renderizarse como **tabla
  cruzada** con dimensiones en filas y en columnas, celdas con el valor agregado, y fila y columna de
  **Totales**.
- **FR-011**: El usuario DEBE poder **arrastrar dimensiones** entre la zona sin asignar, el área de
  filas y el área de columnas, en cualquier orden y combinación, y la tabla DEBE reconstruirse al
  soltar.
- **FR-011b**: El conjunto que alimenta el cruce DEBE ser el mismo que produce el detalle del
  informe, con **una fila por ítem**. Es lo que permite cruzar por producto o tipo de producto, que
  son dimensiones de línea.
- **FR-012**: El selector **"Dato"** DEBE ofrecer 4 opciones en Ventas: Total Venta, Total Venta sin
  impuestos, Cantidad de Productos y Cantidad de Ventas; y sus equivalentes en Compras.
- **FR-012b**: Las cuatro medidas DEBEN definirse sin ambigüedad: **Total Venta** es el importe de la
  línea con impuestos; **Total Venta sin impuestos**, su neto; **Cantidad de Productos**, la suma de
  las cantidades de las líneas; y **Cantidad de Ventas**, el conteo de **comprobantes distintos**
  (no de líneas). El total del comprobante NO DEBE ofrecerse como medida: se repite en cada línea y
  sumarlo lo contaría una vez por ítem.
- **FR-013**: El selector **"Accion"** DEBE ofrecer 7 opciones cuando el Dato es un importe: Suma,
  Promedio, Mínimo, Máximo, Suma como Fracción del Total, Suma como Fracción por Línea y Suma como
  Fracción por Columna.
- **FR-014**: Cuando el **Dato es un conteo** (Cantidad de Ventas, Cantidad de Productos), la lista
  de "Accion" DEBE reducirse a la única opción **"Suma"**, replicando el comportamiento relevado; las
  agregaciones estadísticas no tienen sentido sobre un conteo de filas.
- **FR-015**: El usuario DEBE poder **ordenar** por el encabezado de una columna (ascendente y
  descendente) y **excluir valores puntuales** de una dimensión mediante el filtro de columna.
- **FR-016**: Las tres variantes de fracción DEBEN expresarse como porcentaje, y los porcentajes de
  "Fracción del Total" DEBEN sumar 100% sobre el conjunto mostrado.
- **FR-017**: El cruce DEBE calcularse siempre sobre el **conjunto filtrado completo** del informe
  (rango de emisión + panel de filtros), no sobre una página del detalle.
- **FR-018**: Los registros sin valor en una dimensión cruzada DEBEN agruparse bajo un rótulo
  explícito ("Sin categoría", "Sin vendedor", "Sin tipo de producto"), nunca descartarse.
- **FR-019**: Cada ranking DEBE abrir con su **dimensión propia en filas** y **fecha de emisión → año
  → mes en columnas**, tal como se relevó; desde ahí el usuario reacomoda.
- **FR-019b**: Cuando un cruce supere las **1.000 columnas**, el sistema NO DEBE renderizarlo: DEBE
  mostrar un aviso invitando a acotar el rango o a mover una dimensión de columnas a filas. Las filas
  no llevan tope, porque scrollean sin degradar la pantalla.

### Presentación: sólo tabla

- **FR-020**: La única presentación DEBE ser la **tabla**. NO se construyen "Tabla con Gráfico de
  Barras", "Mapa de Calor", "Mapa de Calor por Fila", "Mapa de Calor por Columna", "Gráfico de
  Líneas", "Gráfico de Barras" ni "Histograma".
- **FR-021**: Al quedar "Mostrar Como" con una sola opción, el selector **NO DEBE renderizarse**; los
  selectores "Dato" y "Accion" sí se muestran siempre.
- **FR-022**: NO se construye la vista consolidada de gráficos `/graphs` ni ningún panel de gráficos
  en el módulo Informes.

### "Arma tu Informe" y vistas guardadas

- **FR-030**: El builder DEBE presentar las **13 dimensiones** relevadas como fichas en una zona sin
  asignar: fecha de emisión, mes, año, categorías, clientes, tipos de factura, vendedores, productos,
  tipos de producto, proveedores, cantidades, descuento en % y etiquetas. En el Informe de Compras
  DEBE ofrecer el conjunto equivalente para compras.
- **FR-031**: El usuario DEBE poder **guardar** el cruce armado mediante un modal con un campo
  "Descripción" y botones Cancelar / Guardar.
- **FR-032**: Al guardar, la vista DEBE quedar como **pestaña persistente** del informe, rotulada con
  la descripción ingresada, y seguir disponible después de recargar o de cerrar sesión.
- **FR-033**: Una vista guardada DEBE almacenar la **configuración del cruce** (dimensiones de fila y
  de columna, Dato y Acción), no los datos: al abrirla se recalcula con la información vigente y con
  el rango de emisión que el usuario tenga puesto.
- **FR-034**: Las vistas guardadas DEBEN ser **compartidas por todo el negocio** (no privadas por
  usuario), registrando quién las creó.
- **FR-035**: Una vista guardada DEBE pertenecer **al informe donde se armó** y no listarse en el
  otro.
- **FR-036**: El usuario DEBE poder **eliminar** una vista guardada; eliminarla no afecta ningún dato
  de ventas, compras ni de ningún otro módulo.
- **FR-037**: Guardar con la descripción vacía DEBE rechazarse con un aviso; guardar con una
  descripción ya usada en ese informe DEBE aceptarse pero avisando de la duplicación.

### Comunes al módulo

- **FR-040**: Las pestañas DEBEN compartir el **selector de rango "Emisión"** con sus 9 opciones y el
  **panel de filtros** del informe al que pertenecen; cambiar cualquiera recalcula el cruce sin
  recargar la página.
- **FR-041**: Cada ranking y cada vista armada DEBE ofrecer un botón **"Exportar Excel"** cuyo
  archivo reproduzca **exactamente el cruce visible** en ese momento, con sus encabezados y totales,
  incluidos los reacomodos, exclusiones de columna y la Acción elegida.
- **FR-042**: El acceso DEBE estar sujeto al **mismo permiso de informes** ya vigente —crear y borrar
  vistas guardadas incluidos, sin un permiso aparte, porque una vista es configuración de
  presentación y no expone ningún dato que ese permiso no habilite ya a ver. Sin el permiso, ni las
  pestañas ni sus endpoints deben responder datos ni aceptar escrituras.
- **FR-043**: Los avisos y errores DEBEN mostrarse con las notificaciones toast del template, sin
  alerts nativos ni recargas; el modal de guardado DEBE ser un modal del template enviado por AJAX.
- **FR-044**: Ningún comprobante **dado de baja** debe computar en ningún cruce.
- **FR-045**: Las notas de crédito y débito DEBEN aportar al cruce con el mismo signo que en el
  informe de detalle correspondiente (NC negativo, ND positivo).

### Fuera de alcance

- **FR-050**: NO se construyen los 7 modos de presentación descartados ni `/graphs` (ver FR-020 a
  FR-022).
- **FR-051**: NO se construye la landing de tarjetas `/reports`.
- **FR-052**: NO se agregan rankings a los otros 5 informes del módulo (Gastos, Stock, las dos
  Cuentas Corrientes, Reporte Final): Contagram sólo los ofrece en Ventas y Compras.

### Key Entities

- **Vista guardada**: cruce personalizado con su descripción, el informe al que pertenece, la
  configuración de dimensiones de fila y columna, el Dato, la Acción y quién la creó.
- **Dimensión**: eje por el que se puede agrupar un cruce (cliente, producto, categoría, mes,
  vendedor, etiqueta…), disponible para filas o para columnas.
- **Dato**: magnitud que se mide en cada celda (importe con o sin impuestos, conteo de comprobantes,
  conteo de productos).
- **Acción**: forma de agregar el Dato dentro de cada celda (suma, promedio, mínimo, máximo,
  fracciones).
- **Ranking**: cruce predefinido, con una dimensión fija de partida, que el usuario puede reacomodar.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede llegar a "quiénes fueron mis mejores clientes del período" en **menos
  de 4 interacciones** desde el sidebar (Informes → Ventas → Rankings → Clientes).
- **SC-002**: Reacomodar un cruce (arrastrar una dimensión, cambiar Dato o Acción) actualiza la tabla
  de forma **inmediata y perceptible**, sin que el usuario perciba una espera.
- **SC-003**: Un cruce sobre un año de operación con miles de comprobantes se presenta en **menos de
  3 segundos**.
- **SC-003b**: Ningún cruce puede colgar la pantalla: superadas las 1.000 columnas el sistema avisa
  en vez de intentar dibujarlo.
- **SC-004**: El archivo exportado coincide **al centavo y celda por celda** con el cruce que el
  usuario tiene en pantalla, incluidas exclusiones de columna y la Acción elegida.
- **SC-005**: Una vista guardada sigue disponible y reproduce el mismo cruce **el 100% de las veces**
  tras recargar, cerrar sesión o entrar desde otro navegador.
- **SC-006**: Los totales de un ranking concilian **al centavo** con los KPIs de la pestaña de
  detalle del mismo informe, con el mismo rango y los mismos filtros.
- **SC-007**: Un período sin movimientos se resuelve con un mensaje de vacío y **cero errores** en
  pantalla o en el export.
- **SC-008**: En ninguna pantalla del módulo existe una opción para presentar el cruce como gráfico o
  mapa de calor.

## Assumptions

- Se reutiliza toda la infraestructura de las tandas 1 y 2: selector de rango de emisión, panel de
  filtros de cada informe, el permiso de acceso a informes y las consultas de detalle ya construidas.
  El cruce se alimenta del **mismo conjunto filtrado** que el detalle, para que concilien (SC-006).
- Las dimensiones ofrecidas se resuelven con datos que el CRM ya tiene (cliente, producto, categoría,
  vendedor, proveedor, tipo de producto, etiqueta, fecha, cantidad, descuento); no se introducen
  clasificadores nuevos.
- La feature es de **sólo lectura sobre los datos del negocio**: lo único que crea o borra son las
  vistas guardadas, que son configuración de presentación y no información contable.
- El Informe de Compras ya construido (spec 067) recibe una barra de pestañas; su pestaña de detalle
  no cambia de comportamiento.
- El equivalente de "Dato" en Compras se deriva de las mismas magnitudes del Informe de Compras
  (Total Compra, total sin impuestos, cantidad de productos, cantidad de compras).
