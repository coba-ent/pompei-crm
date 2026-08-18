# Research: Dashboard filtrado por permisos

No quedaron `NEEDS CLARIFICATION` en el Technical Context del plan — el stack, el mecanismo de
permisos y el patrón de testing ya existen en el proyecto y se reutilizan tal cual. Este documento
registra las decisiones de diseño tomadas para resolver el "cómo" del filtrado.

## Decisión 1: Dónde vive la lógica de "qué rubros puede ver este usuario"

**Decisión**: Un método privado único en `DashboardController` (p. ej. `permisosRubros(User $user): array`)
que devuelve un array asociativo `['ventas' => bool, 'otros_ingresos' => bool, 'compras' => bool,
'gastos' => bool, 'clientes' => bool, 'productos' => bool, 'tesoreria' => bool]`, calculado una vez
por request con `$user->tienePermiso($codigo)` para cada uno de los 7 códigos. Cada uno de los 5
métodos del controller (`index`, `kpis`, `totales`, `graficoMensual`, `donas`, `rankings`) lo
invoca al principio y lo usa para condicionar qué queries ejecuta y qué claves arma en la
respuesta.

**Rationale**: Evita duplicar 7 llamadas a `tienePermiso()` en cada uno de los 5 métodos; centraliza
el mapeo permiso→rubro en un solo lugar, así si mañana cambia el catálogo de permisos sólo se toca
ese método. `tienePermiso()` ya resuelve el caso Admin (devuelve `true` para todo vía `esAdmin()`),
así que no hace falta ninguna rama especial para Admin.

**Alternativas consideradas**:
- Un Service/Value Object dedicado (`DashboardPermisos`): descartado por sobre-ingeniería — la
  lógica es un array de 7 booleans, no amerita una clase nueva ni inyección de dependencias
  adicional, dado el principio de no agregar abstracciones sin necesidad real.
- Middleware por endpoint (`permiso:ventas.ver`) como en el resto de la app: descartado porque acá
  el requisito NO es "bloquear el endpoint sin el permiso" (eso daría 403 y rompería la carga
  parcial del Dashboard) sino "omitir sólo esa parte de la respuesta" — el endpoint sigue
  respondiendo 200 con lo que sí corresponde.

## Decisión 2: Cómo se refleja la ausencia de un rubro en el JSON de respuesta

**Decisión**: Las claves de rubros sin permiso se omiten directamente del array antes de
`response()->json(...)` (usando `array_filter`/construcción condicional), no se envían con `null`
ni con `0`. El frontend, al procesar la respuesta, sólo pinta/instancia el widget de un rubro si la
clave está presente.

**Rationale**: Cumple FR-009 al pie de la letra — la clave ausente es indistinguible de "este
rubro no aplica", mientras que enviar `0` o `null` seguiría siendo una señal indirecta (y en el
caso de "Resultado" sería directamente engañoso, ver Decisión 3). También es más barato: si la
clave no se arma, la query de ese rubro directamente no se ejecuta (ver Decisión 1).

**Alternativas consideradas**:
- Enviar el valor real igual y ocultar sólo en el frontend: descartado explícitamente por la spec
  (US2) — es la fuga de datos que se está corrigiendo.
- Enviar `null` en vez de omitir la clave: descartado por ser innecesariamente distinto de "omitir",
  sin ninguna ventaja, y complica el chequeo en JS (`if (data.compras !== undefined)` es más simple
  y ya es el patrón idiomático para "esta clave no vino").

## Decisión 3: KPI "Resultado" combinado

**Decisión**: `resultado` en la respuesta de `kpis` sólo se calcula y se incluye si el usuario tiene
los 4 permisos de rubro simultáneamente (`ventas.ver`, `otros-ingresos.ver`, `compras.ver`,
`gastos.ver`). Ya está fijado como FR-003 en la spec; acá se registra el motivo técnico: el cálculo
actual (`ventasCreadas + otrosIngresos - compras - gastos`) mezcla los 4 sub-totales en una sola
cifra, así que no hay forma de "filtrar parcialmente" ese KPI sin que el número deje de representar
lo que dice representar.

**Rationale**: evita mostrar un "Resultado" que en realidad es sólo "Ventas − Compras" (por
ejemplo) disfrazado de resultado neto del negocio — sería un dato engañoso, peor que no mostrarlo.

**Alternativas consideradas**:
- Mostrar el resultado parcial con un asterisco/aclaración de qué rubros incluye: descartado por
  scope creep — la spec pide ocultar, no rediseñar el KPI con anotaciones nuevas.

## Decisión 4: Cómo se oculta en el frontend (Blade + JS)

**Decisión**: El controller pasa a la vista `dashboard/index.blade.php` el mismo array de
`permisosRubros()` calculado en `index()`, y la vista envuelve cada bloque estático (Saldos,
Movimientos recientes, Cuentas a Cobrar/Pagar) en un `@if($permisos['tesoreria'])`. Para los
bloques que se llenan por AJAX (KPIs, Totales, gráfico, donas, rankings), el contenedor de cada
tarjeta/rubro individual también se envuelve en Blade con el permiso correspondiente — así el
HTML del contenedor ni siquiera existe en el DOM si falta el permiso, y el JS que puebla esa
tarjeta nunca se ejecuta contra un elemento inexistente (se guardan los `$('#id').length` guards ya
usuales en el proyecto, o directamente no se intenta seleccionar el elemento porque no está en el
array de tarjetas a poblar).

**Rationale**: Consistente con FR-010 ("ocultar completamente", no vacío/bloqueado) y con el patrón
ya usado en el proyecto de condicionar bloques de Blade por permiso (ver sidebar, header,
`_row_actions.blade.php` en Clientes/Proveedores).

**Alternativas consideradas**:
- Ocultar sólo vía CSS/JS (`display:none`) después de que el HTML ya se renderizó: descartado
  porque el HTML seguiría estando en el DOM (visible con "Ver código fuente"), lo cual no es
  "ocultar completamente" en espíritu, aunque el dato en sí no viaje si el backend ya omite la
  clave del JSON.

## Decisión 5: Testing

**Decisión**: Se agrega `tests/Feature/DashboardPermisosTest.php` con casos por cada User Story de
la spec (usuario con `ventas.ver` únicamente, usuario con `tesoreria.ver` únicamente, usuario sin
ningún permiso relevante, usuario Admin). Además, se extienden los tests Feature ya existentes de
cada endpoint (`DashboardKpisTest`, `DashboardTesoreriaResumenTest`, `DashboardGraficoMensualTest`,
`DashboardDonasTest`, `DashboardRankingsTest`) con al menos un caso de permiso parcial que verifique
que la clave del rubro sin permiso **no está presente** en el JSON (`assertArrayNotHasKey` /
`$response->assertJsonMissingPath(...)`), no sólo que valga 0.

**Rationale**: cumple el Principio IV de la constitución (testing donde hay dinero/importes) y
valida específicamente el requisito de seguridad (SC-001) de que el monto real nunca viaja en la
respuesta.
