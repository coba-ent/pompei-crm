# Quickstart: validar el patrón "Crear X" + lápiz inline en Presupuestos

## Prerrequisitos

- App corriendo local (XAMPP + `php artisan serve` o el flujo habitual del proyecto) con al menos un usuario de prueba (ver `CREDENCIALES_ACCESO.txt`).
- Al menos un Cliente, una Categoría de venta y un Vendedor ya cargados (para probar el caso "editar", además de "crear").

## Escenario 1 — Crear Cliente sin salir del presupuesto (User Story 1, SC-001)

1. Ir a Ingresos → Presupuestos → Nuevo Presupuesto.
2. Abrir el dropdown de Cliente sin escribir nada.
3. **Esperado**: la primera fila es "Crear Cliente" con ícono "+", resaltada, por encima de los clientes existentes.
4. Click en "Crear Cliente" → se abre un modal con campo Nombre.
5. Cargar un nombre nuevo → Crear.
6. **Esperado**: el modal se cierra, aparece un toast de éxito, el select de Cliente queda con el cliente recién creado seleccionado, sin recargar la página y sin perder el resto de los campos del formulario ya cargados (fecha, etc.).
7. Repetir pasos 2-6 para Categoría de Venta ("Crear Categoría de ventas") y Vendedor ("Crear Vendedor").

## Escenario 2 — Editar un ítem sin seleccionarlo (User Story 2, SC-002)

1. En el mismo formulario, seleccionar cualquier Categoría de Venta en el select (ítem A).
2. Volver a abrir el dropdown de Categoría de Venta.
3. Ubicar OTRO ítem de la lista (ítem B, distinto del seleccionado) y hacer click en su ícono de lápiz.
4. **Esperado**: se abre el modal de edición para el ítem B (su nombre precargado), sin cerrar el formulario ni cambiar la selección actual (sigue siendo el ítem A).
5. Cambiar el nombre y confirmar.
6. **Esperado**: toast de éxito; al reabrir el dropdown, el ítem B aparece con el nombre nuevo; el select del formulario sigue mostrando el ítem A como seleccionado.
7. Repetir para Vendedor y para Cliente.

## Escenario 3 — Regla de diseño (sin recargas, sin alerts nativos)

1. Durante los escenarios 1 y 2, confirmar en DevTools (Network) que ninguna acción de alta/edición dispara una navegación de página completa (todas las requests son XHR/fetch).
2. Confirmar que los mensajes de éxito/error se muestran como toast (Toastr), nunca como `alert()` nativo ni mensaje flash con recarga.

## Verificación cruzada con la regla de oro (fidelidad estructural)

- Comparar visualmente el dropdown abierto contra las capturas `docs/capturas/saldos/WhatsApp Image 2026-07-30 at 12.16.07/12.16.30/12.16.49 PM.jpeg`: posición de "Crear X" (siempre primera fila), ícono "+", resaltado azul, ícono de lápiz a la derecha de cada fila existente.
- Comparar el modal de alta contra `...12.17.17 PM.jpeg` (modal "Nuevo Vendedor": título, campo Nombre, botones Cancelar/Crear) — el modal rápido de Cliente debe seguir la misma estructura.
