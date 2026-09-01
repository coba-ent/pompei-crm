# Research — spec 094

Decisiones tomadas contra el dato real de producción (clon `contagram_vps_clon`) y contra los cuatro
archivos `Informe Stock AAAA.xlsx`. Todo lo que sigue está verificado, no supuesto.

---

## Decisión 1 — La fuente es el Excel de Contagram, no nuestras tablas

**Alternativa descartada**: reconstruir desde `venta_items` (36.462 filas) y `compra_items` (11.838).

**Por qué se descartó**: sólo cubre ventas y compras. Perdería los `Aumento`/`Disminución` manuales,
los ajustes por importación y los de sincronización — más de 3.000 movimientos reales que no existen
en ninguna otra tabla. Además obligaría a inventar el depósito de cada movimiento (nuestras ventas
legacy no lo tienen) y a calcular un saldo de apertura por producto.

**Lo que decidió**: el Excel trae el depósito como dato y trae `Saldo Stock`, que permite verificar
la carga contra lo que Contagram registró en vez de contra mi propia aritmética.

---

## Decisión 2 — Descartar las filas de cantidad 0

**El hallazgo**: el export repite cada movimiento **una vez por depósito**. El mismo movimiento
aparece tres veces (Local, Full, Depósito Tiendanube); sólo una lleva la cantidad real, las otras
dos van en 0 y sólo muestran el saldo de ese depósito.

Ejemplo real, producto 27203, 30/07/2026:

```
Registro Inicial | cant=2 | saldo=576 | Depósito Tiendanube
Registro Inicial | cant=0 | saldo=574 | Local
Registro Inicial | cant=0 | saldo=574 | Full
```

**Volumen**: 22.326 de las 53.844 filas están en 0. Quedan **31.518 movimientos reales**.

**Por qué importa**: cargarlas crearía 22.326 movimientos que no movieron nada. No romperían el
stock (suman 0), pero harían ilegible el historial de cada producto — que es exactamente lo que esta
spec viene a arreglar.

---

## Decisión 3 — `Registro Inicial` no es un apertura de inventario

Al principio se asumió que resolvía el problema del saldo inicial. **Es falso**: 15.961 de sus
15.964 filas tienen cantidad 0. Son **altas de producto** en el catálogo, no carga de stock.

**Consecuencia**: no hay apertura en la fuente. El histórico de 2024–2026 va a arrancar sin un saldo
inicial que lo respalde, porque ese saldo se formó en 2021–2023, que quedaron fuera de alcance.

**Cómo se maneja**: no se inventa un movimiento de apertura. La verificación de la Decisión 8 mide
esa brecha por producto y la reporta; es un hueco conocido del dato, no un error de la carga. Si el
usuario quisiera cerrarlo, la vía es conseguir los Excel de 2022 y 2023, no fabricar un ajuste.

---

## Decisión 4 — El matcheo va por `legacy_id`, no por `id`

`ventas.legacy_id` existe y tiene formato `AAAA-FC-{ID}`; `compras.legacy_id` usa
`COMPRA-AAAA-FC-{ID}`. Verificado con tres casos reales del Excel:

| Excel | legacy_id | id en el CRM |
|---|---|---|
| Venta 15963 (30/12/2024) | `2024-FC-15963` | 15963 |
| Venta 15962 | `2024-FC-15962` | 15962 |
| Compra 1883 (23/07/2025) | `COMPRA-2025-FC-1883` | 1883 |

El `id` **coincide numéricamente** con el de Contagram, pero el matcheo va igual por `legacy_id`
porque es el dato explícito y no depende de que la numeración se haya conservado en todos los casos.

**Colisiones entre años**: cero, en 23.736 ventas. El año del archivo se usa como validación cruzada,
no para desambiguar.

**Cobertura**: 23.736 de 24.004 ventas tienen `legacy_id` (las 268 sin él son posteriores al corte);
2.391 de 2.404 compras.

---

## Decisión 5 — El corte va por "ya tiene movimiento", no por fecha

**Alternativa descartada**: cortar seco en el 13/08/2026.

**Por qué se descartó**: hay 83 movimientos de compra en el CRM con `fecha` desde el **06/08/2026**,
anteriores al corte. Son compras cargadas después con fecha retroactiva. Un corte por fecha las
volvería a cargar y **duplicaría stock**.

**La regla**: para cada fila, si la operación a la que apunta ya tiene movimientos en
`movimientos_stock`, se saltea. No depende de fechas y cubre cualquier caso de borde.

Para las filas **sin `ID`** (ajustes sin operación) no hay contra qué comparar, así que ahí sí se
corta por fecha: nada con fecha ≥ 13/08/2026.

---

## Decisión 6 — Insertar sin pasar por Eloquent

**El riesgo**: `MovimientoStockObserver::created()` marca `stock_pendiente = true` en las
publicaciones de Mercado Libre y en las variantes de Tiendanube. Con 31.518 inserciones marcaría
prácticamente el catálogo entero, y el sincronizador **empujaría stock histórico a las dos
plataformas**. Es el riesgo más grave de esta spec: convertiría una carga de sólo-historial en una
modificación de lo que se publica y se vende.

**La decisión**: insertar con **query builder directo** (`DB::table('movimientos_stock')->insert()`),
no con el modelo. No es que se silencien los eventos: el camino de código ni siquiera los tiene.

`withoutEvents()` se descartó porque es más fácil de romper — alguien que después cambie el insert a
Eloquent dentro del closure no rompe nada visible, y el efecto reaparece.

**Verificación**: foto de las publicaciones pendientes antes y después. Si alguna cambió, se revierte.

---

## Decisión 7 — Nunca se escribe en `stocks`

El stock actual vive en la tabla `stocks`, separada de `movimientos_stock`. El comando **no tiene
ninguna consulta que la escriba**, y eso se verifica con la foto de FR-014.

Es la propiedad que hace que esta spec sea segura: no es que se tenga cuidado de no alterar el stock,
es que las dos cosas viven en tablas distintas y sólo se toca una.

---

## Decisión 8 — Verificar contra `Saldo Stock`, no contra mi aritmética

La columna `Saldo Stock` del Excel es el acumulado que Contagram tenía después de cada movimiento.
Permite una verificación **independiente**: reconstruir el acumulado por producto y compararlo.

**Qué se espera**: diferencias sistemáticas por el hueco de 2021–2023. Eso no invalida la carga; se
reporta como brecha conocida por producto. Lo que **sí** sería un error es que el orden relativo de
los movimientos de 2024 en adelante no reproduzca los saltos del saldo de Contagram.

---

## Decisión 9 — Comando de consola, dry-run por defecto

Es una carga histórica que se corre una vez. Un botón en la interfaz que alguien pueda apretar dos
veces es un riesgo sin contrapartida.

- `--dry-run` es el **default**; escribir exige bandera explícita.
- La corrida queda identificada para poder deshacerla entera.
- Idempotencia por FR-006: una segunda corrida encuentra que las operaciones ya tienen movimientos y
  saltea todo.

---

## Decisión 10 — El depósito Tiendanube se imputa a Local

El Excel tiene tres depósitos; el CRM tiene dos (Local id 5, Full id 6). No existe un depósito
Tiendanube.

Casi todas las filas de ese depósito caen por la Decisión 2 (cantidad 0). Las que sobreviven son de
`Aumento/Disminución por Sincronización`. Se imputan a **Local**, que es el depósito desde el que el
CRM atiende Tiendanube hoy, y se reportan aparte para poder auditarlas.

**No se crea un depósito nuevo**: crear un depósito para alojar 200 movimientos históricos agregaría
una entidad viva al CRM (aparecería en todos los selectores de depósito) a cambio de nada.

---

## Decisión 11 — Las fechas se parsean tolerando los dos formatos

Los exports de Contagram traen las fechas mezcladas: texto `M/D/YYYY` y serial numérico de Excel, en
el mismo archivo y la misma columna. Es un gotcha ya conocido del proyecto.

Leerlas mal produjo, en el primer análisis de este mismo archivo, fechas de septiembre a diciembre de
2026 — fechas futuras que no existen, resultado de invertir día y mes. El parseo maneja ambos
formatos y **cualquier fecha fuera del rango del archivo aborta la corrida** en vez de cargarse.
