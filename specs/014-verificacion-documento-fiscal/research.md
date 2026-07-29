# Research: Verificación de documento fiscal (CUIT/CUIL)

No hay incógnitas técnicas de stack (proyecto Laravel monolítico ya establecido, sin dependencias
nuevas). Este documento registra las decisiones de diseño tomadas para resolver la spec, no
resoluciones de `NEEDS CLARIFICATION` de Technical Context (no quedó ninguna).

## R1 — El botón "Verificar" reusa `CuitValido::esValido()` por AJAX, no reimplementa el algoritmo en JS

**Decisión**: el botón dispara una llamada AJAX a un endpoint liviano que llama a
`App\Rules\CuitValido::esValido()` (el mismo método estático que ya usa la validación bloqueante de
`ReglasCliente`/`ReglasProveedor`) y devuelve `{valido: true|false}`.

**Rationale**: una sola fuente de verdad para "qué es un CUIT/CUIL válido". Si el algoritmo alguna vez
cambia (ej. ARCA agrega un prefijo nuevo), sólo hay que tocar `CuitValido.php` — el botón, la
validación al guardar y la conversión automática de Mercado Libre quedan sincronizados solos (FR-008).
El costo de la llamada AJAX es despreciable (endpoint local, sin I/O externo).

**Alternativas consideradas**: reimplementar el algoritmo módulo 11 en JavaScript para verificación
100% cliente sin round-trip. Rechazada: duplica lógica fiscal en dos lenguajes con riesgo real de que
diverjan silenciosamente (alguien corrige un caso borde en PHP y se olvida del JS, o viceversa) — el
ahorro de una llamada AJAX local no justifica ese riesgo en una regla con impacto fiscal.

## R2 — Un endpoint por recurso (`clientes/verificar-documento`, `proveedores/verificar-documento`), no uno genérico compartido

**Decisión**: cada controller (`ClienteController`, `ProveedorController`) expone su propia acción
`verificarDocumento()`, ambas delegando en `CuitValido::esValido()`.

**Rationale**: sigue la convención ya establecida en el proyecto de rutas de utilidad por módulo
(`clientes/opciones`, `clientes/stats`, `proveedores/opciones`, etc. — ver `routes/web.php:50-82`).

**Corrección (hallazgo I2 de `/speckit-analyze`)**: la primera versión de este documento justificaba
la decisión diciendo que así "se mantiene el gating de permisos alineado con el módulo" — pero
`routes/web.php` muestra que las rutas de Clientes/Proveedores **no tienen ningún middleware
`permiso:`** hoy (a diferencia de Ventas/Compras/Tesorería, que sí lo usan vía
`Route::middleware('permiso:ventas.ver')->...`) — sólo el `auth` global de la app. No hay gating por
módulo que alinear todavía. La decisión de mantener un endpoint por recurso sigue siendo la correcta,
simplemente por **consistencia de convención de rutas** (todas las utilidades de un recurso viven
junto a ese recurso) y para que, el día que Clientes/Proveedores sí sumen su propio `permiso:` (fuera
de alcance de esta feature), el endpoint de verificación ya esté en el lugar correcto para heredarlo
sin tener que moverlo.

**Alternativas consideradas**: un único endpoint `/utilidades/verificar-documento` compartido.
Rechazada por romper la convención de "cada utilidad vive junto a su recurso" que ya sigue el resto
del proyecto, para ahorrar ~10 líneas de código duplicado (dos métodos de controller triviales, una
línea cada uno).

## R3 — El auto-formato con guiones vive enteramente en el frontend; no hace falta tocar el backend

**Decisión**: el input mask (agregar guiones mientras se tipea) se implementa en
`resources/js/clientes.js`/`proveedores.js`. El valor que efectivamente viaja en el submit puede
incluir guiones sin problema.

**Rationale**: `ReglasCliente::normalizarCuit()` / el equivalente en `ReglasProveedor` **ya** hacen
`preg_replace('/\D/', '', ...)` antes de validar y guardar — el backend ya tolera y limpia guiones.
Cero cambios de backend necesarios para esta parte.

**Alternativas consideradas**: que el JS limpie los guiones antes de enviar el formulario. Descartada
por ser trabajo redundante — el backend ya lo hace, y duplicarlo no aporta nada.

## R4 — Un único punto de saneamiento, en `DerivadorComprobante::derivar()`, antes de cada `return`; `ResolutorCliente` no cambia

**Decisión**: se agrega un helper privado en `DerivadorComprobante`, algo como
`sanearDocumento(?string $tipo, ?string $numero): array{tipo: ?string, numero: ?string}`, que devuelve
`[null, null]` si `$tipo` es CUIT/CUIL y `$numero` falla `CuitValido::esValido()`, o los valores tal
cual en cualquier otro caso (DNI/Pasaporte/CDI, o ya vacíos). Se aplica ese helper a `doc_tipo`/
`doc_numero` **antes de construir el array que devuelve `derivar()`, en los dos `return` que hoy
propagan el documento crudo** (la rama con condición de IVA informada, y la rama FR-040c de
aproximación por documento) — la tercera rama (FR-040a, sin ningún dato) ya devuelve `null`/`null` y
no necesita tocarse.

`ResolutorCliente::crearCliente()` y `completarDatosFiscalesSinPisar()` **no cambian**: ya consumen
`$datosFiscales['doc_tipo']`/`['doc_numero']` tal cual se los da `derivar()`, así que una vez que ese
array sólo contiene documentos válidos (o `null`), ambos quedan protegidos automáticamente, sin
duplicar la validación en un segundo archivo.

**Por qué este diseño y no el descartado en la primera vuelta** (llamar a `CuitValido::esValido()` por
separado dentro de `ResolutorCliente::crearCliente()`, ver historial de este documento): habría sido
una segunda copia de la misma llamada en un segundo archivo, y además dejaba sin cubrir
`completarDatosFiscalesSinPisar()` (el caso de un comprador que ya existe como Cliente y el mecanismo
completa campos vacíos — ver Edge Cases de spec.md, "Comprador de Mercado Libre que ya existe como
Cliente"). Saneando en el único choke-point donde `derivar()` construye su array de salida, los tres
consumidores (`crearCliente`, `completarDatosFiscalesSinPisar`, y la propia derivación de
`tipo_comprobante` dentro de la rama FR-040c) quedan cubiertos con un solo cambio en un solo archivo —
y de paso resuelve FR-005/FR-006 "gratis": si `doc_tipo` queda en `null` porque el número no
verificó, el `... === 'CUIT' ? 'A' : 'B'` de la rama FR-040c ya da `'B'` sin necesitar una rama
especial nueva.

**Alternativas consideradas**:
1. Descartar sólo `doc_numero` y conservar `doc_tipo` — rechazada, deja `tipo_documento = "CUIT"` con
   `cuit = null`, un estado contradictorio (¿por qué dice CUIT si no hay CUIT?).
2. Validar en dos archivos separados (`DerivadorComprobante` para el comprobante,
   `ResolutorCliente::crearCliente()` para la persistencia) — rechazada tras revisar el flujo completo:
   duplica la llamada sin necesidad y deja `completarDatosFiscalesSinPisar()` sin cubrir (ver arriba).
3. Sanear sólo dentro de la rama FR-040c y dejar la rama de condición de IVA informada sin tocar —
   rechazada por CHK004 (checklist de consistencia fiscal): esa rama también alimenta la creación del
   Cliente con el documento crudo, así que sí importa aunque no participe en derivar el comprobante.
