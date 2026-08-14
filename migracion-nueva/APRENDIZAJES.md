# Migración a base nueva — punto de partida para cualquier sesión

**Leer esto primero.** Esta carpeta es el entorno autocontenido de la migración: se puede entrar acá
sin conocer el resto de la conversación y entender dónde estamos parados.

## Qué es esto y por qué existe

El VPS de producción se pobló en agosto 2026 con un import histórico (Contagram → CRM) hecho con
datos incompletos en su momento, y eso generó descuadres de caja por fechas de pagos/compras y
movimientos faltantes. Después de varios intentos de parchear en caliente sin resolverlo del todo,
se decidió reconstruir la base **desde cero, en un entorno 100% local**, aprovechando meses de
análisis ya hecho sobre los Excel de origen y todo lo aprendido sobre los bugs y reglas de negocio
reales del sistema viejo (Contagram).

**Regla no negociable, vigente desde el 13/08/2026: el VPS de producción NO SE TOCA** durante esta
migración, salvo instrucción explícita y muy detallada del usuario para un cambio puntual. Todo el
trabajo es sobre la base local nueva. Ver memoria `vps-congelado-migracion-base-nueva`.

## Plan de ejecución acordado (13/08/2026)

Importación **por etapas, año por año**, no todo de una:

1. **2021** primero: importar sólo ese año a `contagram_migracion`, comparar contra Contagram
   (cifras de control: facturado/cobrado/a cobrar de ese año, cantidad de comprobantes, etc.) antes
   de seguir.
2. **2022**, mismo procedimiento: importar, comparar contra Contagram, validar.
3. Así sucesivamente **2023 → 2024 → 2025**, un año a la vez, sin acumular años sin validar.
4. **2026 se deja para el final, a propósito**: se importa el **sábado a la noche** (la noche
   anterior al corte), para que si aparece algún problema el **domingo** haya tiempo de resolverlo
   con margen, y el **lunes** el negocio arranque a usar la base ya fresca y validada.

Esto reduce el riesgo de repetir el problema original (importar todo junto con datos incompletos y
recién notar el descuadre después) — cada año se valida antes de sumar el siguiente, así si algo no
cuadra se detecta temprano y acotado a un solo año, no mezclado con cinco más.

## Decisión estructural: `ventas.id` = Id de Contagram (13/08/2026)

A diferencia del VPS —donde el CRM ya tenía ventas propias y hubo que dejar auto_increment— acá la
base arrancó vacía, así que **la clave primaria de cada venta es el `Id` real de Contagram**.
Se importa con `php artisan migracion:ventas --anio=YYYY --preservar-id`.

**Verificado antes de decidirlo** (sobre los 6 años de export, no por suposición):

| Familia | Ids distintos | Repetidos entre años | Id máximo |
|---|---|---|---|
| Ventas (FC) | 23.563 | **0** | 24.301 |
| Notas de crédito (NC) | 638 | 0 | 726 |
| Notas de débito (ND) | 54 | 0 | 68 |

Cada familia tiene su propia serie global y correlativa, así que el `Id` de una factura **nunca**
colisiona con el de otra factura. Falsa alarma que conviene no repetir: parecía haber colisión
porque el Id 17 aparece en 2021 y en 2022, pero el de 2022 es una **NCA**, no una venta — familias
distintas con numeración independiente.

**Las NC/ND sí quedan con auto_increment**, porque NC y ND comparten la tabla
`notas_credito_debito` y hay **46 Ids que existen como NC y como ND a la vez** (1, 2, 4, 5, 7…). Su
Id de Contagram queda en `legacy_id` (`2021-NC-16`).

**Consecuencias a tener presentes:**
- `AUTO_INCREMENT` de `ventas` hay que dejarlo por encima de 24.301 cuando termine el histórico, para
  que las ventas nuevas del CRM no pisen un Id de Contagram.
- Los `ml_ordenes.venta_id` que se copiaron de la base vieja apuntaban a ids de otro espacio (ventas
  nacidas en el CRM, rango 4-24.063, que se solapa con el histórico). **Se pusieron en NULL** el
  13/08/2026 para que no quedaran apuntando a ventas ajenas; el mapeo previo quedó respaldado en
  `migracion-nueva/scripts-import/ml_ordenes_venta_id_previo_20260813.tsv` y hay que **rehacer el
  vínculo al importar 2026**. `estado_conversion` se dejó como estaba (122 en `convertida`) a
  propósito: evita que el sync las convierta de nuevo y duplique ventas.

## Decisión: el saldo de Cuenta Corriente a una fecha pasada replica a Contagram (13/08/2026)

Decisión explícita del usuario: el CRM tiene que mostrar los mismos números que el sistema que el
negocio venía usando, aunque no sean el aging contable estándar. Implementado en
`App\Services\Tesoreria\CuentaCorriente::ESTILO_CONTAGRAM`.

**Contagram no usa el mismo criterio de los dos lados** — medido, no supuesto:

| | Criterio de Contagram | Cómo quedó el CRM |
|---|---|---|
| **Clientes** | Filtra los comprobantes por fecha, **pero aplica todos los cobros** sin importar cuándo ocurrieron | Igual: `ESTILO_CONTAGRAM['cliente'] = true` |
| **Proveedores** | Filtra los comprobantes **y también los pagos** por fecha | Igual: `ESTILO_CONTAGRAM['proveedor'] = false` |

**Cómo se probó cada uno** (para no rediscutirlo):

- *Clientes*: la ficha de la venta 1637 en Contagram (06/11/2021, $296.169,36) muestra tres cobros —
  $93.895,34 el 13/11/2021, $195.151,00 el 10/02/2022 y $7.123,02 el 08/04/2022— y aun así el panel
  al 31/12/2021 la da por saldada, cuando ese día debía $202.274,02. Y no puede ser un filtro por
  fecha: para llegar a los −$6,74 que muestra, las ventas de 2021 tendrían que haber cobrado
  $24.048.073,51 dentro de 2021, cuando los extractos registran **$22.871.451,47 de cobros en todo
  el año**. Aritméticamente imposible.
- *Proveedores*: con el filtro por fecha puesto, el saldo al 31/12/2021 da $1.199.345,88 contra
  $1.194.695,87 de Contagram — **$4.650,01**, el mismo desfasaje constante que la bitácora §15 ya
  había medido en el VPS ($4.649,96) y que está **entre el informe de Contagram y su propio panel**,
  no en el CRM.

Esto **revierte parcialmente** el arreglo del aging documentado en `docs/importacion_casos_a_revisar.md`
§12 (que había hecho respetar la fecha de corte en los dos lados). Poniendo las dos constantes en
`false` se vuelve a ese comportamiento.

**Consecuencia para verificar la migración**: el saldo de Cuenta Corriente a una fecha pasada sirve
como control sólo del lado de Proveedores. Del lado de Clientes hay que comparar contra el estado
final (columna `A Cobrar` del export). El control que sí vale a cualquier fecha, y en los dos lados,
es la **caja contra la columna `Saldo`** de los extractos.

## ⚠️ LA CAUSA REAL DEL DESCUADRE ORIGINAL (leído completo el 13/08/2026 de `docs/importacion_casos_a_revisar.md`, 1276 líneas)

**No estuvo en el import de ventas/compras** — ese cerraba al 99,94%+ desde el principio. Estuvo en
cómo se trataron **cobros, pagos, notas de crédito/débito y tesorería** después de importar, y se
fue parchando *a posteriori* sobre el VPS en vez de resolverse desde el primer import. Para la base
nueva, la idea es incorporar estos puntos **desde el día uno** de cada año, no repetir el ciclo de
"importar naive → detectar descuadre → reconstruir con un comando aparte":

1. **No consolidar cobros/pagos en uno solo por comprobante.** El import original tomaba el
   `Cobrado`/`Pagado` total del comprobante, lo fechaba con la fecha de emisión y lo asignaba al
   primer medio de pago listado. Eso rompía la Cta Cte a cualquier fecha pasada (el total de *hoy*
   cerraba igual, por eso no se notaba al principio). **La fuente correcta no es `Ventas c- cobro` /
   `Compras c- pago`** (una fila por comprobante, sin desglose) **sino el informe "Movimientos de
   Clientes/Proveedores" filtrado por Operación = Cobro/Pago** (carpeta `cobros/` y equivalente de
   proveedores) — trae fecha real y cuenta por cada cobro/pago individual, no un acumulado.
2. **NC/ND se vinculan a su venta/compra desde el import**, no quedan con `venta_id`/`compra_id`
   null. Método (bitácora §11 y §8d): el export trae `Total NC`/`Total ND` por comprobante — buscar
   qué nota (o subconjunto de notas) del mismo cliente/proveedor suma exactamente ese total. Llegó a
   598/692 en ventas y 139/149 en compras la primera vez; el resto se carga a mano.
3. **Comprobantes con más de un renglón**: sumar todos los ítems, nunca tomar el `Total` de la
   primera fila sola — bug que se repitió 3 veces en NC de venta y compra porque el importador
   original tomaba una sola fila.
4. **Catálogo de cuentas de tesorería**: exactamente 21 cuentas (las de Contagram), con nombres
   canónicos tomados de la **ficha de cada cuenta**, nunca del panel de Saldos — fusionar duplicados
   que aparecen con nombre distinto al reimportar (ver tabla completa en la bitácora §10) y tipificar
   bien `a_cobrar`/`banco`/`efectivo` (afecta sólo dónde se muestra el saldo, no el importe).
5. **El panel de Saldos de Contagram NO sirve para conciliar** — no reproduce sus propios exports
   (pasó con Proveedores, con Mercado Pago y con Clientes, siempre con una diferencia de unos pocos
   miles a millones de pesos que no es error del CRM). **Conciliar siempre contra los archivos
   exportados, nunca contra lo que muestra la pantalla de Contagram.**
6. **Fechas con día/mes invertido** aparecen en varios lugares distintos con la misma regla de
   recuperación (día ≤12 en celda tipo fecha → viene invertido): `Cuentas/`, `Clientes.Creado`,
   `Gastos/` (formato mes/día). Aplicar la regla en cada Excel nuevo, no asumir que ya está resuelto
   globalmente.
7. **Comparar sólo hasta la fecha de corte real de cada fuente**, no contra "hoy": cajas/bancos,
   Mercado Pago, Cta Cte Proveedores y Cta Cte Clientes tuvieron cada uno su propio corte distinto en
   el VPS (ver bitácora §16). Para la base nueva el corte va a ser otro (el de esta migración), pero
   la metodología —cada fuente compara sólo hasta donde su propio export llega— aplica igual.
8. **Antes de asumir "falta plata", buscar si hay una nota de crédito que lo explique.** Un cobro que
   desaparece de un informe no necesariamente se borró: puede haberse anulado con NC (§21 de la
   bitácora).

**Ya existe código reusable**: `app/Console/Commands/NormalizarTesoreriaContagram.php` (versionado
en el repo, no un script suelto) implementa varias de estas reconstrucciones sobre el VPS. Antes de
escribir lógica nueva para la base local, revisar si se puede **adaptar/reusar** apuntándolo a
`contagram_migracion` en vez de rederivar todo de cero.

## Estado del entorno local (13/08/2026)

- Base MySQL nueva: **`contagram_migracion`** (XAMPP local, `root` sin password). Ya tiene el
  esquema completo (78 tablas, migraciones corridas de punta a punta) y **está vinculada en `.env`**
  — la app corre contra ella ahora mismo.
- **Catálogo/config ya copiado tal cual** desde la base local `contagram` (no del VPS — el VPS no se
  toca, ver regla arriba): `categorias`, `clientes` (19.510), `cliente_contactos`, `condiciones_iva`,
  `configuracion_ventas`, `cuentas_tesoreria` (21, catálogo — sin movimientos), `datos_empresa`,
  `depositos`, `etiquetas`/`etiquetables`, `funciones_avanzadas`, `listas_precio`, `localidades`
  (4.037), `ml_configuracion`, `ml_bot_configuracion`, `ml_cuentas`, `ml_publicacion_producto` (270),
  `permisos`/`permiso_rol`, `precios_producto` (110.156), `productos` (9.630), `producto_variantes`,
  `proveedores` (145), `proveedor_contactos`, `provincias`, `puntos_venta`, `roles`/`rol_usuario`,
  `stocks` (9.193 — snapshot de hoy, se va a actualizar con el stock real cuando ventas/compras estén
  al día), `tipos_producto`, `tn_conexion_rest`, `tn_variante_producto` (85), `users` (6),
  `vendedores` (12). Dump en `migracion-nueva/scripts-import/catalogo_desde_contagram_local_20260813.sql`.
  Verificado conteo exacto igual al origen en las tablas más grandes, 0 errores al cargar.
- **También copiadas** (a pedido explícito, no son contables — son estado de integración):
  `ml_ordenes` (137) + `ml_orden_items` (137), `tn_ordenes` (4) + `tn_orden_items` (4). Dump en
  `migracion-nueva/scripts-import/ordenes_ml_tn_desde_contagram_local_20260813.sql`. Verificado
  conteo exacto. **Ojo**: `ml_ordenes.venta_id` puede apuntar a una venta que todavía no existe en
  `contagram_migracion` (las ventas se importan después, año por año) — se cargó con
  `FOREIGN_KEY_CHECKS=0`, revisar cuando se importen las ventas del año correspondiente que el
  vínculo siga siendo válido.
- **Deliberadamente NO copiado**: todo lo contable/transaccional (`ventas`, `compras`, `cobros`,
  `pagos`, `gastos`, `movimientos_tesoreria`, `movimientos_stock`, `notas_credito_debito` y sus
  ítems, `presupuestos`, `remitos`) y toda auditoría/logs
  (`logs_auditoria`, `arca_logs_auditoria`, `ml_operaciones_log`, `tn_rest_operaciones_log`) — eso se
  reconstruye año por año con el plan de importación. Tampoco `certificados_fiscales` (referencia al
  certificado ARCA real): se restaura en un paso aparte, controlado, el día del corte a producción —
  no antes, para que este entorno de pruebas no tenga capacidad de facturar contra ARCA real por
  accidente.
- La base local anterior (`contagram`) queda intacta, sin tocar, como referencia — y sigue siendo la
  fuente de comparación preferida sobre el VPS para cualquier consulta futura.
- `migracion-nueva/excel-origen/` — copia completa de `public/imports/` (el original en `public/`
  no se tocó ni se borró). Es la fuente de los Excel ya analizados y validados.
- `migracion-nueva/scripts-import/` — vacío todavía, para los scripts de import nuevos que se
  escriban acá (no reusar directo los que corrieron sobre el VPS, están pensados para esa corrida
  puntual — sí reusar la lógica/decisiones que documentan).

### Bugs de migraciones de Laravel corregidos (13/08/2026)

Al correr `php artisan migrate` desde cero contra una base 100% vacía (nunca se había hecho —
siempre se migraba incrementalmente sobre una base que ya tenía tablas de antes) aparecieron 4
migraciones con **orden incorrecto**: referenciaban una tabla o columna que recién se creaba en una
migración con fecha posterior. Invisible en cualquier base existente (la tabla/columna ya estaba),
rompía en una base nueva. Se renombraron los archivos para correr en el orden correcto:

- `configuracion_ventas` (FK a `vendedores`) → movida a después de `create_vendedores_table`.
- Su alter de defaults → misma corrección de orden.
- `ml_configuracion.lista_precio_id_premium` (`after('lista_precio_id')`) → movida a después de
  `add_lista_precio_field_to_ml_configuracion_table`.
- `compras.descuento_general_tipo` (`after('descuento_general_pct')`) → movida a después de
  `add_descuento_general_pct_to_compras_table`.

Es un fix de código real, sirve para cualquier instalación fresca del proyecto, no sólo para esta
migración.

## Dónde está el análisis de los datos (no repetir el trabajo)

Todo esto es la fuente de verdad de **qué dice cada Excel, qué se decidió y por qué**. Están en
`docs/` (no se copiaron acá para no tener dos versiones divergiendo — son documentos vivos):

| Documento | Qué tiene |
|---|---|
| `docs/importacion_datos_reales_2026_bitacora.md` | Bitácora completa del análisis: productos, clientes, gastos, ventas 2021-2026, compras, cuentas/cobros, matching de ML y Tiendanube. 871 líneas — es el más largo y el más importante. |
| `docs/importacion_2021_2026_plan_tecnico.md` | Diseño técnico del importador: qué archivo es fuente de qué campo, alcance y corte (05/08/2026), cifras de control de aceptación. |
| `docs/importacion_ventas_historicas.md` | Cómo se corrió el import de ventas 2021-2025 en su momento (comando, gotchas de archivos, idempotencia por `legacy_id`). |
| `docs/importacion_casos_a_revisar.md` | Casos borde encontrados **después** de importar en el VPS: bugs de la app que el volumen destapó, NC/ND sin comprobante, cta cte de clientes/proveedores, fechas de pagos/cobros migrados. Este es el que más directamente explica **la causa del descuadre que motivó esta migración** — imprescindible releer antes de reimportar tesorería/cobros/pagos. |

### Reglas de negocio que NO se pueden perder al reimportar (resumen de la bitácora)

1. **No se excluye ninguna venta.** Requisito explícito del usuario: "todo lo que está en los Excel
   tiene que estar", incluidas las del cliente sentinela (`NO USAR MAS`), sin comprobante fiscal,
   con NC/ND. Cifras de control de aceptación: facturado $1.570.665.960,38 · cobrado
   $1.506.014.720,12 · a cobrar $9.882.255,64 (2021-05/08/2026).
2. **No se crean productos fantasma.** Líneas de venta sin producto matcheable en el catálogo →
   `venta_items` con `producto_id = NULL` y `descripcion` propia, no se inventa un producto.
3. **Corte limpio 05/08/2026**: hasta ahí se reimporta todo del Excel; lo cargado en el CRM desde el
   06/08 en adelante no se toca ni se duplica (ver método de matching ML/Tiendanube en la bitácora
   §"Tema 1" y "Tiendanube").
4. **Fechas invertidas (BUG 2 de la bitácora)**: en `Cuentas/` y en la columna `Creado` de
   `Clientes/`, Excel guardó como fecha real (día↔mes invertido) las celdas con día ≤12 y como texto
   las de día >12 — hay una regla de recuperación determinística documentada, no adivinar.
5. **Numeración fiscal**: la serie interna `0001-` (PV histórico) y la serie real de ARCA (PV 9,
   CRM) son espacios separados que nunca deben colisionar. No "correr" ventas históricas a la serie
   fiscal. Ver bugs de `siguienteNroComprobante()`/`siguienteNumero()` ya corregidos en el código
   (usan `max()` acotado en vez de `count()`).
6. **Compras 2025-2026**: falta conseguir el formato "por ítem" (35 columnas) de 2026 — ver estado
   en la bitácora, puede haber cambiado desde el 10/08.

## Qué falta antes de reimportar acá

No repetir el análisis de arriba — sí falta, específico de esta migración local:

1. Revisar si hay Excel más nuevos que los copiados acá (la carpeta `excel-origen/` es una foto del
   13/08/2026 de `public/imports/`; si el usuario pasó algo después, hay que traerlo).
2. Escribir/adaptar los scripts de import para que corran contra `contagram_migracion` en vez del
   VPS (mismas decisiones documentadas arriba, pero apuntando a `.env` local).
3. Definir el checklist de cuadre (caja por fecha, cta cte clientes/proveedores) que valide la base
   nueva antes de siquiera pensar en un corte a producción — el motivo original de este proyecto es
   justamente que la última vez no se validó esto a tiempo.
4. Integraciones (Mercado Libre, Tiendanube): definir cómo se van a traer sus históricos a la base
   nueva sin repetir el incidente de `nunca-resetear-ultima-sync-en-produccion` (255 ventas creadas
   de golpe) — pausar creación automática antes de tocar nada, importar con la sync pausada,
   reactivar recién al final.
