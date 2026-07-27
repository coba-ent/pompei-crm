# Research: Base de Datos — Clientes

Fase 0 del plan. Resuelve las decisiones técnicas abiertas antes del diseño.

## 1. Validación de CUIT (formato + dígito verificador)

- **Decision**: Validar CUIT con una regla propia `App\Rules\CuitValido`: 11 dígitos, prefijo válido
  (20/23/24/27/30/33/34…), y dígito verificador calculado con el algoritmo módulo 11 estándar de
  ARCA (multiplicadores `5 4 3 2 7 6 5 4 3 2` sobre los primeros 10 dígitos).
- **Rationale**: Es una validación puramente local, barata y determinística; no requiere red. Evita
  persistir CUIT inválidos (SC-006) sin depender de ARCA.
- **Alternatives considered**: (a) Sólo validar longitud/numérico — rechazado: dejaría pasar CUIT
  con DV incorrecto. (b) Validar únicamente contra ARCA — rechazado: acopla una validación básica a
  un servicio externo que puede estar caído.

## 2. Verificación de datos fiscales contra ARCA ("botón Verificar")

- **Decision**: Definir un contrato `App\Services\Arca\VerificadorCuit` con un método
  `verificar(string $cuit): ?DatosFiscales`. Proveer una implementación provisoria
  `VerificadorCuitStub` (registrada en el container) que por ahora sólo valida formato y devuelve
  `null` (sin autocompletar), dejando lista la costura para enchufar la integración real
  (WSAA + padrón A5) cuando se construya el módulo de Facturación.
- **Rationale**: Alinea con la constitución (reutilizar la capa fiscal del módulo Facturación) sin
  bloquear esta feature en una integración SOAP compleja. La resiliencia (FR-008) queda garantizada:
  si el verificador falla o devuelve `null`, el alta/edición del cliente continúa con el CUIT
  ingresado a mano.
- **Alternatives considered**: (a) Implementar ya la llamada real a ARCA — rechazado: excede el
  alcance de Clientes y depende de certificados/punto de venta aún no configurados. (b) No prever el
  botón — rechazado: la spec (FR-007) y el doc de dominio §5.1 lo requieren; conviene dejar el
  contrato desde ahora.

## 3. "Apto para facturar"

- **Decision**: Regla de negocio derivada (no columna persistida): un cliente es apto para facturar
  si tiene `condicion_iva_id` cargada. Cuando la condición de IVA exige CUIT (Responsable Inscripto,
  Monotributista), además requiere CUIT válido presente. Se expone como método del modelo
  (`Cliente::esAptoParaFacturar(): bool`) y se usa en validaciones y en la UI.
- **Rationale**: Evita duplicar estado; la aptitud siempre refleja los datos actuales. Cumple
  Principio III (no habilitar facturación sin condición de IVA) y FR-011/FR-012.
- **Alternatives considered**: Columna booleana `apto_facturar` — rechazado: se desincronizaría con
  los datos reales y sería fuente de bugs fiscales.

## 4. Unicidad de CUIT

- **Decision**: Índice único parcial sobre `clientes.cuit` que aplica sólo cuando el CUIT no es NULL.
  En MySQL/MariaDB, varias filas con `cuit = NULL` no colisionan bajo un índice único, por lo que un
  índice único simple sobre una columna nullable ya permite múltiples clientes sin CUIT y bloquea
  duplicados de CUIT presente. Se refuerza con `unique` (ignorando nulos) en el FormRequest.
- **Rationale**: Cumple FR-016 (único si presente; varios sin CUIT permitidos) con garantía a nivel
  de base de datos, no sólo de aplicación.
- **Alternatives considered**: Unicidad sólo en la capa de aplicación — rechazado: condiciones de
  carrera podrían insertar duplicados. Guardar CUIT vacío como `''` — rechazado: complica la
  semántica de "sin CUIT"; se usa NULL.

## 5. "Operación asociada" (regla de no-eliminación)

- **Decision**: Implementar la comprobación mediante un método extensible
  `Cliente::tieneOperaciones(): bool` que hoy devuelve `false` (aún no existen presupuestos, ventas,
  cobros ni comprobantes) y se irá completando a medida que esos módulos agreguen sus relaciones. La
  eliminación física pasa por este chequeo; si es `true`, se rechaza y sólo se permite inactivar.
- **Rationale**: Permite entregar la regla ahora (FR-022/FR-023) sin acoplarse a tablas inexistentes;
  cada módulo futuro suma su verificación en un único lugar. Consistente con la nota de la spec.
- **Alternatives considered**: Posponer la regla hasta que existan operaciones — rechazado: dejaría
  la baja lógica sin la salvaguarda y obligaría a re-tocar el flujo después.

## 6. Baja lógica vs SoftDeletes de Laravel

- **Decision**: Usar una columna booleana `activo` (default true) para la baja lógica del cliente, NO
  `SoftDeletes`. La eliminación física (para clientes sin operaciones) es un `delete()` real.
- **Rationale**: El doc de dominio y la constitución reservan `SoftDeletes` para documentos fiscales
  (ventas, compras, comprobantes). Para clientes, el patrón de Contagram es "activo/inactivo"
  (mostrar/ocultar del buscador), que mapea naturalmente a un booleano. Un cliente sin operaciones sí
  puede borrarse de verdad (FR-023).
- **Alternatives considered**: `SoftDeletes` en clientes — rechazado: confunde "inactivo" (visible en
  filtro inactivos, reactivable) con "borrado" (oculto), y complica FR-023.

## 7. Campos personalizados

- **Decision**: Persistir en una columna `campos_personalizados` de tipo JSON en `clientes` (como
  define el modelo de datos), con casting a array en Eloquent. En esta feature se soporta el
  almacenamiento y la edición de pares clave/valor a nivel de cliente; la definición global de campos
  (catálogo de campos disponibles reutilizable) se difiere.
- **Rationale**: Coincide con `docs/modelo_datos.md` (`campos_personalizados json nullable`). JSON es
  suficiente para el volumen y flexibilidad requeridos, sin crear tablas EAV.
- **Alternatives considered**: Tabla EAV `cliente_campos` — rechazado por ahora: más compleja de lo
  necesario para un único negocio; se puede migrar a EAV más adelante si hace falta reporting sobre
  esos campos.

## 8. Tablas de soporte (condiciones_iva, categorias, listas_precio)

- **Decision**: Crear las tres tablas en su forma mínima (según modelo de datos) para satisfacer las
  FK y los selects del formulario de cliente. `condiciones_iva` se precarga por seeder con el catálogo
  fijo (Responsable Inscripto, Monotributista, Consumidor Final, Exento, No Categorizado + código
  ARCA). `categorias` (tipo=venta) y `listas_precio` se crean vacías; su ABM propio es feature aparte.
- **Rationale**: Desbloquea Clientes sin construir módulos completos todavía. Consistente con el
  modelo de datos ya documentado.
- **Alternatives considered**: Hardcodear condiciones de IVA como enum — rechazado: el modelo de datos
  define `condiciones_iva` como tabla lookup con `codigo_afip`, necesario para el mapeo fiscal futuro.

## Puntos que quedan fuera de esta feature (confirmado)

- Importación masiva por Excel (feature Importar Datos).
- ABM propio de Proveedores, Categorías y Listas de Precio.
- Integración SOAP real con ARCA para verificación de CUIT (se deja el contrato/stub).
