# Quickstart — Validar teléfono y sitio web en los comprobantes

**Feature**: 105-telefono-web-pdf | **Date**: 2026-09-18

Cómo comprobar que la feature funciona de punta a punta. Detalles de las columnas en
[data-model.md](./data-model.md); reglas de renderizado en
[contracts/encabezado-emisor.md](./contracts/encabezado-emisor.md).

## Prerequisitos

- XAMPP con MySQL corriendo, base `contagram`.
- Migraciones al día: `php artisan migrate`.
- Un usuario con acceso a Configuración & Ajustes (ver `CREDENCIALES_ACCESO.txt`).

> **No validar en producción.** El VPS está en uso real; la validación funcional va en local. Después
> de deployar, en producción sólo se hace verificación de lectura.

## Validación automática

```bash
php artisan test --filter="MiPerfil|EncabezadoEmisor"
```

Esperado: verde. Cubre que los campos se persisten, que se imprimen cuando están cargados y que **no**
se imprime nada cuando están vacíos.

Para confirmar que no hubo regresión en los comprobantes:

```bash
php artisan test --filter="Pdf|Presupuesto|Recibo|Remito|NotaCredito"
```

Esperado: mismas fallas que antes del cambio y ninguna nueva. (La suite del proyecto tiene fallas
preexistentes ajenas a esta feature: comparar contra la baseline, no asumir que todo tiene que estar
en verde.)

## Validación manual

### 1. Cargar los datos

1. Entrar a **Configuración & Ajustes → Empresa**.
2. Botón **Editar**.
3. Completar **Teléfono** (ej. `11 5555-5555 / WhatsApp 11 4444-4444`) y **Página web**
   (ej. `www.pompeisanitarios.com.ar`).
4. Guardar.

Esperado: toast de éxito, **sin recarga de página**, y los dos valores visibles en la ficha.

### 2. Verlos en los cinco comprobantes

Para cada uno: abrir el listado, usar la acción de imprimir/PDF, y mirar el encabezado en el modal de
PDF.

| Comprobante | Dónde |
|---|---|
| Venta | Ingresos → Ventas → imprimir |
| Presupuesto | Ingresos → Presupuestos → imprimir |
| Nota de Crédito/Débito | Ingresos → Notas de Crédito/Débito → imprimir |
| Remito | Ingresos → Remitos → imprimir |
| Recibo | Ventas → cobranza de una venta → recibo |

Esperado en los cinco: bajo la condición de IVA aparecen el teléfono y la página web, **con el mismo
formato y en el mismo orden**. El PDF se abre en el modal compartido, no en una pestaña nueva.

### 3. El caso que suele romperse: campos vacíos

1. Volver a **Empresa → Editar** y **borrar** el teléfono y la página web. Guardar.
2. Imprimir cualquier comprobante.

Esperado: el encabezado se ve **exactamente como antes de la feature**. Sin `Tel:` suelto, sin renglón
en blanco, sin corrimiento del logo. Este es el escenario 4 de la Historia 1 y el más fácil de pasar
por alto.

### 4. Un solo campo cargado

Cargar **sólo** el teléfono, dejar la web vacía, imprimir.

Esperado: aparece el teléfono, no aparece nada de la web.

### 5. Valores largos

Cargar un teléfono con dos números y aclaraciones, y una URL larga. Imprimir.

Esperado: el texto corta de línea dentro de su bloque. El logo no se desplaza y los datos fiscales no
se superponen.

## Verificación de que nada fiscal cambió (SC-004)

Sobre una venta con CAE ya emitido, comparar el PDF antes y después del cambio: importes, tipo de
comprobante, numeración, CAE, vencimiento del CAE y código QR deben ser idénticos. Lo único distinto
debe ser el bloque de contacto del encabezado.

## Post-deploy en producción (sólo lectura)

1. `php artisan migrate:status | grep -i pending` → la migración de esta feature aplicada, nada más
   pendiente.
2. Abrir un comprobante ya existente y confirmar que sigue generándose.
3. **No** cargar datos de prueba ni emitir comprobantes de prueba en producción.
