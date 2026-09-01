# Checklist de seguridad — spec 094

Esta carga toca 31.518 filas en una base en uso real. El usuario pidió explícitamente
"backups y una garantía absoluta de que no pase nada". Este checklist es esa garantía puesta por
escrito: **ningún ítem se marca de memoria, cada uno se verifica.**

## Antes de escribir una sola fila

- [x] **S-01** Dump completo de la base del VPS, con fecha en el nombre y tamaño verificado.
      No "hice el backup": el archivo existe, pesa lo que tiene que pesar y se puede leer.
- [x] **S-02** El dump se **restauró** en una base local de prueba. Un backup que nunca se probó no
      es un backup.
- [x] **S-03** El comando corrió completo sobre ese clon fresco, con `--escribir`, sin errores.
- [x] **S-04** Sobre el clon: `stock_actual` de los 9.781 productos **idéntico** antes y después.
      Comparación fila por fila, no un total.
- [x] **S-05** Sobre el clon: ninguna publicación de ML ni variante de Tiendanube quedó con
      `stock_pendiente` que no lo estuviera antes.
- [x] **S-06** Sobre el clon: se corrió `--deshacer` y la base volvió al estado exacto anterior.
      Cantidad de movimientos, y `stock_actual` de nuevo idéntico.
- [x] **S-07** El historial del producto 27204 (la alacena) se abrió en el navegador y se lee bien.

## Durante la corrida real

- [x] **S-08** Backup inmediatamente antes, distinto del de S-01.
- [x] **S-09** Dry-run en producción primero, con los números revisados **antes** de escribir.
- [x] **S-10** Los números del dry-run en producción coinciden con los del clon. Si no, se para y se
      averigua por qué antes de seguir.
- [x] **S-11** La corrida real va dentro de una transacción con verificación final.

## Después

- [x] **S-12** `stock_actual` en producción idéntico al de antes de la corrida.
- [x] **S-13** Ninguna publicación quedó pendiente de sincronizar.
- [x] **S-14** El cron de sincronización de stock no envió nada anómalo en la corrida siguiente.
- [x] **S-15** El historial de tres productos al azar se abre y se lee correctamente.

## Lo que invalida la corrida

Cualquiera de estos aborta y revierte, sin discusión:

1. Un solo producto con `stock_actual` distinto.
2. Una sola publicación marcada como pendiente que no lo estaba.
3. Una fecha fuera del rango del año del archivo.
4. Un movimiento cuyo `origen` apunta a una operación de otro año.

## Nota sobre el ambiente

La regla del proyecto es **nunca probar en producción**. Toda la validación funcional va contra el
clon (S-02 a S-07). En producción sólo se corre el dry-run (S-09) y la corrida real ya verificada.


---

## Resultado de la corrida real — 01/09/2026

**Los 15 puntos cumplidos.** Producción quedó con el histórico cargado y el stock intacto.

| Verificación | Resultado |
|---|---|
| Backup previo | `contagram_ANTES_094_20260901_005104.sql.gz`, 10 MB |
| Excel subidos | md5 verificado en origen y destino, los tres |
| Dry-run vs. prueba | 30.712 en ambos, 0 discrepancias de saldos |
| **`stocks` antes y después** | **md5 `99460ea3...` idéntico, 9.177 filas, diff vacío** |
| Publicaciones ML | 3 pendientes, las mismas de antes |
| Variantes Tiendanube | 13 pendientes, las mismas de antes |
| Eventos de auditoría | 0 |
| Movimientos | 1.394 → 32.106 (+30.712) |
| Rango de fechas | 02/01/2024 a 13/08/2026 |
| Productos que cierran exacto | 7.963 de 9.156 (87%) |

Para revertir: `php artisan stock:importar-movimientos-historicos . --deshacer=20260901035814`
