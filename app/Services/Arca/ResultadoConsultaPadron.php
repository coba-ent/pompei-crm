<?php

namespace App\Services\Arca;

use App\Models\CondicionIva;

/**
 * Resultado transitorio de una consulta a ws_sr_padron_a13 (data-model.md): no
 * se persiste como entidad propia, sólo se usa para completar/autocompletar un
 * Cliente en los dos puntos de integración de spec 037.
 */
class ResultadoConsultaPadron
{
    /** Mapeo entre el texto de condición de IVA que devuelve ARCA y `condiciones_iva.nombre` (research.md R6). */
    private const MAPEO_CONDICION_IVA_CRM = [
        'IVA RESPONSABLE INSCRIPTO' => 'Responsable Inscripto',
        'RESPONSABLE INSCRIPTO' => 'Responsable Inscripto',
        'IVA SUJETO EXENTO' => 'Exento',
        'EXENTO' => 'Exento',
        'MONOTRIBUTISTA' => 'Monotributista',
        'MONOTRIBUTO' => 'Monotributista',
        'CONSUMIDOR FINAL' => 'Consumidor Final',
        'NO CATEGORIZADO' => 'No Categorizado',
    ];

    /**
     * Mapeo entre `descripcionProvincia` del catálogo oficial de ws_sr_padron_a13 (sin
     * tildes, sin "de"/conectores) y `provincias.nombre` del CRM: el modal matchea la
     * provincia devuelta por ARCA por texto exacto normalizado (case/acentos-insensitive)
     * contra las <option> del select, y algunas descripciones de ARCA difieren del nombre
     * completo del catálogo (ej. "CIUDAD AUTONOMA BUENOS AIRES" sin "DE"), lo que rompía el
     * autocompletado de Provincia/Localidad en el modal de Cliente para esos casos.
     */
    private const MAPEO_PROVINCIA_ARCA = [
        'CIUDAD AUTONOMA BUENOS AIRES' => 'Ciudad Autónoma de Buenos Aires',
        'CABA' => 'Ciudad Autónoma de Buenos Aires',
        'TIERRA DEL FUEGO' => 'Tierra del Fuego, Antártida e Islas del Atlántico Sur',
    ];

    public function __construct(
        public readonly string $cuit,
        public readonly bool $encontrado,
        public readonly ?string $razonSocial = null,
        public readonly ?string $domicilioFiscal = null,
        public readonly ?string $localidadFiscal = null,
        public readonly ?string $provinciaFiscal = null,
        public readonly ?string $condicionIvaRaw = null,
        public readonly ?int $condicionIvaId = null,
        public readonly ?bool $activo = null,
    ) {}

    public static function noEncontrado(string $cuit): self
    {
        return new self(cuit: $cuit, encontrado: false);
    }

    /**
     * Fusiona el resultado ya construido desde `ws_sr_padron_a13` con la respuesta
     * (best effort, puede ser `null`) de `ws_sr_constancia_inscripcion`, aplicando
     * la regla de derivación de condición de IVA de research.md R4. No toca
     * `razonSocial`/`domicilioFiscal`/`localidadFiscal`/`activo` (siguen viniendo
     * exclusivamente de A13, data-model.md).
     */
    public static function conCondicionIva(self $resultado, ?object $respuestaConstancia): self
    {
        $datos = $respuestaConstancia->personaReturn ?? $respuestaConstancia ?? null;
        $datosGenerales = $datos->datosGenerales ?? null;

        if (! $datosGenerales) {
            return $resultado;
        }

        $condicionRaw = self::derivarCondicionIva($datos, $resultado->activo);

        return new self(
            cuit: $resultado->cuit,
            encontrado: $resultado->encontrado,
            razonSocial: $resultado->razonSocial,
            domicilioFiscal: $resultado->domicilioFiscal,
            localidadFiscal: $resultado->localidadFiscal,
            provinciaFiscal: $resultado->provinciaFiscal,
            condicionIvaRaw: $condicionRaw,
            condicionIvaId: $condicionRaw ? CondicionIva::where('nombre', $condicionRaw)->value('id') : null,
            activo: $resultado->activo,
        );
    }

    /** Regla de derivación de research.md R4, a partir de `datosRegimenGeneral`/`datosMonotributo` de la constancia. */
    private static function derivarCondicionIva(object $datos, ?bool $activoSegunPadron): ?string
    {
        if (! empty($datos->datosMonotributo ?? null)) {
            return 'Monotributista';
        }

        $impuestos = $datos->datosRegimenGeneral->impuesto ?? [];
        $impuestos = is_array($impuestos) ? $impuestos : [$impuestos];

        $impuestoIva = null;
        foreach ($impuestos as $impuesto) {
            if (strtoupper((string) ($impuesto->descripcionImpuesto ?? '')) === 'IVA') {
                $impuestoIva = $impuesto;
                break;
            }
        }

        if (! $impuestoIva) {
            return null;
        }

        if (strtoupper((string) ($impuestoIva->estadoImpuesto ?? '')) === 'AC') {
            return 'Responsable Inscripto';
        }

        return $activoSegunPadron === true ? 'Exento' : null;
    }

    /** Parsea la respuesta cruda de `ClientePadron::consultarConstancia()` y aplica el mapeo de condición de IVA. */
    public static function desdeRespuesta(string $cuit, object $respuesta): self
    {
        $datos = $respuesta->personaReturn ?? $respuesta;
        $persona = $datos->persona ?? null;

        if (! $persona) {
            return self::noEncontrado($cuit);
        }

        $domicilioFiscal = self::extraerDomicilioFiscal($persona);
        $condicionRaw = self::extraerCondicionIva($persona);

        return new self(
            cuit: $cuit,
            encontrado: true,
            razonSocial: $persona->razonSocial ?? trim(($persona->nombre ?? '').' '.($persona->apellido ?? '')) ?: null,
            domicilioFiscal: $domicilioFiscal->direccion ?? null,
            // Provincia y localidad son datos distintos (selects linkeados en el modal, docs §2.1) —
            // nunca conflatearlos: `localidad` es la ciudad/partido, `descripcionProvincia` la provincia.
            localidadFiscal: $domicilioFiscal->localidad ?? null,
            provinciaFiscal: self::mapearProvincia($domicilioFiscal->descripcionProvincia ?? null),
            condicionIvaRaw: $condicionRaw,
            condicionIvaId: self::mapearCondicionIva($condicionRaw),
            activo: isset($persona->estadoClave) ? strtoupper((string) $persona->estadoClave) === 'ACTIVO' : null,
        );
    }

    /** El padrón A13 devuelve `domicilio` como array de domicilios (FISCAL, LEGAL/REAL, etc.), no un único campo. */
    private static function extraerDomicilioFiscal(object $persona): object
    {
        $domicilios = $persona->domicilio ?? [];
        $domicilios = is_array($domicilios) ? $domicilios : [$domicilios];

        foreach ($domicilios as $domicilio) {
            if (strtoupper((string) ($domicilio->tipoDomicilio ?? '')) === 'FISCAL') {
                return $domicilio;
            }
        }

        return $domicilios[0] ?? (object) [];
    }

    private static function extraerCondicionIva(object $persona): ?string
    {
        $impuestos = $persona->datosRegimenGeneral->impuesto ?? $persona->datosMonotributo ?? null;

        if (is_array($impuestos)) {
            foreach ($impuestos as $impuesto) {
                if (! empty($impuesto->descripcionImpuesto)) {
                    return (string) $impuesto->descripcionImpuesto;
                }
            }
        }

        return $persona->datosMonotributo->categoria ?? null ? 'MONOTRIBUTO' : null;
    }

    private static function mapearCondicionIva(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $nombre = self::MAPEO_CONDICION_IVA_CRM[strtoupper(trim($raw))] ?? null;

        return $nombre ? CondicionIva::where('nombre', $nombre)->value('id') : null;
    }

    /**
     * Traduce la `descripcionProvincia` cruda de ARCA al nombre completo del catálogo
     * `provincias.nombre` cuando difieren (ver MAPEO_PROVINCIA_ARCA); si no hay mapeo
     * conocido, devuelve el valor tal cual llegó de ARCA (la mayoría de las provincias ya
     * matchean sin cambios una vez que el JS del modal normaliza case/acentos).
     */
    private static function mapearProvincia(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return $raw;
        }

        $normalizado = strtoupper(\Illuminate\Support\Str::ascii(trim($raw)));

        return self::MAPEO_PROVINCIA_ARCA[$normalizado] ?? $raw;
    }
}
