# Checklist de calidad: prevalidación y confirmación previa

**Purpose**: verificar, antes de dar la feature por terminada, que resuelve los cuatro defectos reales
y que no rompió nada de lo que ya andaba.
**Created**: 2026-08-26
**Feature**: [spec.md](../spec.md)

> Esta feature decide qué se escribe sobre **precios, costos y stock**. Por el principio IV de la
> constitución, los ítems de testing no son opcionales.

## El modal de confirmación

- [X] "Confirmar importación" abre el modal y **no** empieza a importar (FR-001)
- [X] El modal informa altas, actualizaciones y errores, con los números correctos (FR-001)
- [X] Estando el modal abierto, **nada** se creó ni se modificó en la base (FR-002)
- [X] Con actualizaciones, lista **qué campos** se van a modificar y **a cuántos registros** (FR-005b)
- [X] Los campos listados son exactamente los mapeados con valor en esas filas, ni uno más (I7)
- [X] Los campos se nombran por su **etiqueta visible**, no por el nombre interno (FR-005b)
- [X] Cancelar el modal deja el mapeo intacto y no toca ningún dato (FR-005c)
- [X] Con ≥ 1 fila con error, el botón de confirmar está **bloqueado** (FR-005)
- [X] Con 0 errores, se puede confirmar y la importación procede como antes (FR-006)
- [X] El detalle de errores indica número de fila y motivo (FR-004)
- [ ] Con cientos de errores, el modal sigue siendo usable y muestra el total real (FR-010)
- [X] La prevalidación muestra progreso mientras corre (FR-008)
- [ ] Un archivo de 10.000 filas se prevalida sin cortarse (FR-007, SC-008)
- [X] Confirmar con un archivo o mapeo distinto del prevalidado se rechaza (FR-009)

## La promesa central: la prevalidación no miente

- [X] Una fila que la prevalidación aprueba **nunca** falla en la importación real (FR-003, SC-003)
- [ ] Los conteos del modal coinciden con los del resumen final, en un caso sin cambios concurrentes
- [X] El validador y el importador coinciden fila por fila sobre el mismo archivo (I2 del contrato)

## Fórmulas de Excel

- [X] Un `.xlsx` con fórmulas sin cachear se importa con los **valores**, no con el texto (FR-011)
- [ ] El caso real: las 148 celdas `=+B2&" "&A2` entran como el código correcto (SC-004)
- [ ] Las 24 celdas `=+ROUND(L92,2)` entran como número y ya **no** hacen fallar la fila
- [X] Una fórmula que no se puede evaluar reporta error de esa fila (FR-012)
- [X] **Ningún** campo de texto guarda un valor que empiece con `=` de una fórmula sin resolver (FR-013)

## Round-trip exportación ↔ importación

- [X] Todas las columnas de un archivo recién exportado quedan automapeadas (FR-014)
- [X] "Precio venta" automapea (era el defecto concreto)
- [X] Reimportar un export sin modificar deja **cero** diferencias (FR-017, SC-005)
- [X] Existe un test que falla si alguien agrega una columna al export sin su alias (FR-016)
- [X] Se verificó si hay export de Clientes y Proveedores; si no hay, quedó registrado (FR-015)

## Mensajes de error

- [X] Todos los motivos están en español (FR-018, SC-006)
- [X] Ningún motivo contiene nombres internos (`precio_lista_2`, `tipo_producto_id`)
- [X] El caso real: en vez de *"The precio lista 2 field must be a number"* dice algo como
      *"AHORA 3 tiene que ser un número"* (FR-019)
- [X] Una fila con varios problemas los informa todos, no sólo el primero (FR-020)

## Integridad del resumen

- [X] El caso reproducido: 1000 residuales + 2 importados informa **2**, no 1002 (FR-021, SC-007)
- [X] Abandonar una importación no contamina la siguiente (FR-022)
- [X] El resumen muestra el **archivo** y la **fecha y hora** de esa corrida (FR-023)
- [X] Los números del resumen coinciden con lo que quedó en la base (FR-024)
- [X] Clientes y Proveedores (sin corrida) también tienen un resumen atado a su importación

## No regresión (specs 006 / 026 / 027 / 074 / 078 / 082)

- [X] La suite existente de importación pasa (SC-009)
- [X] El procesamiento por tandas, el reintento y la retoma de la spec 082 siguen funcionando (FR-025)
- [X] El deshacer de la spec 078 sigue funcionando sobre una importación prevalidada (FR-026)
- [X] Las reglas de alta/actualización por Id de la spec 027 se preservan (FR-027)
- [X] Las reglas de mapeo del Paso 2 se preservan (FR-027)
- [X] Las tres solapas se comportan igual: Clientes, Proveedores, Productos (FR-028, SC-006)

## Casos borde

- [X] Archivo sólo con encabezados → 0 altas, 0 actualizaciones, 0 errores, sin error
- [X] Archivo sin ninguna fila válida → todos los errores listados, confirmar bloqueado
- [X] Una fila mala en un archivo grande → bloquea el archivo entero (comportamiento **buscado**)
- [X] Actualización que no cambia nada → no infla el listado de campos afectados
- [ ] Cerrar el navegador con el modal abierto → no deja una importación fantasma

## Documentación

- [X] §2.4 de `docs/documentacion_principal_crm.md` refleja el modal y el cambio de tolerancia
- [X] `docs/modelo_datos.md` **no** se tocó, y está justificado
- [X] La reversión de la tolerancia por fila quedó documentada como decisión del usuario, con su
      caso límite
- [X] Las pruebas funcionales se hicieron en **local**, nunca en producción

## Notes

- El ítem que más importa es **"una fila que la prevalidación aprueba nunca falla"**: si eso no se
  cumple, el modal se vuelve decorativo y el bloqueo de FR-005 pasa a ser un obstáculo sin
  contrapartida.
- El segundo en importancia es el conteo por campo (FR-005b): si lista campos de más, el usuario va a
  desconfiar del modal y a confirmarlo sin leerlo.

## Estado (26/08/2026)

Marcados: los ítems cubiertos por tests automatizados o por revisión del código.

**Sin marcar, pendientes de la verificación manual en el navegador (T044–T046, T050):**

- Con cientos de errores, el modal sigue siendo usable y muestra el total real (FR-010)
- Un archivo de 10.000 filas se prevalida sin cortarse (FR-007, SC-008)
- Los conteos del modal coinciden con los del resumen final
- El caso real de las 148 celdas `=+B2&" "&A2` y las 24 celdas `=+ROUND(L92,2)` de `Ferrum nuevos (2).xlsx`
- Cerrar el navegador con el modal abierto → no deja una importación fantasma

Los tres primeros dependen de correr la app con los archivos reales; los dos últimos, de esos archivos
concretos y de un navegador. **Van en local, nunca en producción.**
