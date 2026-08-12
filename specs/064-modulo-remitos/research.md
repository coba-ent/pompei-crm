# Research — spec 064

Qué se verificó en el código y en producción antes de planear, y qué decidió cada hallazgo.

## R1 — El módulo está a medio construir, no roto: nunca se terminó

`Remito` es un "encabezado mínimo" declarado como tal por el propio modelo:

> `/** Encabezado mínimo (FR-018); detalle de ítems pendiente de relevamiento propio. */`

Y `docs/documentacion_principal_crm.md` §5 lo registra como brecha abierta:

> *"Remitos: estructura de pantalla real de Contagram sigue sin relevar con capturas (§3.6 sólo pudo
> confirmar la forma genérica de un remito, no la pantalla real de la app)."*

**Decisión**: no es deuda técnica a reparar, es un módulo a completar. El relevamiento que faltaba ya
existe (`docs/Contagram-Informe-Remitos.md` + 12 capturas, agosto 2026), así que la condición que el
propio documento ponía para avanzar está cumplida.

## R2 — `remitos.venta_id` es NOT NULL: los remitos de Compra están rotos

Verificado en la base de producción del VPS:

```
venta_id    bigint(20) unsigned  NO   (NOT NULL)
compra_id   bigint(20) unsigned  YES  (nullable)
```

Pero `CompraController::remitoStore()` hace `$compra->remitos()->create([...])` sin `venta_id`.

**Es el mismo bug ya documentado** para `notas_credito_debito.venta_id` en
`docs/importacion_casos_a_revisar.md` §0 ("emitir una NC/ND de una compra fallaba; nunca se había
probado"). Mismo patrón: la columna se declara nullable en el modelo y en `modelo_datos.md`, pero la
migración la creó NOT NULL, y el camino de Compras nunca se ejercitó.

**Decisión**: se corrige en esta spec (migración de nulabilidad). Sin esto, US4 no puede funcionar.
Conviene además revisar si hay más tablas con el mismo patrón `venta_id`/`compra_id`.

## R3 — Tres bugs de UI en lo poco que existe

Verificados leyendo el código:

1. **Botón mal cerrado** (`resources/views/ventas/detalle.blade.php:37`): falta el `>` que cierra el
   tag `<button`, así que los atributos del `<i>` se absorben como atributos del botón y **el ícono
   del camión no se renderiza**. Introducido en el commit `00db775` ("Agrega el botón Enviar a ARCA"),
   no por la spec 063.
2. **Ancla inexistente** (`ventas/_row_actions.blade.php:26`): el menú de fila apunta a
   `route('ventas.show', $venta).'#remitos'`, pero **no existe ningún `id="remitos"`** en el detalle.
   Además viola la regla de navegación del proyecto sobre no usar URLs con `#`.
3. **Remitos invisibles**: `VentaController::show()` hace `$venta->load([... 'remitos' ...])`, pero la
   vista **nunca los renderiza**. Se cargan a memoria y se descartan.

**Decisión**: se incorporan como FR-024 a FR-026 en vez de tratarse como arreglos sueltos, para que
queden cubiertos por criterios de aceptación.

## R4 — Precedente de página completa (spec 059)

La regla de UI del proyecto exige modales para alta/edición. Pero la **spec 059
(`pagina-completa-ncnd`)** ya resolvió el mismo conflicto en el caso de NC/ND: un formulario con tabla
de ítems **no** entra en un modal, y Contagram real lo tiene en página completa. Esa spec corrigió
justamente un modelo mal relevado (specs 039/045/057) por falta de capturas.

La captura 02 muestra "Nuevo Remito Venta ID 5" como página completa, con la misma estructura.

**Decisión**: se sigue el precedente. La regla de modales se interpreta como aplicable al ABM simple,
no a formularios con tabla de ítems. No hizo falta preguntárselo al usuario.

## R5 — El remito no mueve stock: confirmado por tres fuentes

1. Documentación oficial de Contagram: *"El stock es afectado al momento de vender o comprar, no al
   momento de emitir el remito."*
2. La constitución del proyecto, en Flujo de Desarrollo y Calidad: *"stock se afecta al vender/comprar
   no al remitir"*.
3. `docs/documentacion_principal_crm.md` §11 (reglas de negocio críticas), mismo criterio.

**Decisión**: la regla ya era doctrina del proyecto antes de esta spec. El aporte de la spec es
**blindarla con tests** (`RemitoNoMueveNadaTest`), porque hasta ahora no había código de remitos que
pudiera violarla — y ahora sí lo va a haber.

## R6 — Estado en producción al momento de planear

Verificado en el VPS el 12/08/2026:

| Dato | Valor |
|---|---|
| Remitos existentes | 3 (N° 1 sobre venta 2, N° 2 sobre venta 17942, N° 3 sobre venta 24038) |
| Con ítems | 0 — la tabla no existe |
| Con transportista | 0 — la tabla no existe |
| Remitos de Compra | 0 (imposible crearlos, ver R2) |

El N° 3 se creó por accidente el 12/08/2026 durante una prueba manual del usuario. **Decisión del
usuario**: se elimina el N° 3, se conservan el N° 1 y el N° 2.

FR-026 exige que esos 2 remitos históricos —sin ítems ni transportista— sigan visualizándose sin
romper la sección ni el documento imprimible.

## R7 — Qué NO se relevó

El informe cubre **Remitos de Ventas** en profundidad. **No cubre Compras**: no hay capturas de un
remito de Compra en Contagram.

**Decisión**: la estructura de Compras se asume simétrica (mismo formulario, mismo documento, datos
del proveedor en vez del cliente), con una excepción resuelta con el usuario: el **domicilio de
entrega** apunta al depósito que recibe, no al proveedor, porque en una Compra la mercadería viene
hacia el negocio. Queda registrado como supuesto en la spec; si algún día se releva Compras con
capturas y difiere, se corrige.
