# Data Model: Robustez de datos fiscales en la emisión de CAE (ARCA)

Sin cambios de esquema de base de datos. Todos los campos usados ya existen; esta spec sólo cambia
**cómo se usan** al armar la solicitud a WSFEv1.

## Entidades existentes involucradas

### VentaItem (sin cambios de esquema)

- `iva_pct` (decimal, existente): alícuota de IVA del ítem. Pasa a usarse para agrupar los ítems de
  una Venta por alícuota y armar un bloque `AlicIva` por cada grupo, en vez de ignorarse a favor de un
  total agregado.
- `subtotal` (decimal, existente): neto del ítem — se suma por alícuota para el `BaseImp` de cada
  bloque `AlicIva`.

### CondicionIva (sin cambios de esquema)

- `codigo_afip` (string, existente): ya contiene los códigos oficiales ARCA de "Condición IVA
  Receptor" (RG 5616). Pasa a incluirse en la solicitud de CAE como `CondicionIVAReceptorId`, además
  de su uso ya existente en la relación `Cliente->condicionIva`.

### Cliente (sin cambios de esquema)

- `condicion_iva_id` (FK, existente, nullable): si es `null` para un cliente identificado (con
  CUIT/DNI), la emisión se rechaza como precondición (FR-006) — no se agrega ninguna validación nueva
  a nivel de formulario de Cliente, sólo al momento de emitir un comprobante.

## Estructuras de datos internas (no persistidas)

### `MapeadorComprobante::mapear()` — nuevo parámetro `items` (opcional)

```text
array{
    ...campos existentes (tipo_comprobante, punto_venta, numero, fecha, cliente, neto, iva, total, ...),
    items?: array<int, array{neto: float, iva_pct: float}>,
    // Si está presente (Ventas): se agrupa por iva_pct y se arma un bloque AlicIva por grupo.
    // Si está ausente (NC/ND): se conserva el comportamiento actual (un único bloque AlicIva).
    cliente: array{
        cuit?: string|null,
        dni?: string|null,
        condicion_iva_codigo?: string|null,  // NUEVO — código ARCA ya resuelto por ValidadorDatosFiscales
    },
}
```

### Bloque `AlicIva` armado (por alícuota, salida hacia WSFEv1)

```text
array{
    Id: int,          // código ARCA de la alícuota (3/4/5/6/8/9)
    BaseImp: float,   // suma de netos de los ítems de esa alícuota
    Importe: float,   // IVA de esa alícuota
}
```

Múltiples bloques se agrupan en `FeDetReq.FECAEDetRequest.Iva.AlicIva` como array (WSFEv1 acepta una
lista de `AlicIva` cuando hay más de una alícuota en el comprobante).

## Reglas de validación nuevas (`ValidadorDatosFiscales`)

- Toda alícuota (`iva_pct` de los ítems) DEBE resolver a un código ARCA soportado (tabla fija: 0%,
  10,5%, 21%, 27%, 5%, 2,5%) — si no, rechazo de precondición (FR-004).
- La suma de `Importe`/`BaseImp` de los bloques `AlicIva` armados DEBE coincidir con
  `ImpIVA`/`ImpNeto` totales con tolerancia $0.01 (FR-003/FR-004).
- El cliente asociado al comprobante DEBE tener `condicion_iva_id` cargado, salvo que sea un receptor
  sin CUIT/DNI identificado (Consumidor Final anónimo, código 5 por defecto) (FR-005/FR-006/FR-007).
