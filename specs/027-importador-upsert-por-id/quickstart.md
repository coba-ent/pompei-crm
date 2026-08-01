# Quickstart — Validación de Importador de Datos: Actualizar por Id (Upsert)

Guía para validar la actualización por Id de punta a punta. Complementa (no reemplaza) los quickstart de spec
006/026, que siguen siendo válidos para el mecanismo base y para los campos ampliados.

## Prerrequisitos

- Spec 006 y spec 026 (mecanismo base + campos ampliados del asistente "Importar Datos") ya implementadas y en
  verde.
- Al menos un Cliente, un Proveedor y un Producto ya creados (para tener ids reales contra los que actualizar).
- Un archivo `.xlsx` de prueba de Clientes con columnas: Id (con al menos una fila con un id existente, una con
  un id inexistente, una con un valor no numérico, y una con la celda vacía) + una o dos columnas de dato a
  corregir (ej. Saldo Inicial).
- Archivos equivalentes para Proveedores y Productos.

## Setup

```bash
php artisan serve               # levanta la app en http://127.0.0.1:8000
```

## Escenarios de validación

### US1 — Actualizar Clientes existentes por Id

1. Anotar el `id` de un cliente ya cargado (columna Id del listado `/clientes`, o `SELECT id FROM clientes LIMIT
   1`).
2. En `/clientes` → "Importar datos" → subir el archivo de prueba de Clientes.
3. En el paso de mapeo, verificar que el selector de cada columna ofrece "Id" entre los campos disponibles.
4. Mapear la columna Id al campo "Id" y la columna de dato a corregir a su campo correspondiente → confirmar.
5. En `/clientes`, verificar que el cliente con id existente quedó con el dato nuevo actualizado y el resto de
   sus campos (nombre, email, domicilio, etc.) intactos.
6. En el resumen, verificar que la fila con id inexistente aparece como fallida ("Id … no encontrado"), la fila
   con valor no numérico aparece como fallida ("Id … no es un id válido"), y la fila con celda Id vacía generó un
   cliente nuevo (alta, no actualización).

### US2 — Mismo mecanismo para Proveedores y Productos

7. Repetir los pasos 2-6 desde `/proveedores` y `/productos` con sus archivos de prueba respectivos.

## Tests automatizados

```bash
php artisan test --filter=ImportacionDatos
```

Debe quedar en verde antes de dar la feature por terminada (Principio IV de la constitución: la actualización
parcial no debe pisar `saldo_inicial` ni otros campos no mapeados).

## Consistencia de documentación (antes de cerrar la feature)

- Actualizar `docs/documentacion_principal_crm.md §2.4` con el comportamiento de actualización por Id.
- Confirmar que `docs/modelo_datos.md` no requiere cambios (sin tablas ni columnas nuevas).

## Criterios de aceptación cubiertos

SC-001 (corregir datos ya cargados sin recargar todo el archivo), SC-002 (100% de Id no encontrado → fila
fallida, sin duplicados), SC-003 (100% de campos no mapeados en una fila de actualización conservan su valor
previo).
