# Checklist: no se puede publicar barato por accidente

**Propósito**: verificar que la spec cierra **todas** las vías por las que un precio puede llegar
bajo a Mercado Libre. No valida redacción — valida que no quede un agujero.
**Creado**: 2026-08-26
**Feature**: [spec.md](../spec.md)

Cada ítem es una pregunta de la forma "¿por acá se puede colar?". Un ítem sin marcar es una vía
abierta.

## Caminos de envío — ninguno puede saltear el corte

- [x] El cambio de precio desde el modal de Producto pasa por el corte — CHK001
- [x] La importación masiva de precios pasa por el corte — CHK002
- [x] El reintento de pendientes ("Sincronizar precios ahora") pasa por el corte — CHK003
- [x] La republicación por cambio de lista configurada pasa por el corte — CHK004
- [x] Está definido que el corte vive en el único punto que hace el envío, y no replicado en cada
      llamador — CHK005 *(Decisión 2; replicar la regla fue la causa raíz del 25/08)*
- [x] Un camino de envío que se agregue en el futuro queda cubierto sin acordarse de nada — CHK006

## Casos límite del corte

- [x] El borde exacto del umbral está definido (igual pasa, mayor retiene) — CHK007
- [x] Precio propuesto $0 o negativo se retiene siempre — CHK008
- [x] Sin precio publicado conocido se retiene, en vez de publicar a ciegas — CHK009
- [x] Falla al consultar Mercado Libre ⇒ no se publica — CHK010
- [x] Umbral 0% y umbral 100% están definidos y no rompen — CHK011
- [x] Umbral 100% **sigue** reteniendo precio inválido y sin referencia — CHK012
- [x] Está dicho que las subidas nunca se retienen, y por qué — CHK013

## Que el corte no se vuelva inútil

- [x] Una retención no frena el resto de la corrida — CHK014
- [x] Dos propuestas seguidas no acumulan retenciones — CHK015
- [x] Hay un plan de activación que evita retener las 270 el primer día — CHK016 *(Decisión 5)*
- [x] Las retenidas son visibles sin ir a buscarlas — CHK017
- [x] Aprobar envía el precio vigente, no uno congelado y viejo — CHK018

## Detección

- [x] El chequeo compara cada publicación contra la lista que le toca por tipo — CHK019
- [x] Está dicho que comparar todo contra la lista general genera 30 falsos positivos — CHK020
- [x] Las retenidas no se cuentan como desfasajes — CHK021
- [x] Las publicaciones no consultables no se cuentan como coincidentes — CHK022
- [x] El panel muestra cuándo corrió por última vez — CHK023
- [x] El chequeo no corrige por su cuenta — CHK024

## Las dos ventanas silenciosas

- [x] Premium sin precio en su lista queda advertida — CHK025
- [x] Vínculo sin tipo conocido no recibe precio — CHK026
- [x] Al conocerse el tipo, el pendiente se resuelve con la lista correcta — CHK027

## Trazabilidad

- [x] Cada retención registra contra qué precio se comparó — CHK028
- [x] Cada retención guarda el umbral vigente al momento de retener — CHK029 *(si cambia el umbral,
      la retención vieja tiene que seguir explicándose sola)*
- [x] Aprobar y rechazar quedan con usuario y momento — CHK030
- [x] Nada sensible queda en el historial — CHK031

## Lo que NO cubre esta spec — reconocido, no olvidado

- [x] Tiendanube publica cualquier precio sin validar: fuera de alcance, documentado — CHK032
- [x] Una subida errónea (×1000) pasa el corte: aceptado y justificado — CHK033 *(Decisión 6)*
- [x] `precio_publicado` puede tener hasta 24 horas: aceptado, el chequeo lo refresca — CHK034
- [x] La previa del cambio de lista usa datos de hasta 24 horas: aceptado, es orientativa — CHK035
      *(Decisión 7)*

## Resultado

**35 de 35.** No queda ninguna vía de envío sin cubrir dentro del alcance.

Las cuatro exclusiones (CHK032–CHK035) son decisiones explícitas con su justificación, no huecos.
**La que más conviene tener presente es CHK032**: Tiendanube sigue expuesta a publicar cualquier
precio. No causó ningún incidente todavía y usa una sola lista, pero la exposición es real y merece
su propia spec.
