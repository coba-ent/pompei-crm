# Contagram CRM — Guía para Claude

## Qué es este proyecto

CRM propio para un negocio particular argentino, inspirado funcionalmente en Contagram (contagram.com). Laravel 12 + Eloquent, MySQL, **single-tenant** (un solo negocio — no es un SaaS multi-empresa), con facturación electrónica ARCA (ex AFIP) vía WSAA + WSFEv1.

Metodología de desarrollo: **spec-driven development con [spec-kit](https://github.com/github/spec-kit)**. No se implementa nada de negocio sin pasar antes por el flujo de specs.

## Idioma

Claude siempre responde en **español** en este proyecto.

## Principio rector: fidelidad estructural a Contagram (regla de oro)

Este proyecto ya se tiró abajo y se reconstruyó una vez (24/07/2026) porque los módulos construidos en
la primera pasada no reflejaban con precisión el negocio ni la estructura real de Contagram. **No se
repite ese error.**

- **La estructura de cada pantalla —vistas, sub-vistas, modales, distribución de campos, menús de
  fila, paneles de filtros, KPIs, navegación entre pantallas— tiene que calcar la de Contagram real**,
  no una versión simplificada o "equivalente" inventada. Fidelidad de negocio (campos, reglas,
  validaciones) no alcanza si la distribución visual/estructural diverge sin una razón documentada.
- **Fuente de verdad para la estructura real**: los informes `docs/informe_contagram_*.md` (relevamiento
  con capturas reales de la app, navegación real, apertura de cada modal/dropdown/menú) son más
  confiables que `documentacion_principal_crm.md` para estructura de pantalla — éste último puede
  quedar desactualizado o impreciso; ante conflicto, corregirlo contra el informe con capturas.
- **Dependencias entre módulos no se resuelven "simplificando".** Si al relevar fielmente una pantalla
  aparece que depende de un módulo todavía no construido (ej. un filtro que referencia Proveedor,
  o un link que navega a Cta Cte), **no se construye una versión sin esa dependencia**: se arma un
  spec que contempla ambos (el módulo faltante + la pantalla que depende de él), o se documenta
  explícitamente la brecha como pendiente en `docs/documentacion_principal_crm.md §5` hasta que se
  arme ese spec conjunto.
- Antes de dar por cerrada una pantalla, contrastarla contra el informe correspondiente con capturas
  (columnas exactas, orden, acciones del menú de fila, paneles de filtro, KPIs) — no alcanza con que
  "funcione", tiene que coincidir estructuralmente.

## Documentación fuente de verdad

- [docs/documentacion_principal_crm.md](docs/documentacion_principal_crm.md) — spec funcional: los módulos ya construidos/en alcance, pantallas, campos, flujos y reglas de negocio relevadas de Contagram. Recortado al alcance vigente (ver su propia sección "Módulos pendientes de re-relevamiento").
- [docs/modelo_datos.md](docs/modelo_datos.md) — esquema de base de datos derivado de esa spec funcional.
- [docs/informe_contagram_*.md](docs/) — relevamientos con capturas reales de Contagram (navegación real, apertura de cada modal/dropdown/menú, scroll horizontal completo de tablas). **Fuente de verdad para la estructura exacta de pantalla** (ver principio rector arriba). Se van agregando uno por módulo a medida que se relevan.

Estos documentos son la referencia de dominio del proyecto. **Cualquier spec, plan o tarea debe ser consistente con lo que dicen.**

## Regla obligatoria: docs y specs se retroalimentan

1. **Antes de `/speckit-specify` o `/speckit-plan`**: leer `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` para el módulo en cuestión. La spec tiene que basarse en las reglas de negocio y el modelo de datos ya relevados ahí, no reinventarlos.
2. **Después de `/speckit-specify`, `/speckit-clarify` o `/speckit-plan`**: si el proceso reveló una regla de negocio nueva, un campo nuevo, una entidad nueva o corrigió algo que estos documentos tenían mal, **actualizar `docs/documentacion_principal_crm.md` y/o `docs/modelo_datos.md`** antes de pasar a `/speckit-tasks`. No dejar que la spec y la documentación de dominio diverjan.
3. Si una spec contradice estos documentos, señalarlo explícitamente y resolver la contradicción (actualizando el doc o ajustando la spec) antes de seguir — no avanzar en silencio con la inconsistencia.

## Stack técnico

- Backend: Laravel 12 (PHP 8.2), Eloquent ORM
- Base de datos: MySQL (XAMPP local, `root` sin password), DB `contagram`
- Frontend: template Bootstrap 5 **NexaDash** (Laravel Blade) como base visual — ver `resources/views/layouts/default.blade.php` + `resources/views/elements/` (header, sidebar, footer). Vite + Tailwind para el build de assets.
- El sidebar (`resources/views/elements/sidebar.blade.php`) ya está wireado con los 8 módulos reales del CRM (Ingresos, Egresos, Base de Datos, Facturación, Informes, Tesorería, Configuración & Ajustes) con rutas placeholder — se van completando módulo por módulo.
- Toda vista nueva extiende `layouts.default`:
  ```blade
  @extends('layouts.default')
  @section('content')
      {{-- contenido --}}
  @endsection
  ```

## Credenciales de acceso — mantener actualizado `CREDENCIALES_ACCESO.txt`

`CREDENCIALES_ACCESO.txt` (raíz del proyecto, gitignored) es la fuente de verdad de accesos a la app
(usuarios, contraseñas) para desarrollo/pruebas locales. **Cualquier acción que cree, borre o cambie
un acceso —incluido resetear una contraseña para hacer una prueba manual en el navegador— tiene que
quedar anotada ahí en el mismo cambio.** No dejar el archivo desactualizado: si alguien más (o una
sesión futura) lo usa para loguearse, tiene que reflejar la credencial real vigente, no una vieja.

## Especificaciones de diseño OBLIGATORIAS (todo el proyecto)

Estas reglas de UX/UI son innegociables y aplican a **todas** las pantallas que se construyan.
No son sugerencias: toda spec, plan, task e implementación debe cumplirlas.

1. **Tablas**: siempre con **DataTables**, responsive, y con datos cargados por **AJAX**
   (server-side processing). Nada de tablas estáticas renderizadas en Blade para listados.
2. **Alta / edición / eliminación**: siempre mediante **modales de Bootstrap + AJAX**. La página
   **NUNCA** se refresca ni se recarga para realizar una operación (comportamiento tipo SPA sobre
   Blade). Los formularios se envían por AJAX y actualizan la tabla/UI en el lugar.
3. **Notificaciones**: toda notificación (éxito, error, advertencia, info) se muestra con las
   **alertas toast del template** (Toastr de NexaDash — ver `uc-toastr` como referencia). No se usan
   alerts nativos del navegador ni mensajes flash con recarga de página.
4. **PDFs / documentos imprimibles**: siempre se visualizan en el modal compartido
   `resources/views/elements/modal-pdf.blade.php` (incluido globalmente en `layouts/default.blade.php`),
   vía `window.AppPdf.abrir(url, titulo)`. **Nunca** un link `target="_blank"` ni `window.open` directo
   como primera opción — el modal es la vía principal, y `window.open` sólo entra como *fallback*
   si `window.AppPdf` no está disponible (ver `resources/js/presupuestos.js` como referencia).
5. **Selects con buscador**: todo `<select>` de **datos dinámicos** (productos, proveedores,
   depósitos, clientes, categorías, cuentas, etc.) usa **Select2** (la librería del template NexaDash:
   `vendor/select2/*`, registrada por pagelevel en `config/dz.php`). **Nunca** un `<select>` nativo
   pelado ni `size="N"` para listas largas. Reglas: `width:'100%'`; dentro de un modal pasar
   `dropdownParent` = el modal; para catálogos grandes usar `ajax` (endpoint que devuelve
   `{data:[{id,nombre,codigo}]}`, ej. `productos.opciones`); tras setear value/opciones por código
   refrescar con `.trigger('change.select2')`. El alto/tipografía ya están alineados a los controles
   compactos en `public/css/contagram-custom.css` (regla global). Referencia: `resources/js/productos.js`.
   **Carga en lote**: en un buscador que agrega ítems a un detalle (productos de Venta/Presupuesto/
   Compra), tras agregar el ítem hay que **reabrir el desplegable** (`setTimeout(() => $el.select2('open'), 0)`)
   para que el foco vuelva al buscador y se pueda cargar el siguiente sin volver a hacer clic — el
   campo de búsqueda de Select2 sólo existe con el desplegable abierto, y el `setTimeout` es
   imprescindible porque en el handler de `select2:select` el cierre todavía está en curso. Ver
   `reabrirBuscador()` en `resources/js/ventas.js`.

Consecuencias técnicas típicas: controladores que responden JSON para las operaciones AJAX,
endpoints server-side para DataTables, validación que devuelve errores en JSON para mostrarlos en el
modal/toast sin recargar, endpoints de PDF que devuelven `Content-Disposition: inline` (renderizables
en el `<iframe>` del modal compartido).

## Flujo de trabajo (spec-kit)

1. `/speckit-constitution` — principios del proyecto (una sola vez / cuando cambien)
2. `/speckit-specify` — especificar el módulo o feature a construir (consultar docs primero)
3. `/speckit-clarify` — resolver ambigüedades antes de planear
4. `/speckit-plan` — plan técnico de implementación
5. `/speckit-checklist` — checklist de calidad de la spec
6. `/speckit-tasks` — desglose en tareas accionables
7. `/speckit-analyze` — chequeo de consistencia entre spec/plan/tasks
8. `/speckit-implement` — implementación

Specs, plans y tasks quedan en `specs/` (generado por spec-kit al correr `/speckit-specify`).

### REGLA OBLIGATORIA: encadenar hasta `analyze` sin preguntar

Cuando el usuario pide "hacé el spec de X" (o `/speckit-specify`), **NO se ejecuta sólo `specify`**.
Se ejecuta la cadena completa **de corrido y sin pedir confirmación entre pasos**:

```
specify → clarify → plan → checklist → tasks → analyze → aplicar los fixes de analyze
```

Reglas de esa cadena:

- **Las preguntas se hacen TODAS AL PRINCIPIO**, antes de arrancar `specify`. Una vez que empieza la
  cadena no se vuelve a interrumpir al usuario para consultarle nada, salvo que aparezca algo que
  **realmente bloquee** y no tenga default razonable.
- **`clarify`, `checklist` y `analyze` NO son opcionales** en este proyecto, aunque el flujo genérico
  de spec-kit los marque como tales.
- **Al terminar `analyze` no se pregunta "¿aplico los fixes?"**: se aplican directamente. Recién
  después se le reporta al usuario.
- **Antes de `tasks`** se actualizan `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md`
  si la spec/plan introdujo reglas, campos o entidades nuevas (principio I de la constitución).
- **`implement` NO se ejecuta.** La cadena termina informando: **"Está listo para implementar"**, con
  el resumen de lo generado y de los fixes aplicados. El usuario decide cuándo implementar.
