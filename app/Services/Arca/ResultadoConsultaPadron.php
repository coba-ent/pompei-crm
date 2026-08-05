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

    public function __construct(
        public readonly string $cuit,
        public readonly bool $encontrado,
        public readonly ?string $razonSocial = null,
        public readonly ?string $domicilioFiscal = null,
        public readonly ?string $localidadFiscal = null,
        public readonly ?string $condicionIvaRaw = null,
        public readonly ?int $condicionIvaId = null,
        public readonly ?bool $activo = null,
    ) {}

    public static function noEncontrado(string $cuit): self
    {
        return new self(cuit: $cuit, encontrado: false);
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
            localidadFiscal: $domicilioFiscal->localidad ?? $domicilioFiscal->descripcionProvincia ?? null,
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
}
