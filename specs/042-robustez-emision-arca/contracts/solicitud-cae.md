# Contrato: Solicitud de CAE (`FECAESolicitar`) corregida

Este contrato describe la forma del payload `FeCAEReq` que arma `MapeadorComprobante::mapear()`
después de esta spec, y las precondiciones que `ValidadorDatosFiscales` evalúa antes de intentar el
envío real. No cambia el contrato HTTP de `POST ventas/{venta}/enviar-arca` (spec 040) — sigue
devolviendo `422` para rechazos de precondición y `200 {ok: true/false}` para intentos reales contra
ARCA.

## Precondiciones nuevas (evaluadas antes de contactar a ARCA)

Se suman a las ya existentes de spec 040 (`Venta::puedeEnviarseAArca()`, certificado fiscal activo).
Un rechazo de cualquiera de estas devuelve el mismo patrón de rechazo de precondición ya definido
(`ArcaRechazoException` → `422` en el endpoint de spec 040, sin contactar a ARCA):

| Precondición | Motivo de rechazo (ejemplo) |
|---|---|
| Alícuota de un ítem sin código ARCA soportado | "El ítem tiene una alícuota de IVA (X%) no soportada por ARCA." |
| Suma de bloques `AlicIva` no coincide con `ImpIVA`/`ImpNeto` (tolerancia $0.01) | "El IVA calculado no coincide con la suma por alícuota — revisar los ítems de la Venta." |
| Cliente identificado sin Condición de IVA cargada | "El cliente no tiene Condición de IVA cargada — cargala en Base de Datos → Clientes antes de enviar." |

## Payload `FeCAEReq` — ejemplo con alícuotas mixtas (21% + 10,5%)

```json
{
  "FeCabReq": { "CantReg": 1, "PtoVta": 9, "CbteTipo": 6 },
  "FeDetReq": {
    "FECAEDetRequest": {
      "Concepto": 1,
      "DocTipo": 80,
      "DocNro": "20111222333",
      "CbteDesde": 1,
      "CbteHasta": 1,
      "CbteFch": "20260804",
      "ImpTotal": 133100.00,
      "ImpTotConc": 0,
      "ImpNeto": 110000.00,
      "ImpOpEx": 0,
      "ImpIVA": 23100.00,
      "ImpTrib": 0,
      "MonId": "PES",
      "MonCotiz": 1,
      "CondicionIVAReceptorId": 1,
      "Iva": {
        "AlicIva": [
          { "Id": 5, "BaseImp": 100000.00, "Importe": 21000.00 },
          { "Id": 4, "BaseImp": 10000.00, "Importe": 2100.00 }
        ]
      }
    }
  }
}
```

**Diferencia vs. el payload que causó el incidente del 04/08/2026** (`arca_logs_auditoria` id=1):

- Antes: un único `AlicIva` con `Id=5` fijo, sin relación garantizada entre `Importe`/`BaseImp` y el
  21% real → rechazo código 10051.
- Ahora: un `AlicIva` por alícuota real presente en los ítems, cada uno matemáticamente consistente
  con su porcentaje (validado antes de enviar) — y `CondicionIVAReceptorId` presente, evitando el
  rechazo que ARCA aplicará por su ausencia a partir del 01/09/2026.

## Payload — caso de alícuota única (comportamiento hoy ya funcional, sin cambios observables)

```json
{
  "Iva": {
    "AlicIva": { "Id": 5, "BaseImp": 100000.00, "Importe": 21000.00 }
  }
}
```

Cuando sólo hay una alícuota presente, el bloque `AlicIva` sigue siendo un único objeto (no un array
de un elemento) — mismo formato que WSFEv1 espera hoy, sin regresión para el caso simple.
