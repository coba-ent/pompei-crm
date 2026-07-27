# Quickstart / Validación: Base de Datos — Clientes

Guía para validar de punta a punta que la feature de Clientes funciona. Referencia el
[data-model](./data-model.md) y el [contrato de rutas](./contracts/clientes-rutas.md).

## Prerrequisitos

- App levantada: `php artisan serve` (backend) y `npm run dev` (assets) — o `npm run build`.
- Base de datos `contagram` migrada y con seeders: `php artisan migrate --seed`.
- El seeder debe haber cargado el catálogo de `condiciones_iva`.

## Setup

```bash
php artisan migrate --seed
php artisan serve
```

## Escenarios de validación

### 1. Alta de cliente básico (US1)

1. Ir a `/clientes` → botón "Nuevo Cliente" (abre el **modal**, sin recargar).
2. Completar sólo "Nombre / Razón social" y guardar.
3. **Esperado**: el modal se cierra, la DataTable se actualiza mostrando el nuevo cliente (activo) y
   aparece un **toast** de éxito. La página nunca se recarga.
4. Intentar crear otro sin nombre → **esperado**: el modal permanece abierto, muestra el error de
   validación en `nombre` (respuesta 422) y/o un toast de error, sin recargar.

### 2. Datos de facturación y "apto para facturar" (US2)

1. Editar un cliente, ingresar un CUIT válido (ej. `20111111112`) y presionar "Verificar".
2. **Esperado**: si el formato/DV es válido, no hay error; si el verificador devuelve datos, se
   autocompletan (con el stub actual devuelve `null` y los campos quedan como estaban — sin bloquear).
3. Ingresar un CUIT con DV inválido (ej. `20111111113`) → **esperado**: rechazo por formato.
4. Seleccionar condición de IVA y tipo de comprobante, guardar.
5. **Esperado**: el cliente figura como "apto para facturar".
6. Quitar la condición de IVA e intentar marcarlo apto → **esperado**: rechazo indicando que la
   condición de IVA es obligatoria.

### 3. Listar, buscar y filtrar (US3)

1. Con varios clientes cargados, buscar por parte del nombre → **esperado**: sólo coincidencias.
2. Buscar por CUIT → **esperado**: cliente correcto.
3. Filtrar por "activos" → **esperado**: se excluyen inactivos. Filtrar por categoría → sólo esa.

### 4. Baja lógica y eliminación (US4)

1. Inactivar un cliente → **esperado**: desaparece de selectores de operación, sigue en listado
   (filtro inactivos), reactivable.
2. Intentar eliminar un cliente **con** operaciones → **esperado**: rechazo ("sólo puede
   inactivarse"). (Mientras no existan operaciones, `tieneOperaciones()==false`; este caso se valida
   plenamente cuando existan Ventas.)
3. Eliminar un cliente **sin** operaciones → **esperado**: se elimina definitivamente.

### 5. Unicidad de CUIT (FR-016)

1. Crear cliente con CUIT `20111111112`. Crear otro con el mismo CUIT → **esperado**: rechazo por
   unicidad.
2. Crear dos clientes **sin** CUIT → **esperado**: ambos permitidos.

## Validación automatizada

```bash
php artisan test --filter=Cliente
```

Cubre (ver `tasks.md` para el detalle): validación de CUIT (`CuitValidoTest`), lógica apto-facturar
(`ClienteAptoFacturarTest`), alta/validación (`ClienteAltaTest`), facturación/unicidad de CUIT
(`ClienteFacturacionTest`), listado/búsqueda/filtros (`ClienteListadoTest`) y baja/eliminación
(`ClienteBajaTest`).

## Validación de performance (SC-005)

Para verificar que el listado responde en <5 s con una cartera grande:

```bash
php artisan db:seed --class=ClientesDemoSeeder   # genera ~1.000 clientes de prueba (factory)
```

Luego abrir `/clientes` y buscar por nombre/CUIT: la DataTable usa procesamiento **server-side**
(yajra), por lo que sólo pagina/consulta el subconjunto necesario. **Esperado**: respuesta percibida
<5 s. (El seeder de demo es sólo para pruebas; no se corre en producción.)

## Criterios de éxito verificados

- SC-001 alta básica rápida · SC-002 nunca apto sin condición IVA · SC-003 verificar autocompleta ·
  SC-004 no eliminar con operaciones · SC-005 búsqueda en cartera grande · SC-006 no persistir CUIT
  inválido.
