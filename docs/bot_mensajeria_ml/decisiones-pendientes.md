# Decisiones pendientes

Preguntas que hay que cerrar con el usuario antes de correr `/speckit-specify` sobre este módulo.
Mientras esta carpeta sea sólo documentación de contexto, quedan abiertas a propósito.

## Alcance de mensajería

- ¿"Mensajes de ML" significa **Preguntas** (pre-venta, públicas, sobre una publicación), **mensajería
  post-venta** (privada, ligada a una orden), o ambas? Son APIs y modelos de datos distintos en ML
  (`/questions` vs `/messages` post-venta). Puede que el negocio sólo reciba/necesite uno de los dos.
- ¿Hace falta el historial completo de conversación por comprador, o alcanza con pregunta→respuesta
  aislada?

## Scope de OAuth

- Confirmar en el DevCenter de ML si la app propia (spec 011) ya tiene habilitado el permiso de
  mensajería/preguntas, o hay que solicitarlo y volver a autorizar la conexión existente.

## Dónde vive en el CRM

- ¿Es una pantalla nueva bajo "Ingresos" (junto a las vinculaciones de ML/Tiendanube ya existentes),
  bajo "Funciones Avanzadas", o un módulo propio en el sidebar? Afecta permisos, navegación y menú.

## LLM a usar

- **Proveedor — decisión abierta, no asumir Anthropic por default.** Hoy el CRM no tiene ningún
  proveedor de LLM integrado en la app (el MCP de Mercado Libre/Claude que existe hoy es de esta
  sesión de desarrollo con Claude Code, no tiene relación con el bot ni con la app en producción — ver
  `README.md`). No hay "camino de menor resistencia" que empuje hacia un proveedor en particular.
  Candidatos con el mismo tipo de encaje (API paga, llamada server-side desde el VPS, SDK oficial,
  requiere API key en `.env` de producción):
  - **Anthropic (Claude)** — Sonnet 5 como default razonable, Haiku 4.5 como alternativa barata (ver
    detalle abajo).
  - **OpenAI (GPT)** — GPT-4o/GPT-4.1 como default razonable, GPT-4o-mini como alternativa barata.
    Igual de apto para el caso de uso: tono conversacional de atención al cliente en español es un
    punto fuerte de esta familia de modelos.
  - Self-hosted no está justificado para este volumen, en ningún proveedor.
  - **Ninguno de los dos es claramente mejor en teoría para este caso** (respuestas cortas de atención
    al comprador en español, con revisión humana antes de enviar). La decisión real debería salir de
    una **prueba empírica antes de implementar la fase 1**: tomar 10-15 mensajes reales de compradores,
    generar la sugerencia con ambos proveedores (ej. Haiku 4.5 vs GPT-4o-mini) y que alguien del
    negocio elija cuál suena mejor en español rioplatense/argentino. Esa prueba pesa más que cualquier
    recomendación teórica.
- **Si se opta por Anthropic**: Claude Sonnet 5 (`claude-sonnet-5`) como default — calidad de
  español/tono suficiente para que la sugerencia salga casi lista, sin pagar el costo de Opus, que
  apunta a razonamiento profundo/trabajo agéntico que este caso no necesita (hay revisión humana en el
  medio). Alternativa más barata: Claude Haiku 4.5 (`claude-haiku-4-5`, ~1/3 del costo de Sonnet) —
  viable justamente porque el humano revisa/edita cada sugerencia antes de enviar.
- ¿Con qué tono/instrucciones se lo "educa"? Hace falta que alguien del negocio provea ejemplos reales
  de respuestas buenas actuales (si las hay) para calibrar el prompt (system prompt), sea cual sea el
  proveedor/modelo elegido.

## Volumen y urgencia real

- ¿Cuántos mensajes por día maneja hoy el negocio en ML? Esto define si vale la pena la complejidad de
  colas/VPS ahora mismo o si un MVP más simple (fase 0, sólo lectura) ya resuelve el dolor principal
  mientras se termina de contratar y migrar el VPS.

## Notificación al frontend

- Polling simple (consistente con el resto del CRM) vs algo push/real-time para avisar "tenés un
  mensaje nuevo" o "la sugerencia ya está lista". Definir expectativa de latencia aceptable.
