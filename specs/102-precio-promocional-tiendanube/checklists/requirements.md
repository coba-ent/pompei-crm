# Checklist de calidad — spec 102

**Feature**: [spec.md](../spec.md) | **Fecha**: 2026-09-10

## Calidad del contenido

- [x] Sin detalles de implementación en la spec (viven en el plan)
- [x] Centrada en el valor: publicar el precio de oferta desde el CRM
- [x] Legible por alguien no técnico
- [x] Secciones obligatorias completas

## Completitud de requisitos

- [x] Sin marcadores de clarificación pendientes
- [x] Requisitos verificables y sin ambigüedad
- [x] Criterios de éxito medibles contra la cuenta real
- [x] Casos de borde **verificados contra la API**, no supuestos
- [x] Alcance acotado
- [x] Supuestos verificados ejecutando, no leyendo

## Listo para implementar

- [x] Cada requisito tiene su tarea
- [x] Los requisitos de seguridad (FR-003, FR-004) tienen test propio
- [x] Sin filtraciones de implementación

## Notas de la validación

**La documentación oficial no alcanzaba.** Dice que `promotional_price` existe y es escribible, pero
no dice qué pasa al mandarlo mayor al precio, ni cómo se borra. Los dos casos se probaron contra la
cuenta real, sobre una variante **no vinculada**, restaurando su estado al terminar.

De ahí salieron los dos hallazgos que definen la spec:

1. **Tiendanube no valida nada** — acepta un promocional más caro que el precio de lista y lo
   publica. FR-004 existe porque la API no es una red.
2. **Omitir el campo preserva la promoción existente** — eso convirtió la duda del usuario
   (*"borrar cosas desde acá a Tiendanube sería medio peligroso"*) en una garantía estructural: el
   CRM no borra porque el campo no viaja, no porque alguien se acuerde de no borrarlo.

**Sobre la configuración**: la primera versión de la spec hardcodeaba la lista 5. El usuario lo
corrigió señalando que Mercado Libre ya resuelve esto con una columna configurable
(`lista_precio_id_premium`) y que había que calcar ese patrón. Quedó como FR-000.

**Sobre el riesgo**: publicar un precio promocional es publicar el precio al que se vende, y esta
integración **no tiene el corte de bajadas** de Mercado Libre. Las 85 variantes de hoy tienen el
promocional menor que el normal, así que ninguna dispararía el rechazo — pero eso es el estado de
hoy, no una garantía a futuro.
