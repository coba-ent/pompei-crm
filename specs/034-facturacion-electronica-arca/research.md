# Research: Facturación Electrónica (ARCA/AFIP)

## 1. Autenticación WSAA (Web Service de Autenticación y Autorización)

- **Decision**: Implementar `ClienteWsaa` que genera un TRA (Ticket de Requerimiento de Acceso, XML
  con `uniqueId`, `generationTime`, `expirationTime`, `service=wsfe`), lo firma en CMS/PKCS#7 con
  `openssl_pkcs7_sign` usando el certificado `.crt`/`.key` del negocio, lo envía a WSAA
  (`wsaahomo.afip.gov.ar` en homologación / `wsaa.afip.gov.ar` en producción) y cachea el Ticket de
  Acceso (token + sign) resultante hasta su expiración (~12hs), usando el cache de Laravel
  (`Cache::remember`) namespaced por servicio (`wsfe`).
- **Rationale**: Es el mecanismo estándar y único soportado por ARCA para autenticar contra WSFEv1;
  no requiere paquetes de terceros — PHP nativo (`openssl_pkcs7_sign`, `SoapClient`) alcanza. Evita
  dependencias externas de mantenimiento incierto para algo tan crítico (fiscal).
- **Alternatives considered**: Paquetes de terceros (`afipsdk/afip.php`) — rechazados para no atar
  la corrección fiscal del negocio al mantenimiento de un paquete de la comunidad; se puede
  reevaluar si el desarrollo nativo demanda mucho más esfuerzo del previsto, pero el plan arranca
  con la implementación nativa dado el Principio III de la constitución (corrección fiscal
  innegociable — preferible controlar cada pieza).

## 2. Emisión de comprobantes vía WSFEv1

- **Decision**: `ClienteWsfev1` envuelve el `SoapClient` contra el WSDL de WSFEv1
  (`wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL` en homologación), exponiendo los métodos
  necesarios: `FECAESolicitar` (emitir con CAE), `FECompUltimoAutorizado` (para saber el próximo
  número correlativo antes de armar el request), `FECompConsultar` (para reconciliar tras un
  timeout, FR-011).
- **Rationale**: WSFEv1 es el webservice vigente y recomendado por ARCA para Responsables
  Inscriptos/Monotributistas con estos volúmenes; no requiere factura electrónica de exportación ni
  otros WS más complejos (fuera de alcance, negocio no exporta).
- **Alternatives considered**: WSFEX (exportación) y WSMTXCA (con detalle de items) — descartados,
  no aplican al alcance actual (sin operaciones de exportación; el detalle de ítems para IVA por
  alícuota que exige WSMTXCA no es requisito declarado del negocio hoy).

## 3. Resolución del gap "sin informe con capturas"

- **Decision**: Documentar la brecha en spec Assumptions (ya hecho) y basar la estructura de
  pantalla nueva (Configuración de Facturación Electrónica) en el patrón visual ya usado por
  pantallas de configuración existentes del CRM (ej. Configuración de Mercado Libre/Tiendanube,
  specs 011/019), no en una estructura inventada desde cero. Se marca como pendiente de contrastar
  contra un futuro relevamiento con capturas si Contagram real tiene una pantalla equivalente.
- **Rationale**: Consistente con la regla de oro del proyecto — cuando no hay informe con capturas,
  se declara la brecha explícitamente en vez de simular fidelidad que no existe.

## 4. Almacenamiento seguro del certificado

- **Decision**: El par `.crt`/`.key` se guarda en `storage/app/arca/` (fuera de `public/`, fuera de
  control de versiones — `storage/app` ya está gitignored), con la ruta persistida (no el contenido)
  en `certificados_fiscales.ruta_certificado`/`ruta_clave_privada`. El contenido del archivo se
  cifra en disco reutilizando el driver de filesystem de Laravel con un wrapper que cifra/descifra
  con `Crypt::encryptString` antes de escribir/leer.
- **Rationale**: Igual patrón de "no secretos en DB en texto plano" que ya usan
  `MercadoLibreConfiguracion`/`TiendanubeConexionRest` (columnas `encrypted`), adaptado a archivos
  porque un certificado no es un string corto sino un blob binario/PEM.
- **Alternatives considered**: Guardar el certificado cifrado directamente en una columna
  `LONGBLOB` de MySQL — descartado por preferencia de mantener archivos grandes fuera de la DB
  (backups más livianos, patrón ya usado en el proyecto para adjuntos).

## 5. Ambiente de desarrollo sin certificado de producción

- **Decision**: Desarrollo y tests de integración corren contra el ambiente de **homologación**
  ARCA con un certificado de prueba generado por el propio desarrollador en el portal de
  homologación de AFIP/ARCA (no requiere el certificado real del negocio). El campo `ambiente` en
  `certificados_fiscales` (`homologacion`/`produccion`) determina qué endpoints de WSAA/WSFEv1 se
  usan.
- **Rationale**: Permite construir y probar el módulo completo sin bloquear por la ausencia del
  certificado real (Assumptions de la spec), sin comprometer al negocio real hasta que decidan pasar
  a producción.
