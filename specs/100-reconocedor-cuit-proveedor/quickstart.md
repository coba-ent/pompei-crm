# Quickstart: validar el reconocedor de CUIT en Proveedores

**Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md) | **Fecha**: 2026-09-07

Guía para comprobar que la feature funciona de punta a punta. Dos partes: la suite automatizada
(backend) y la verificación manual en navegador (front, que el proyecto no cubre con tests de JS).

> **No validar en producción.** El VPS está en uso real. Todo lo de acá corre en local.

---

## Prerequisitos

- XAMPP con MySQL corriendo, base `contagram`.
- Dependencias instaladas (`composer install`, `npm install`).
- Assets compilados: `npm run build` (o `npm run dev` mientras se itera en `proveedores.js`).
- Credenciales de acceso vigentes en `CREDENCIALES_ACCESO.txt`.

Para la prueba manual con consulta real a ARCA hace falta además un certificado fiscal activo
cargado en Configuración. **Sin certificado igual se puede validar** el camino de degradación
(escenario M5), que es el más importante de los caminos de falla.

---

## Parte 1 — Suite automatizada

### 1.1 Tests de la feature

```bash
php artisan test --filter=ProveedorVerificarPadron
```

**Esperado**: verde, cubriendo los seis escenarios del contrato (padrón OK, sin certificado, ARCA
caída, CUIT no encontrado, padrón sin condición de IVA, documento no-CUIT).

### 1.2 No-regresión del refactor (crítico)

El servicio extraído toca tres consumidores en producción. Estos tests son la red de seguridad y
**deben pasar sin haber sido modificados**:

```bash
php artisan test --filter=ClienteVerificarPadron
php artisan test --filter=VerificacionDocumento
```

**Esperado**: verde. `ClienteVerificarPadronTest` respalda FR-018 y SC-005: si falla, el refactor
cambió el comportamiento de Cliente y hay que corregirlo antes de seguir.

### 1.3 Suite completa

```bash
php artisan test
```

**Esperado**: verde. Presta atención a los tests de Mercado Libre y Tiendanube: son los otros dos
consumidores migrados al servicio.

> Recordatorio del proyecto: la suite corre en SQLite y producción es MySQL, así que verde no
> garantiza que el navegador funcione. La Parte 2 no es opcional.

---

## Parte 2 — Verificación manual en el navegador

Entrar a **Base de Datos → Proveedores** y abrir **Nuevo Proveedor** → solapa **Datos de
facturación**.

### M1 — Autocompletado desde el padrón (US1, camino feliz)

1. Tipo de documento: `CUIT`. Ingresar un CUIT real y válido.
2. Click en **Verificar**.

**Esperado**: toast verde de éxito; Razón Social, Domicilio Fiscal, Provincia Fiscal, Localidad
Fiscal y Condición de IVA quedan cargados. La localidad debe corresponder a la provincia traída
(verifica FR-011: si la provincia no se resolviera primero, la localidad queda vacía).

### M2 — Respeto de lo escrito a mano (US2)

1. Modal nuevo. Escribir a mano una Razón Social inventada (ej. `NO ME PISES SA`).
2. Ingresar un CUIT válido y click en **Verificar**.

**Esperado**: la Razón Social escrita **sobrevive**; el resto de los campos sí se completan.
Repetir eligiendo la Condición de IVA a mano: también debe sobrevivir.

### M3 — Derivación del comprobante por defecto (US3, regla A/C/B)

Sin necesidad de consultar el padrón, cambiar la Condición de IVA a mano y observar el campo
**Tipo de comprobante por defecto**:

| Condición de IVA elegida | Comprobante esperado |
|--------------------------|----------------------|
| Responsable Inscripto | Factura A |
| Monotributista | **Factura C** |
| Consumidor Final | Factura B |
| Exento | Factura B |
| No Categorizado | Factura B |

**Ojo con el caso Monotributista**: es la diferencia deliberada respecto de Cliente, que ahí propone
B. Si Proveedor propone B para Monotributista, la regla quedó copiada de Cliente y está mal
(FR-015/FR-017).

Después elegir un comprobante a mano y cambiar la condición: **no debe pisarse** (FR-016).

### M4 — Reinicio de estado entre modales (FR-010)

1. Hacer M2 (dejar una Razón Social escrita a mano) y **cerrar** el modal sin guardar.
2. Abrir **Nuevo Proveedor** de nuevo, ingresar un CUIT válido y **Verificar**.

**Esperado**: ahora sí se completa la Razón Social desde el padrón. Si quedara vacía, el registro de
campos tocados no se está reiniciando al abrir el modal.

### M5 — Degradación sin certificado (US4) — **el más importante**

Con el certificado fiscal desactivado (o sin ninguno cargado):

1. Ingresar un CUIT válido y click en **Verificar**.

**Esperado**: toast informativo "No se pudo consultar el padrón de ARCA en este momento.", **ningún
campo se modifica**, y el proveedor **se puede guardar igual** completando los datos a mano. Que el
alta no quede bloqueada es el requisito no negociable (FR-005, Constitución III).

### M6 — CUIT inexistente y CUIT inválido

- CUIT con dígito verificador **incorrecto** → mensaje de CUIT inválido, **sin** consulta a ARCA.
- CUIT válido pero inexistente en el padrón → toast "No se encontró el CUIT en el padrón de ARCA.",
  sin modificar campos, y el proveedor se puede guardar igual.

### M7 — Edición de un proveedor existente (aclaración 2026-09-07)

1. Editar un proveedor que ya tenga datos fiscales cargados.
2. Click en **Verificar** sin tocar nada.

**Esperado**: los campos **se sobrescriben** con lo del padrón. Es el comportamiento acordado (misma
semántica que Cliente); no es un defecto. Nada se persiste hasta apretar Guardar.

### M8 — Clicks repetidos (FR-013)

Apretar **Verificar** tres veces seguidas rápido.

**Esperado**: una sola consulta en curso; no se apilan toasts ni requests. Verificable en la pestaña
Network de las devtools.

### M9 — No regresión de Cliente (FR-018, SC-005)

Repetir M1 y M3 en **Base de Datos → Clientes**.

**Esperado**: el autocompletado funciona igual que antes, y en Cliente la regla sigue siendo
**A/B** — Monotributista debe proponer **Factura B**, no C. Si Cliente empezó a proponer C, la regla
de Proveedor se filtró donde no debía.

### M10 — Alta rápida de proveedor desde el formulario de Compra (hallazgo de analyze)

Este modal (`proveedor-modal.js`) tenía el autocompletado escrito pero inactivo, y **se activa solo**
al hacer que el endpoint devuelva `padron`. Hay que verificarlo explícitamente.

1. Ir a **Egresos → Compras → Nueva Compra**.
2. En el selector de Proveedor, usar el alta rápida de proveedor nuevo.
3. Ingresar un CUIT válido y click en **Verificar**.

**Esperado**: se completan los cinco campos fiscales, igual que en M1.

4. Con la Condición de IVA en **Monotributista**, mirar el Tipo de comprobante por defecto.

**Esperado**: **Factura C**. Si aparece Factura B, la corrección de T026a no se aplicó y el modal
quedó con la regla de Cliente — que es el error fiscal que este escenario existe para atrapar.

### M11 — No regresión del alta rápida de cliente

Ir a **Ingresos → Ventas → Nueva Venta** y usar el alta rápida de cliente con un CUIT.

**Esperado**: autocompleta como siempre, y con Monotributista propone **Factura B** (en Cliente esa
regla es la correcta). Confirma que la regla de Proveedor no se filtró a `cliente-modal.js`.

---

## Checklist de aceptación

- [ ] `ProveedorVerificarPadronTest` en verde (6 escenarios)
- [ ] `ClienteVerificarPadronTest` en verde **sin modificarlo** (no-regresión del refactor)
- [ ] Suite completa en verde, incluidos Mercado Libre y Tiendanube
- [ ] M1: los cinco campos fiscales se completan desde el padrón
- [ ] M2: lo escrito a mano no se pierde
- [ ] M3: Monotributista → **Factura C** en Proveedor
- [ ] M4: el estado se reinicia al reabrir el modal
- [ ] M5: sin certificado, el proveedor se guarda igual
- [ ] M6: CUIT inválido no consulta ARCA; CUIT inexistente informa y no bloquea
- [ ] M7: al editar, los campos precargados se sobrescriben (comportamiento acordado)
- [ ] M8: sin consultas superpuestas
- [ ] M9: Cliente sin cambios, y ahí Monotributista sigue dando **Factura B**
- [ ] M10: el alta rápida de proveedor en Compra autocompleta, y ahí Monotributista da **Factura C**
- [ ] M11: el alta rápida de cliente en Venta sigue dando **Factura B** para Monotributista
