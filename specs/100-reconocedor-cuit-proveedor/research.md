# Research: Reconocedor de CUIT por ARCA en Proveedores

**Spec**: [spec.md](./spec.md) | **Fecha**: 2026-09-07

Este documento resuelve las decisiones técnicas abiertas antes del diseño. No hubo marcadores
`NEEDS CLARIFICATION` en el Technical Context: el stack está fijado por la constitución y el
comportamiento objetivo ya existe implementado en Cliente. Las decisiones de acá son de **diseño**
(cómo evitar una cuarta copia de la misma lógica) y de **alcance de la reutilización**.

---

## R1. ¿Extraer la consulta al padrón a un servicio compartido, o copiar el bloque a Proveedor?

**Decisión**: Extraer a un servicio de aplicación `App\Services\Arca\ConsultaPadron`, con un método
público que recibe un CUIT y devuelve `?ResultadoConsultaPadron`. Migrar a él los tres consumidores
existentes y usarlo desde Proveedor.

**Evidencia recogida**:

Hay hoy **tres** implementaciones privadas de `consultarPadron()`:

| Ubicación | Firma | Retorno | Diferencias |
|-----------|-------|---------|-------------|
| `App\Services\MercadoLibre\DerivadorComprobante:121` | `(?string $cuit)` | `?ResultadoConsultaPadron` | — |
| `App\Services\Tiendanube\ResolutorCliente:134` | `(?string $documento)` | `?ResultadoConsultaPadron` | — |
| `App\Http\Controllers\ClienteController:80` | `(string $cuit)` | `array` (para JSON) | agrega formateo de respuesta |

Las dos primeras son **idénticas línea por línea** (comparadas en el relevamiento): mismo guard de
11 dígitos, mismo `CertificadoFiscal::activo()`, misma secuencia de dos consultas, mismos `catch`.
La tercera comparte exactamente la misma secuencia y sólo difiere en que, en vez de devolver el
objeto, lo traduce a un array con `consultado`/`encontrado`/`mensaje` y pasa por `array_filter`.

Es decir: la lógica de **consulta** es una sola, repetida tres veces; lo que varía es la
**presentación** del resultado. Agregar Proveedor sin extraer produciría una cuarta copia y, dado
que ClienteController y ProveedorController necesitarían además el mismo formateo JSON, también una
segunda copia de la traducción.

**Rationale**:

- El costo de la duplicación ya es real y observable: las specs 037 y 047 tuvieron que tocar los
  tres lugares. Una corrección futura en el manejo de errores de ARCA hoy requiere cuatro ediciones
  coordinadas, con riesgo de que una quede atrás — exactamente el tipo de divergencia silenciosa que
  originó este reporte.
- La constitución (Principio V) pide no pelear contra el framework: un servicio inyectable por el
  contenedor es el patrón Laravel estándar y el que ya usa el resto de `App\Services\Arca\`.
- No es refactor especulativo: hay un cuarto consumidor concreto entrando ahora. Se extrae porque el
  caso llegó, no por si llega.

**Alternativas consideradas**:

- *Copiar el bloque a `ProveedorController`*: es lo más rápido (~40 líneas) y no toca código en
  producción. Rechazada: consolida la deuda en el momento exacto en que se paga sola, y deja al
  próximo cambio en ARCA con cuatro lugares que sincronizar.
- *Trait compartido entre los controladores*: resuelve la duplicación del formateo pero no la de los
  servicios (Mercado Libre y Tiendanube no son controladores), así que dejaría dos copias vivas. Un
  trait tampoco es inyectable ni mockeable de forma independiente. Rechazada.
- *Clase base común de controlador*: acopla Cliente y Proveedor por herencia para compartir un
  método, cuando la relación entre ambos es de similitud, no de especialización. Rechazada.

**Riesgo asumido y mitigación**: la extracción toca tres consumidores en producción, incluidas dos
integraciones (Mercado Libre y Tiendanube) que corren por cron. Mitigación: el comportamiento
extraído es idéntico byte a byte en los dos servicios, y sus tests existentes se conservan sin
modificar como red de seguridad — si el refactor cambiara algo, esos tests lo detectan. Ver R5.

---

## R2. ¿Dónde vive la traducción del resultado a la respuesta JSON del modal?

**Decisión**: en el mismo servicio, como un segundo método `paraModal(string $cuit): array` que
envuelve la consulta y devuelve la estructura `{consultado, encontrado, mensaje, ...datos}` que hoy
arma `ClienteController::consultarPadron()`. Ambos controladores lo consumen sin transformar nada.

**Rationale**: los mensajes al usuario ("No se pudo consultar el padrón de ARCA en este momento.",
"No se encontró el CUIT en el padrón de ARCA.") son parte del contrato con el front y la spec exige
que Proveedor use los mismos textos (Assumptions). Centralizarlos garantiza que no diverjan, que es
precisamente el defecto que esta feature corrige. Mercado Libre y Tiendanube siguen usando el método
que devuelve el objeto, porque no tienen modal ni mensajes.

**Alternativas consideradas**:

- *Un API Resource de Laravel*: es el lugar canónico para formatear respuestas, pero acá el objeto
  formateado no es un modelo Eloquent sino un DTO transitorio con tres formas posibles según el
  desenlace. Un Resource agregaría una capa sin beneficio. Rechazada.
- *Dejar el formateo duplicado en cada controlador*: reintroduce el problema en la capa de
  presentación. Rechazada.

---

## R3. ¿Dónde vive la regla de derivación del comprobante por defecto de Proveedor?

**Decisión**: en el front (`resources/js/proveedores.js`), en paralelo exacto a como Cliente la tiene
hoy en `resources/js/clientes.js`, con la tabla A/C/B propia de compra.

**Rationale**:

- Es una **sugerencia de UI sobre un campo editable**, no una validación. El backend no la aplica ni
  la verifica: `tipo_comprobante_defecto` se guarda como lo dejó el usuario. Ponerla en el servidor
  implicaría un endpoint nuevo o un cálculo que después el usuario puede contradecir, sin que nadie
  lo use.
- El paralelo con Cliente (spec 048) mantiene ambas pantallas con la misma arquitectura, que es lo
  que el usuario pidió.
- La derivación depende del **texto** de la condición de IVA seleccionada, que ya está en el DOM.

**Punto de atención documentado**: la regla de Cliente y la de Proveedor son distintas a propósito
(FR-017). El riesgo es que un futuro refactor "unifique" ambas por parecerse. Mitigación: comentario
explícito en el código de ambos lados y test que fija los tres casos de Proveedor (A/C/B).

**Alternativas consideradas**:

- *Derivar en el backend y devolverlo en la respuesta del padrón*: acopla la sugerencia de UI a la
  consulta a ARCA, cuando la regla debe correr también al elegir la condición a mano, sin consulta.
  Rechazada.
- *Tabla de configuración editable*: sobredimensionado para 5 condiciones fijas de un catálogo que no
  cambia. Rechazada (YAGNI, y la constitución pide simplificar lo no justificado).

---

## R4. ¿Cómo se porta el autocompletado del front sin duplicar `clientes.js` entero?

> **Corregido tras `/speckit-analyze`**: la versión original de esta decisión afirmaba que en el
> front había "dos consumidores". Son **cuatro**, y uno de ellos ya tiene el autocompletado
> implementado. Ver R7, que es el hallazgo que cambia el alcance de la feature.

**Estado real del front** (relevado completo):

| Archivo | Pantalla | ¿Autocompleta desde el padrón? | Regla de comprobante |
|---------|----------|-------------------------------|----------------------|
| `resources/js/clientes.js` | Listado de Clientes | Sí (spec 037/047/048) | A/B (correcta para Cliente) |
| `resources/js/cliente-modal.js` | Alta rápida en Venta y Presupuesto | Sí | A/B (correcta para Cliente) |
| `resources/js/proveedores.js` | Listado de Proveedores | **No** — hay que construirlo | — (hay que agregarla) |
| `resources/js/proveedor-modal.js` | Alta rápida en Compra | **Sí, ya está** (ver R7) | **A/B — incorrecta para Proveedor** |

**Decisión**: portar el bloque a `proveedores.js` como código propio del módulo, **sin** extraer un
módulo JS compartido en esta spec, y corregir la regla de comprobante en `proveedor-modal.js`.

**Rationale**: la conclusión original se sostiene, pero por un motivo distinto al que decía. No es
que haya pocos consumidores: hay cuatro y son notablemente parecidos, así que la tentación de
unificar es legítima. Se decide **no hacerlo ahora** porque:

- Las reglas de comprobante son **deliberadamente distintas** entre Cliente (A/B) y Proveedor
  (A/C/B), así que un módulo común tendría que parametrizarlas — que es justo la parte donde un
  error se vuelve fiscal.
- Esta feature ya refactoriza el backend tocando tres consumidores en producción. Sumar un refactor
  del front en el mismo cambio amplía el radio de fallo sin que nadie lo pida.
- `proveedores.js` queda como espejo estructural de `clientes.js`, que es la convención vigente del
  proyecto para pantallas hermanas.

Queda anotado como deuda explícita: un módulo `padron-autocomplete.js` que reciba la tabla de
derivación como parámetro eliminaría las cuatro copias. Es candidato a spec propia.

**Alternativas consideradas**:

- *Módulo `padron-autocomplete.js` compartido ahora*: más DRY, pero mezcla dos refactors de riesgo en
  un solo cambio. Diferida, no descartada.
- *Reusar `clientes.js` desde la vista de proveedores*: rompe el aislamiento por pantalla y arrastra
  selectores de Cliente (`#cliente-id`). Rechazada.

---

## R7. Hallazgo: el alta rápida de proveedor en Compra ya tiene el autocompletado (y la regla mal)

**Detectado por `/speckit-analyze`.** No estaba en el relevamiento inicial, que se detuvo en
`proveedores.js` sin buscar otros archivos que consumieran el mismo endpoint.

**Qué hay**: `resources/js/proveedor-modal.js` — el alta rápida de proveedor del formulario de Compra
(`resources/views/compras/form.blade.php:218`, que incluye el mismo `proveedores/_modal_form`) — ya
implementa el autocompletado **completo**: `CAMPOS_PADRON`, `tocadoPadron`, `resetearTocadoPadron()`,
`autocompletarDesdePadron()`, `mostrarMensajePadron()`, `verificacionEnCurso`, y los helpers
`normalizarTexto()` / `buscarOpcionPorTexto()`. Consulta `proveedores.verificar-documento`.

**Por qué nadie lo notó**: es **código muerto hoy**. Como el endpoint nunca devuelve la clave
`padron`, `autocompletarDesdePadron(undefined)` entra y retorna en la primera línea. El modal se
comporta como si la función no existiera. Presumiblemente se copió de `cliente-modal.js` al construir
el modal, anticipando un backend que no llegó.

**Las dos consecuencias**:

1. **Se activa solo.** En cuanto el endpoint devuelva `padron` (tarea T010), ese modal empieza a
   autocompletar sin que ninguna tarea lo haya tocado. Es un buen resultado —es lo que queremos—
   pero tiene que ser una decisión, no un efecto lateral.
2. **Con la regla equivocada.** Su `derivarComprobantePorCondicionIva()` usa la de Cliente:
   `texto === 'Responsable Inscripto' ? 'A' : 'B'`, comentada como "docs §2.1". Al activarse, un
   proveedor **Monotributista** quedaría con **Factura B** en vez de **C** — el error preciso que
   FR-015 evita, y en la pantalla de Compra, que es donde el dato se usa.

**Decisión**: incorporar `proveedor-modal.js` al alcance de la feature (FR-019/FR-020). El
autocompletado ya está y no hay que tocarlo; hay que **corregir la regla de comprobante a A/C/B** y
verificar el modal en el navegador, porque pasa de código muerto a código vivo.

**Verificación adicional necesaria**: confirmar que `cliente-modal.js` conserva su regla A/B (ahí sí
es la correcta) y que este cambio no la toca (FR-018).

---

## R5. Estrategia de testing

**Decisión**:

1. **Tests nuevos** en `tests/Feature/ProveedorVerificarPadronTest.php`, espejo de
   `ClienteVerificarPadronTest.php`: padrón OK, sin certificado, ARCA caída, CUIT no encontrado,
   padrón sin condición de IVA, y documento no-CUIT.
2. **Tests existentes intactos** como red de no-regresión del refactor de R1:
   `ClienteVerificarPadronTest.php` (respalda FR-018 y SC-005) y los de las integraciones. No se
   modifican: si el servicio extraído cambia el comportamiento, fallan.
3. **Regla A/C/B**: cubierta a nivel de los tres casos en el test de Feature del front no es posible
   (es JS sin cobertura de navegador en el proyecto); se documenta como verificación manual en
   `quickstart.md`. El proyecto ya tiene precedente: la regla A/B de Cliente (spec 048) tampoco tiene
   test automatizado de JS.

**Rationale**: la constitución (Principio IV) exige tests donde hay impacto fiscal. La consulta al
padrón alimenta datos que después se usan en comprobantes de compra, así que entra. La sugerencia de
comprobante por defecto es editable y no bloquea nada, así que el nivel de verificación manual es
proporcional al riesgo.

**Nota sobre el entorno**: la suite corre en SQLite mientras producción es MySQL (memoria del
proyecto: `mysql-only-full-group-by-tests-sqlite`). Este cambio no agrega consultas SQL nuevas ni
agregaciones, así que ese riesgo no aplica acá; igualmente la verificación en navegador queda en el
quickstart.

---

## R6. Reutilización del mapeo de provincia y condición de IVA

**Decisión**: reutilizar tal cual `ResultadoConsultaPadron`, que ya normaliza el nombre de provincia
del catálogo de ARCA al del sistema y deriva el id de condición de IVA. No se toca.

**Rationale**: ese mapeo fue el objeto de las specs 037 y 047 y está validado en producción para los
mismos datos. Proveedor consume el mismo catálogo de provincias y el mismo de condiciones de IVA
(cinco valores), así que no hay nada específico que resolver. FR-012 (no asignar valores sin
correspondencia) ya está satisfecho por ese componente y por la búsqueda de opción por texto del
front.

---

## Resumen de decisiones

| Id | Decisión | Impacto |
|----|----------|---------|
| R1 | Extraer `App\Services\Arca\ConsultaPadron` y migrar los 3 consumidores | Elimina 3 duplicados; toca código en producción (mitigado por tests) |
| R2 | El formateo para el modal vive en el mismo servicio | Garantiza mensajes idénticos entre Cliente y Proveedor |
| R3 | Regla A/C/B en el front de Proveedor, en paralelo a Cliente | Sin cambios de backend; regla explícitamente distinta de Cliente |
| R4 | Portar el autocompletado a `proveedores.js` sin módulo JS compartido | Menor radio de riesgo; deuda de 4 copias anotada para spec propia |
| R5 | Test de Feature nuevo + tests existentes como red de regresión | Cubre el impacto fiscal; JS por verificación manual |
| R6 | Reutilizar `ResultadoConsultaPadron` sin cambios | Cero riesgo sobre el mapeo ya validado |
| R7 | Incorporar `proveedor-modal.js` (alta rápida en Compra) al alcance | **Amplía el alcance**: su autocompletado se activa solo con T010, y su regla de comprobante está mal (A/B en vez de A/C/B) |
