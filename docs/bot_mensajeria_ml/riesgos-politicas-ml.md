# Riesgos y políticas de Mercado Libre a respetar

A tener en cuenta en el diseño para no exponer la cuenta real del negocio a sanciones o fricción con
compradores. Esto hay que verificarlo contra la documentación oficial vigente de ML al momento de
especificar (las políticas cambian), pero como lineamiento de diseño:

- **Mercado Libre mide tiempo de respuesta** al comprador como métrica de reputación del vendedor.
  Un flujo con aprobación humana introduce demora respecto a un bot 100% automático — hay que medir
  si esa demora (mensaje entra → sugerencia generada → humano revisa y aprueba) es aceptable dentro de
  los tiempos que ML espera, y diseñar la vista para que sea rápido de revisar/aprobar, no un cuello
  de botella.
- **Respuestas que se perciban como bot/spam** pueden generar reclamos del comprador o fricción. Por
  eso la decisión de arrancar con aprobación humana (no envío automático) — reduce el riesgo de que
  salga una respuesta robótica o fuera de contexto sin que nadie la vea antes.
- **Contenido prohibido en mensajes** (ML prohíbe, por ejemplo, compartir datos de contacto externos,
  links a otras plataformas de venta, etc. dentro de mensajería). El LLM tiene que estar instruido para
  no generar ese tipo de contenido, y probablemente convenga una validación adicional (no sólo
  confiar en el prompt) antes de dejar enviar.
- **Cuenta real del cliente** (Pompei Sanitarios) — ver memoria `tiendanube-cuenta-real-cuidado-escrituras`
  (aplica el mismo cuidado, aunque sea Mercado Libre y no Tiendanube): nunca probar el envío real de
  mensajes a compradores reales sin que el usuario lo autorice puntualmente. Cualquier prueba de
  integración de envío debe hacerse contra un mensaje/comprador de prueba, no contra tráfico real, salvo
  que el usuario decida explícitamente lo contrario.
