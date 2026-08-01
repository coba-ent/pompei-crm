# Quickstart: validar "Transformar todas en Venta"

Prerrequisitos: entorno local levantado (XAMPP + `php artisan serve` o equivalente), usuario de
prueba logueado con acceso a Ingresos, conexión Tiendanube y/o MercadoLibre configurada (puede ser
en modo de datos de prueba), función avanzada correspondiente activa y modo solo lectura desactivado.

## Escenario 1 — Lote 100% exitoso

1. Dejar 2-3 órdenes de Tiendanube en estado "Lista para convertir" (vía seed/factory o simulando
   una sincronización), con cliente, producto vinculado y cuenta de tesorería válidos.
2. Ir a Ingresos > Tiendanube.
3. Apretar "Transformar todas en Venta".
4. **Esperado**: toast de éxito ("N de N órdenes convertidas"), las órdenes pasan a "Convertida" en
   la tabla (recarga vía DataTables AJAX, sin recargar la página), cada una con su Venta asociada y
   `convertida_por` = usuario logueado. No se abre modal de detalle (no hay fallidas) o se abre con
   la tabla de detalle vacía, según la decisión de UI tomada en implementación.

## Escenario 2 — Lote con fallidas

1. Repetir el punto 1, pero dejar una de las órdenes con un problema conocido (ej. cliente ambiguo:
   dos `Cliente` con el mismo email que el de la orden).
2. Apretar "Transformar todas en Venta".
3. **Esperado**: se abre el modal de resultado mostrando el resumen (total/convertidas/fallidas) y
   una fila en la tabla de detalle con el número de esa orden, el motivo ("Más de un Cliente con el
   mismo email") y su explicación. El resto de las órdenes del lote sí quedan convertidas.

## Escenario 3 — Guardrail bloqueando el batch

1. Desactivar la función avanzada "Tiendanube" (Configuración > Funciones Avanzadas) o activar el
   modo solo lectura de la conexión.
2. Apretar "Transformar todas en Venta".
3. **Esperado**: toast de error con el mensaje del guardrail; ninguna orden cambia de estado.

## Escenario 4 — Sin órdenes pendientes

1. Asegurarse de que no haya ninguna orden en estado "Lista para convertir".
2. Apretar "Transformar todas en Venta".
3. **Esperado**: toast informando "0 de 0 órdenes convertidas" (o mensaje equivalente), sin error.

## Escenario 5 — Equivalente en MercadoLibre

Repetir los escenarios 1-4 en Ingresos > MercadoLibre contra `ml_ordenes`, verificando el mismo
comportamiento de forma independiente de Tiendanube.

## Verificación de no-duplicación (SC-003)

1. Con una orden en estado "Lista" y la creación automática activa, disparar en paralelo (o en
   rápida sucesión) una sincronización automática y el botón manual.
2. **Esperado**: la orden termina con una única `Venta` asociada; el intento que llegó segundo
   registra la falla ya prevista ("ya tiene una Venta asociada" o equivalente) sin generar un
   registro duplicado ni un error visible confuso para el usuario.
