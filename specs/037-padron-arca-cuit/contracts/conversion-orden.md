# Contrato: Determinación de tipo de comprobante en conversión de orden (interno, sin endpoint nuevo)

No se agrega ningún endpoint ni parámetro nuevo a los controllers de conversión
(`TiendanubeVentaController::convertir`/`convertirGuardar`, `MercadoLibreVentaController` análogo).
El cambio es interno a `ResolutorCliente::tipoComprobante()` (Tiendanube) y su equivalente de
MercadoLibre, invocado por `ConversorOrdenAVenta::previsualizar()` y `convertir()` — ambos flujos
(manual y automático) pasan por el mismo método, así que el cambio aplica a los dos sin distinción.

## Contrato del método (ampliado)

```php
// App\Services\Tiendanube\ResolutorCliente (y análogo MercadoLibre)
public function tipoComprobante(?Cliente $cliente, TiendanubeOrden $orden): string
```

**Comportamiento nuevo** (reemplaza sólo la rama de "no hay condición de IVA ya cargada"):

1. Si `$cliente` existe y tiene `condicion_iva_id` cargado → **sin cambios**: se usa esa condición,
   el padrón NO se consulta (FR-007a).
2. Si `$cliente` es `null` (se va a crear) o no tiene `condicion_iva_id` cargado, y la orden trae
   un documento que luce como CUIT (11 dígitos):
   - Se consulta `ClientePadron::consultarConstancia()` con ese CUIT.
   - Si el padrón responde y mapea a una condición de IVA conocida (research.md R6) → se usa esa
     condición para derivar el tipo de comprobante (FR-007), y esos datos (razón social, domicilio,
     condición IVA) se completan en el `Cliente` si no los tenía (FR-007b), respetando
     `completarDatosFiscalesSinPisar()`.
   - Si el padrón no responde, no encuentra el CUIT, o no mapea → **fallback**: se usa
     `tipoComprobantePorDocumento()` tal como existe hoy (FR-008).
3. Si la orden no trae un documento con forma de CUIT → sin cambios, va directo a
   `tipoComprobantePorDocumento()` (FR-008), sin intentar consultar el padrón.

## Garantías de resiliencia (FR-008, FR-009, Constitución III)

- Ninguna excepción de `ClientePadron` (`ArcaNoDisponibleException`) debe propagarse fuera de
  `tipoComprobante()`: se captura internamente y se trata como "padrón no disponible" → fallback.
- En `convertirTodasLasListas()` (conversión en lote), una falla de padrón en una orden no debe
  interrumpir el procesamiento de las órdenes siguientes del lote (ya se cumple porque la falla no
  se propaga fuera de `tipoComprobante()`, ver punto anterior).
