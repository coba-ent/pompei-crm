# Checklist de robustez y no-regresión: Importación escalable

**Purpose**: Verificar antes de dar la feature por terminada que resuelve el problema real y que no
rompió nada de lo que ya andaba.
**Created**: 2026-08-25
**Feature**: [spec.md](../spec.md)

> Esta feature toca **precios, costos y stock** sobre el catálogo en vivo. Por el principio IV de la
> constitución, los ítems de testing de acá no son opcionales.

## El problema original, resuelto

- [ ] Una planilla de 9.000+ filas se importa **completa** por el asistente web, sin intervención por
      línea de comandos (SC-001)
- [X] El archivo se interpreta **una sola vez** por importación, no una vez por tanda (FR-001)
- [X] La memoria de una tanda **no crece** con el tamaño total del archivo (FR-003)
- [ ] Una tanda termina con al menos **2x de margen** frente al límite de tiempo del servidor (FR-005)
- [ ] El progreso avanza de forma visible durante toda la importación, sin trabarse (FR-006)

## Resiliencia

- [ ] Un fallo transitorio de una tanda se reintenta solo (2 s, 4 s, 8 s) y la importación continúa
      (FR-007)
- [X] Un fallo **de validación** (422) **no** se reintenta — reintentarlo sólo repetiría el error
- [ ] Tras agotar los reintentos aparece el error en lenguaje claro y el botón de retomar (FR-008)
- [X] Al retomar se procesan **exactamente** las filas pendientes: ninguna repetida, ninguna salteada
      (FR-009)
- [X] Retomar con un mapeo que ya no corresponde al archivo se detecta y se informa, en vez de
      escribir en columnas equivocadas

## Idempotencia (lo más fácil de romper)

- [X] Reprocesar el mismo `offset` **no duplica** filas en `importacion_filas_snapshot`
- [X] `COUNT(*)` y `COUNT(DISTINCT numero_fila)` de una corrida dan **el mismo número**
- [X] Reprocesar el mismo `offset` **no recuenta** las filas en los contadores de la corrida
- [X] Una importación cortada y retomada queda como **una sola** corrida a efectos del deshacer
      (FR-010)
- [X] El deshacer de una corrida cortada y retomada restaura **todas** las filas, no sólo las de la
      primera tanda

## No-regresión (specs 026 / 027 / 074 / 078)

- [X] La suite de tests existente de importación pasa **sin modificar ninguna expectativa** (SC-007)
- [X] El upsert por Id se comporta igual: alta / actualización parcial / alta preservando id / fallida
      (FR-011)
- [X] Una fila inválida se reporta con su motivo y **no** aborta el resto del archivo (FR-012)
- [X] Reimportar una planilla sin cambios genera **cero** eventos de auditoría y **cero** movimientos
      de stock (FR-014)
- [X] El stock se sigue fijando de forma atómica, sin reabrir la ventana de *lost update* de la spec
      074 (FR-015)
- [X] El automapeo por encabezado y alias sigue funcionando, incluidos `Stock {depósito}` y `Punto de
      Reposición` (FR-017)
- [X] Un archivo que entra en una sola tanda se comporta **idénticamente** a antes (FR-018)
- [X] El comportamiento heredado de `$limite = null` (que **ignora** el `offset`) se preserva y queda
      cubierto por un test

## Estado transitorio

- [X] El archivo volcado se borra al **terminar** la importación
- [X] El archivo volcado se borra al **cancelar**
- [X] Nada del archivo ni del mapeo se persiste en la base de datos (invariante de §2.4, FR-004)
- [X] Si el archivo temporal ya no está, se informa "volvé a subir el archivo" sin dejar la pantalla
      colgada

## Casos borde

- [X] Archivo sólo con encabezados → termina informando 0 filas, sin error
- [X] Archivo de una sola fila → se procesa en una tanda
- [ ] Cancelar a mitad → lo aplicado queda aplicado y los temporales se borran
- [ ] Cerrar el navegador a mitad → no queda una importación fantasma bloqueando la siguiente
- [ ] Dos importaciones de la misma entidad en dos pestañas → no se pisan los contadores entre sí
- [X] Fila con celdas corruptas en el medio → se reporta esa fila y el resto continúa

## Las tres entidades

- [ ] Productos & Servicios completa una importación grande
- [ ] Clientes completa una importación grande con sus reglas propias (CUIT/DNI en dos columnas,
      saldo inicial, lista de precios por nombre)
- [ ] Proveedores completa una importación grande con sus reglas propias

## Documentación y despliegue

- [X] §2.4 de `docs/documentacion_principal_crm.md` refleja el comportamiento nuevo
- [X] `docs/modelo_datos.md` **no** se tocó (esta feature no cambia el esquema) y eso está justificado
- [X] El paso de nginx quedó documentado como opcional y **no se ejecutó** sin autorización explícita
- [ ] Las pruebas funcionales se hicieron en **local**, no en producción

## Estado (25/08/2026)

Los ítems marcados quedaron cubiertos por la implementación y por los tests automatizados
(`tests/Unit/FuenteFilasImportacionTest.php`, `tests/Feature/ImportacionPorTandasTest.php` y la
suite de no-regresión de las specs 026/027/074/078).

Los **12 ítems sin marcar** son los que sólo se pueden verificar corriendo el asistente en el
navegador con un archivo real de 9.000+ filas y provocando un corte a mano (T027 y T028). Van **en
local, nunca en producción**.

## Notes

- El ítem de `COUNT(*)` vs `COUNT(DISTINCT numero_fila)` es el que detecta el problema de idempotencia
  descrito en la Decisión 5 de `research.md`. Es barato de correr y es el canario de toda esta feature.
- Los ítems de no-regresión son el contrato con las specs anteriores. Si alguno obliga a cambiar una
  expectativa de un test existente, **eso es una regresión disfrazada**: hay que entender por qué
  antes de tocar el test.
