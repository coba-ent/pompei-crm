# Guías de front — Contagram

Convenciones de UI del proyecto sobre el template **NexaDash** (Bootstrap 5). El objetivo es no
volver a tomar (ni re-discutir) las mismas decisiones en cada pantalla. Si una regla choca con un
caso puntual, gana el sentido común, pero hay que anotar la excepción y por qué.

Las reglas globales viven en `public/css/contagram-custom.css` (se carga siempre, vía
`config/dz.php → global.css.bottom`, después de `style.css` del template). El layout base es
`resources/views/layouts/default.blade.php`. Ver también las "Especificaciones de diseño
OBLIGATORIAS" de `CLAUDE.md` (DataTables + AJAX, modales, toasts, PDFs en modal compartido).

> Nota: el template `public/css/style.css` es **vendored** — no editarlo. Todo override propio va en
> `contagram-custom.css`.

## Tamaño de controles y botones: "sm"/compacto por defecto

Inputs, selects, textareas, labels **y botones** se ven **compactos** por defecto — no hace falta
agregar `.form-control-sm` / `.form-select-sm` / `.btn-sm` a mano en cada vista. Ya está resuelto
globalmente en `contagram-custom.css`:

- **`.form-control` / `.form-select`**: mismo `padding` + `font-size` (0.8125rem) + `line-height`,
  sin `height` fija — quedan finos y **a la misma altura** entre sí (text/date/select). Nunca
  reintroducir una `height` fija alta (el bug histórico fue `.form-select { height: 2.813rem }`,
  ~45px, que se veía "enorme").
- **`.form-label`**: `margin-bottom` ajustado y `font-size` 0.8125rem.
- **`.btn`**: compacto con `padding`/`font-size` **literales** en `contagram-custom.css`. **Todos
  los botones deben verse "sm"** — no se agrega `.btn-sm` explícito en vistas nuevas.
  - Detalle importante: NexaDash redefine `.btn` más abajo en `style.css` con valores **literales**
    (`padding: 10px 20px; font-size: 14px`), no con variables — por eso un override por
    `--bs-btn-*` NO alcanza (se ve grande igual). Hay que setear `padding`/`font-size` literales.
    **Sin `!important`**: `contagram-custom.css` carga después, así que con igual especificidad
    (`.btn`, 0,1,0) gana por orden.

**Excepción — control/botón grande a propósito** (un CTA principal, un input destacado): usar
`.form-control-lg` / `.btn-lg` **explícito** para ese caso puntual. Como los `.btn-lg`/`.btn-sm` del
template son `.btn.btn-lg`/`.btn.btn-sm` (especificidad 0,2,0), conservan su propio tamaño pese al
override base — por eso **no** se usa `!important` en el `.btn` global (aplastaría también los lg/sm).

**Plugins de formulario nuevos** (date picker, tag input, select potenciado, etc.) que no hereden de
`.form-control`/`.form-select`: revisar su tamaño por defecto y, si hace falta, sumar su regla en
`contagram-custom.css` siguiendo el mismo patrón.

## Select2 (selects de datos dinámicos)

Todo select de datos dinámicos usa **Select2** (regla obligatoria, ver `CLAUDE.md` #5). El alto
compacto ya está resuelto **globalmente** en `contagram-custom.css` — no hace falta (ni hay que)
tocarlo por vista.

**Gotcha ya pisado una vez (24/07/2026, spec 003-proveedores-informe-stock)**: `public/css/style.css`
(NexaDash) define el `min-height: 2.813rem` (~45px) en el **span interno**
`.select2-selection__rendered`, **no** en el contenedor `.select2-selection--single`. Un override que
sólo resetea el contenedor externo (`height`/`min-height`/`padding`) no alcanza: el hijo con
`min-height` grande sigue empujando al padre (`height: auto`) a ~52px de alto igual.

**Sintoma para reconocerlo**: el select cerrado se ve bien, pero al abrirlo el dropdown de opciones
queda "flotando" con un hueco por encima (no pegado al borde inferior del select) y puede tapar el
campo de la fila de abajo — porque el select mide ~52px en vez de los ~28px del resto de los
controles compactos.

**Fix** (ya aplicado en `contagram-custom.css`): resetear `min-height: 0` en **ambos** niveles:

```css
.select2-container--default .select2-selection--single {
    min-height: 0;
    height: auto;
    /* ...padding/font-size/line-height/border igual que .form-select... */
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    min-height: 0;   /* el que faltaba — sin esto, el bug vuelve */
    padding: 0;
    line-height: 1.4;
}
```

Si en el futuro se ajusta el alto/tipografía de Select2 y el problema reaparece, es casi seguro que
falta este `min-height: 0` en `.select2-selection__rendered`.

## Modales: centrados y con scroll si el form es largo

Todo modal se abre **centrado**: el `<div class="modal-dialog ...">` lleva **siempre**
`modal-dialog-centered`. Las clases de tamaño (`modal-lg`, `modal-xl`) o `modal-dialog-scrollable`
se suman después. Para formularios largos, `modal-dialog-centered modal-dialog-scrollable` es el
combo confiable: header y footer quedan fijos y el `modal-body` scrollea. Referencia:
`resources/views/clientes/_modal_form.blade.php`.

## Overrides sobre el template: cuidado con la especificidad

`style.css` a veces redefine reglas más abajo en el mismo archivo con valores literales o selectores
de atributo (`[data-headerbg="..."] .header`). Un override "normal" en `contagram-custom.css`, aunque
cargue después, puede perder por especificidad. Según el caso:

- Si el template usa **variables CSS** (p. ej. `.btn`, `.modal`), redefinir las variables — suele
  bastar sin `!important`.
- Si usa **valores literales** con más especificidad, doblar la clase (`.header.header { ... }`) o
  usar `!important` puntual. Anotar por qué.

## DataTables

Ver `CLAUDE.md` (server-side + AJAX obligatorio) y `contagram-custom.css` para los fixes globales ya
resueltos: paginación "Anterior/Siguiente" horizontal, y detalle responsive (fila expandida)
alineado a la izquierda. El botón **ColVis** ("Columnas") con visibilidad recordada en el navegador
(`stateSave`) es el patrón para listados con muchas columnas (referencia: `clientes`).
