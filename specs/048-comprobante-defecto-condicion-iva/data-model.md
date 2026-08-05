# Data Model: Comprobante por defecto derivado de la Condición de IVA

Sin cambios de esquema — no se agregan columnas, tablas ni entidades nuevas.

`clientes.tipo_comprobante_defecto` y `clientes.condicion_iva_id` ya existen (spec original de Base de
Datos). Esta feature sólo agrega, en el frontend del modal, una derivación automática del primero a
partir del segundo, análoga a la que ya existe en backend en dos lugares:

| Origen (ya existente) | Regla | Dónde vive hoy |
|---|---|---|
| `App\Services\Tiendanube\ResolutorCliente::tipoComprobantePorCondicionIva()` | `nombre === 'Responsable Inscripto' ? 'A' : 'B'` | Backend, conversión automática de órdenes Tiendanube |
| `App\Services\MercadoLibre\DerivadorComprobante` (`MAPEO_CONDICION_IVA_CRM` + comparación) | `nombreCondicionIva === 'Responsable Inscripto' ? 'A' : 'B'` | Backend, conversión automática de órdenes MercadoLibre |
| **Nuevo**: `cliente-modal.js` | Mismo criterio, aplicado al texto visible de la `<option>` seleccionada de `condicion_iva_id` | Frontend, modal manual de alta/edición de Cliente |

No se persiste ninguna regla nueva en base de datos — el criterio queda expresado en JS del mismo modo
que ya está expresado dos veces en PHP; no se extrae a un endpoint ni a una tabla de configuración
porque el criterio es estable y binario (research.md R2).
