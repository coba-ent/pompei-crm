# Quickstart — Validación de Importador de Datos: Campos Completos

Guía para validar la ampliación de campos de punta a punta. No incluye código de implementación (eso va
en `tasks.md`/implementación); son los pasos y resultados esperados que prueban que el módulo funciona.
Complementa (no reemplaza) `specs/006-importar-datos-excel/quickstart.md`, que sigue siendo válido para
el mecanismo base.

## Prerrequisitos

- Spec 006 (mecanismo base del asistente "Importar Datos") ya implementada y en verde.
- Al menos una `ListaPrecio` activa cargada (para probar el campo "Lista de Precios" de Clientes).
- Un archivo `.xlsx` de prueba de Clientes con columnas para: Razón Social, Tipo de Documento, Domicilio/
  Localidad/Provincia/CP Fiscal, Teléfono Fiscal, Teléfono Celular Fiscal, Código Postal, Saldo Inicial,
  Fecha de Saldo Inicial (con al menos una fila en `DD/MM/YYYY`, una en `YYYY-MM-DD` y, si el archivo es
  `.xlsx`, una con fecha nativa de Excel), Nota para Ventas, Descuento General, Lista de Precios (con un
  valor que matchea y uno que no), Usuario de Mercado Libre, Página Web — y al menos una fila con un valor
  de fecha inválido (ej. texto suelto) para probar el fallo controlado.
- Un archivo `.xlsx` de prueba de Proveedores con el mismo bloque de columnas fiscales/saldo (sin ML/Nota
  para Ventas/Descuento/Lista de Precios, que no aplican).
- Un archivo `.xlsx` de prueba de Productos con columnas Activo/Mostrar en Ventas/Mostrar en Compras con
  una mezcla de valores `Si/No`, `1/0`, `true/false`, alguna celda vacía, y una fila con un valor no
  reconocido (ej. "tal vez") para probar el fallo controlado.
- Opcional: los archivos reales del negocio en `public/imports/clientes.xlsx`, `proveedores.xlsx`,
  `productos.xlsx` sirven como caso de validación real de máximo volumen (no como fixture de test
  automatizado, por su tamaño).

## Setup

```bash
php artisan serve               # levanta la app en http://127.0.0.1:8000
```

## Escenarios de validación

### US1 — Importar Clientes con datos comerciales y fiscales completos

1. En `/clientes` → "Importar datos" → subir el archivo de prueba de Clientes.
2. En el paso de mapeo, verificar que el selector de cada columna ofrece, además de los campos ya
   existentes, los 12 campos nuevos listados en `data-model.md` (Razón Social, Tipo de Documento, bloque
   fiscal completo, Código Postal, Saldo Inicial, Fecha de Saldo Inicial, Nota para Ventas, Descuento
   General, Lista de Precios, Usuario de Mercado Libre, Página Web).
3. Mapear todas las columnas del archivo de prueba a sus campos correspondientes → confirmar.
4. En el resumen: verificar que las filas con fecha válida (en cualquiera de los 3 formatos aceptados) se
   importaron con `saldo_inicial_fecha` correcto, y la fila con fecha inválida aparece como fallida con
   motivo claro.
5. Verificar en `/clientes` que un cliente con "Lista de Precios" matcheada quedó asociado a esa lista, y
   uno con un valor no coincidente se creó igual con la advertencia correspondiente en el resumen (no como
   fallo).
6. Verificar que los clientes creados muestran razón social, domicilio fiscal y demás campos nuevos
   correctamente guardados (comparar contra el archivo de prueba).

### US2 — Importar Proveedores con datos fiscales y saldo

7. Repetir los pasos 1-4 desde `/proveedores`, con el archivo de prueba de Proveedores — mismo
   comportamiento para el bloque fiscal + saldo inicial + fecha; verificar que el selector **no** ofrece
   Usuario de Mercado Libre, Nota para Ventas, Descuento General ni Lista de Precios (no existen en
   Proveedor).

### US3 — Importar Productos con estado Activo/Mostrar en Ventas/Mostrar en Compras

8. En `/productos` → "Importar datos" → subir el archivo de prueba de Productos.
9. Mapear Activo, Mostrar en Ventas y Mostrar en Compras → confirmar.
10. Verificar en `/productos` que cada producto quedó con el estado correcto según el valor de su fila
    (`Si/1/true` → activo/visible; `No/0/false` → inactivo/no visible), que las filas con celda vacía
    quedaron con el default (`true`), y que la fila con valor no reconocido aparece como fallida en el
    resumen con motivo claro.

## Tests automatizados

```bash
php artisan test --filter=ImportacionDatos
```

Debe quedar en verde antes de dar la feature por terminada (Principio IV de la constitución: hay
validación de saldo inicial —dinero— y de visibilidad en Ventas/Compras en el camino de importación).

## Consistencia de documentación (antes de cerrar la feature)

- Actualizar `docs/documentacion_principal_crm.md §2.6` con la lista ampliada de campos mapeables por
  entidad.
- Agregar en `docs/documentacion_principal_crm.md §5` la brecha pendiente "Punto Reposición" (columna del
  archivo real de Productos sin campo equivalente en el modelo — fuera de alcance de esta feature,
  decisión documentada en `spec.md` Assumptions).
- Confirmar que `docs/modelo_datos.md` no requiere cambios (sin tablas ni columnas nuevas).

## Criterios de aceptación cubiertos

SC-001 (100% de columnas útiles de Clientes con destino de mapeo disponible), SC-002 (ídem Proveedores),
SC-003 (Activo/Mostrar en Ventas/Mostrar en Compras importables sin revisión manual posterior), SC-004
(fechas/booleanos no interpretables reportados como fila fallida sin abortar el resto del archivo).
