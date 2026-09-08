# Contrato: `App\Services\Arca\ConsultaPadron`

**Spec**: [../spec.md](../spec.md) | **Decisión**: [../research.md](../research.md) R1 y R2

Servicio nuevo que centraliza la consulta al padrón de ARCA, hoy duplicada en tres lugares. Es una
**extracción sin cambio de comportamiento**: la secuencia de llamadas, los guards y el manejo de
errores se conservan exactamente como están en las implementaciones actuales.

---

## Responsabilidad

Dado un CUIT, obtener del padrón de ARCA la identidad, el domicilio fiscal y la condición de IVA del
contribuyente, degradando sin excepciones ante cualquier falla.

**No es responsable de**: validar el dígito verificador (eso es `App\Rules\CuitValido`), decidir el
tipo de comprobante, ni persistir nada.

---

## Interfaz pública

### `consultar(?string $cuit): ?ResultadoConsultaPadron`

Consulta el padrón y devuelve el DTO, o `null` si no se pudo obtener nada.

**Comportamiento** (idéntico al actual de `DerivadorComprobante` y `ResolutorCliente`):

1. Normaliza el CUIT quitando todo lo que no sea dígito.
2. Si no quedan exactamente 11 dígitos → `null` (sin tocar la red).
3. Si no hay `CertificadoFiscal::activo()` → `null`.
4. Obtiene ticket WSAA para `ws_sr_padron_a13` y consulta identidad y domicilio.
   Ante `ArcaNoDisponibleException` → `null`.
5. **Best effort e independiente**: obtiene ticket para `ws_sr_constancia_inscripcion`, consulta y
   fusiona la condición de IVA. Ante `ArcaNoDisponibleException` → devuelve el resultado del paso 4
   **sin** condición de IVA (FR-004).

**Nunca propaga excepciones de ARCA.** Consumidores: `DerivadorComprobante` (Mercado Libre),
`ResolutorCliente` (Tiendanube).

---

### `paraModal(string $cuit): array`

Envuelve `consultar()` y traduce el resultado a la estructura JSON que consumen los modales de
Cliente y Proveedor. Existe para que ambos modales usen **exactamente los mismos mensajes** (FR-006 y
Assumptions de la spec).

**Devuelve** una de tres formas —ver [verificar-documento-proveedor.md](./verificar-documento-proveedor.md)
secciones C.1, C.2 y C.3 para el detalle y los textos exactos:

| Situación | Forma |
|-----------|-------|
| Sin certificado activo, o ARCA no disponible | `{consultado: false, mensaje: "No se pudo consultar el padrón de ARCA en este momento."}` |
| Consultado pero CUIT inexistente | `{consultado: true, encontrado: false, mensaje: "No se encontró el CUIT en el padrón de ARCA."}` |
| Encontrado | `{consultado: true, encontrado: true, ...datos}` con las claves nulas omitidas |

**Nota sobre `condicion_iva`**: se devuelve el **nombre** de la condición (resuelto desde el id del
DTO), no el id, porque el front la matchea por texto de opción.

**Distinción importante entre ambos métodos**: `consultar()` devuelve `null` tanto si ARCA no
respondió como si el CUIT no existe — a los consumidores automáticos les da igual. `paraModal()`
**sí** distingue ambos casos, porque el usuario necesita saber si debe reintentar o si el CUIT está
mal (FR-006).

---

## Contrato de migración de los consumidores actuales

La extracción debe dejar el comportamiento observable idéntico. Correspondencia:

| Consumidor | Método privado actual | Reemplazo |
|------------|----------------------|-----------|
| `DerivadorComprobante::consultarPadron()` | devuelve `?ResultadoConsultaPadron` | `consultar()` — sustitución directa |
| `ResolutorCliente::consultarPadron()` | idéntico al anterior | `consultar()` — sustitución directa |
| `ClienteController::consultarPadron()` | devuelve `array` formateado | `paraModal()` — misma estructura de salida |

**Verificación de no-regresión**: los tests existentes de los tres consumidores se conservan sin
modificar. Si el refactor alterara cualquier comportamiento, deben fallar
(`ClienteVerificarPadronTest.php` respalda específicamente FR-018 y SC-005).

---

## Testabilidad

El servicio debe resolver sus colaboradores (`ClienteWsaa`, `ClientePadron`,
`ClienteConstanciaInscripcion`) a través del **contenedor de Laravel**, tal como lo hacen hoy las
implementaciones actuales (`app()->makeWith(...)` con el certificado). Esto es un requisito, no un
detalle: los tests existentes mockean esas clases vía contenedor, y romper ese mecanismo obligaría a
reescribirlos — perdiendo justamente la red de seguridad del refactor.

---

## Lo que este contrato NO cubre

- La derivación del tipo de comprobante por defecto: vive en el front y es distinta para Cliente
  (A/B) y Proveedor (A/C/B) — ver research.md R3.
- El mapeo de provincia y la derivación de condición de IVA desde la respuesta cruda: siguen en
  `ResultadoConsultaPadron`, que no se modifica (research.md R6).
