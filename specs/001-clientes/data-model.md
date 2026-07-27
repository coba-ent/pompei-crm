# Data Model: Base de Datos — Clientes

Fase 1 del plan. Derivado de `docs/modelo_datos.md` (tabla `clientes` y soporte) y de la spec.
Single-tenant: ninguna tabla lleva `empresa_id`.

## Entidad principal

### `clientes`

| Campo | Tipo | Reglas / Notas |
|---|---|---|
| id | bigint PK | autoincremental |
| nombre | string(255) | **obligatorio** ("Cliente": empresa o nombre y apellido). FR-001/FR-002 |
| nombre_pila | string(255), nullable | "Nombre" de pila. FR-003a |
| apellido | string(255), nullable | FR-003a |
| apodo_ml | string(255), nullable | apodo de Mercado Libre. FR-003a |
| pagina_web | string(255), nullable | FR-003a |
| email | string(255), nullable | formato email si presente |
| telefono | string(50), nullable | |
| telefono_celular | string(50), nullable | |
| domicilio | string(255), nullable | calle y número (comercial) |
| localidad | string(120), nullable | |
| provincia | string(120), nullable | |
| cp | string(20), nullable | |
| nota | text, nullable | nota general. FR-003a |
| **— Facturación —** | | |
| razon_social | string(255), nullable | razón social fiscal (puede diferir de `nombre`). FR-026 |
| tipo_documento | string(20), nullable, default 'CUIT' | CUIT/CUIL/DNI/Pasaporte/CDI. FR-025 |
| cuit | string(11), nullable, **unique (ignora NULL)** | N° de doc fiscal. DV válido sólo si tipo es CUIT/CUIL (regla `CuitValido`). FR-006/FR-016/FR-025 |
| condicion_iva_id | bigint FK → condiciones_iva, nullable | obligatorio para ser apto-facturar. FR-009/FR-011 |
| tipo_comprobante_defecto | string(2), nullable | 'A' / 'B' / 'C' / 'E'. FR-010 |
| domicilio_fiscal | string(255), nullable | FR-027 |
| localidad_fiscal | string(120), nullable | FR-027 |
| provincia_fiscal | string(120), nullable | FR-027 |
| cp_fiscal | string(20), nullable | FR-027 |
| telefono_fiscal | string(50), nullable | FR-027 |
| telefono_celular_fiscal | string(50), nullable | FR-027 |
| **— Ventas —** | | |
| categoria_id | bigint FK → categorias, nullable | categoría tipo=venta. FR-013 |
| lista_precio_id | bigint FK → listas_precio, nullable | FR-013 |
| descuento_general_pct | decimal(5,2), nullable | 0 ≤ x ≤ 100. FR-013/FR-014 |
| nota_cliente | text, nullable | "Nota para el Cliente". FR-028 |
| saldo_inicial | decimal(14,2), default 0 | punto de partida de cta. cte. FR-013 |
| campos_personalizados | json, nullable | cast a array (clave/valor). FR-015 |
| activo | boolean, default true | baja lógica. FR-020/FR-021 |
| created_at / updated_at | timestamp | |

**Índices**: `unique(cuit)` (los NULL no colisionan en MySQL/MariaDB), índice en `nombre` y `cuit`
para búsqueda (FR-018), índice en `activo` y `categoria_id` para filtros (FR-019).

**Sin SoftDeletes**: la baja es lógica vía `activo`; la eliminación (sólo sin operaciones) es física.

### `cliente_contactos` (personas de contacto, 1..N — FR-029)

| Campo | Tipo | Reglas / Notas |
|---|---|---|
| id | bigint PK | autoincremental |
| cliente_id | bigint FK → clientes | **cascade on delete** (se borran con el cliente) |
| nombre | string(255) | requerido a nivel de fila (contactos sin nombre se descartan) |
| cargo | string(255), nullable | |
| telefono | string(50), nullable | |
| email | string(255), nullable | formato email si presente |
| created_at / updated_at | timestamp | |

## Reglas de negocio (en el modelo)

- `Cliente::esAptoParaFacturar(): bool` — true si `condicion_iva_id` presente; y si la condición de
  IVA exige CUIT (RI, Monotributo), además CUIT válido presente. (FR-011/FR-012, Principio III)
- `Cliente::tieneOperaciones(): bool` — extensible; hoy `false` (no existen presupuestos/ventas/
  cobros/comprobantes). Bloquea la eliminación física cuando sea `true`. (FR-022/FR-023)
- Validación de `descuento_general_pct` en rango [0,100]. (FR-014)
- Unicidad de `cuit` cuando no es NULL. (FR-016)

## Estados

`activo` (boolean) es el único estado:

```
activo = true  ──(inactivar)──►  activo = false
activo = false ──(reactivar)──►  activo = true
```

- `activo=false` → no disponible para nuevas operaciones, sí consultable (FR-021).
- Eliminación física: sólo permitida si `activo` es cualquiera pero `tieneOperaciones()==false`.

## Tablas de soporte (forma mínima para esta feature)

### `condiciones_iva` (catálogo, precargado por seeder)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | Responsable Inscripto, Monotributista, Consumidor Final, Exento, No Categorizado |
| codigo_afip | string, nullable | código de condición IVA de ARCA |
| requiere_cuit | boolean, default false | true para RI y Monotributo (usado por esAptoParaFacturar) |

### `categorias` (tipo venta — creada vacía; ABM propio en feature aparte)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tipo | enum(`venta`,`compra`,`producto`,`gasto`) | esta feature usa `venta` |
| categoria_padre_id | bigint FK → categorias, nullable | subcategorías |
| nombre | string | |

### `listas_precio` (creada vacía; ABM propio en feature aparte)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | ej. Mayorista, Minorista, Tarjeta |
| activo | boolean, default true | |

## Relaciones

```
condiciones_iva 1───N clientes
categorias      1───N clientes   (categoria tipo=venta)
listas_precio   1───N clientes
clientes        1───N cliente_contactos   (cascade on delete)
clientes        1───N (futuro) presupuestos, ventas, cobros, comprobantes
```

## Consistencia con docs de dominio

Este data-model coincide con `docs/modelo_datos.md` (tablas `clientes` y `cliente_contactos`). Dos
ajustes ya reflejados en el doc de dominio (Principio I):

1. Se agregó el flag `requiere_cuit` a `condiciones_iva` para la regla de aptitud de facturación.
2. Tras relevar el formulario real de Contagram (`capturas/crea cliente formulario.png`), se ampliaron
   los campos de `clientes` (nombre_pila, apellido, apodo_ml, pagina_web, nota, razón social,
   tipo_documento, bloque fiscal, nota_cliente) y se agregó la tabla `cliente_contactos`.
