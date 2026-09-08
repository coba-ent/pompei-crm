<?php

namespace App\Services\MercadoLibre;

use App\Models\CondicionIva;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Rules\CuitValido;
use App\Services\Arca\ConsultaPadron;

/**
 * Deriva el tipo de comprobante (A/B) de la condición frente al IVA del
 * comprador (FR-039/FR-040, research.md §R8) — nunca del tipo de documento,
 * salvo como aproximación cuando falta ese dato (FR-040c). Principio III de
 * la constitución: el comprobante se deriva, no se elige a mano.
 */
class DerivadorComprobante
{
    /** Mapeo del vocabulario de Mercado Libre al de `condiciones_iva` del CRM. */
    private const MAPEO_CONDICION_IVA_CRM = [
        'IVA Responsable Inscripto' => 'Responsable Inscripto',
        'Monotributo' => 'Monotributista',
        'IVA Exento' => 'Exento',
        'Consumidor Final' => 'Consumidor Final',
    ];

    /** Razón social y domicilio del comprador leídos de billing-info en esta derivación, si los hubo. */
    private array $domicilioFiscal = [
        'razon_social' => null,
        'domicilio_fiscal' => null,
        'localidad_fiscal' => null,
        'provincia_fiscal' => null,
    ];

    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
        private readonly TraductorOrdenes $traductor,
        private readonly ConsultaPadron $consultaPadron,
    ) {
    }

    /**
     * @return array{tipo_comprobante: string, condicion_iva: string, doc_tipo: ?string, doc_numero: ?string, aproximado: bool, razon_social: ?string, domicilio_fiscal: ?string, localidad_fiscal: ?string, provincia_fiscal: ?string}
     */
    public function derivar(MercadoLibreOrden $orden): array
    {
        $this->asegurarDatosFiscales($orden);
        $orden->refresh();

        if ($orden->comprador_condicion_iva) {
            [$docTipo, $docNumero] = $this->sanearDocumento($orden->comprador_doc_tipo, $orden->comprador_doc_numero);

            return [
                'tipo_comprobante' => $orden->comprador_condicion_iva === 'IVA Responsable Inscripto' ? 'A' : 'B',
                'condicion_iva' => self::MAPEO_CONDICION_IVA_CRM[$orden->comprador_condicion_iva] ?? 'Consumidor Final',
                'doc_tipo' => $docTipo,
                'doc_numero' => $docNumero,
                'aproximado' => false,
                ...$this->domicilioFiscal,
            ];
        }

        // FR-040c: sin condición de IVA pero con documento — se consulta el padrón
        // de ARCA (spec 037) antes de aproximar sólo por tipo de documento.
        if ($orden->comprador_doc_tipo) {
            [$docTipo, $docNumero] = $this->sanearDocumento($orden->comprador_doc_tipo, $orden->comprador_doc_numero);

            $resultadoPadron = $docTipo === 'CUIT' ? $this->consultaPadron->consultar($docNumero) : null;

            if ($resultadoPadron && $resultadoPadron->condicionIvaId) {
                $nombreCondicionIva = CondicionIva::find($resultadoPadron->condicionIvaId)?->nombre ?? 'Consumidor Final';

                return [
                    'tipo_comprobante' => $nombreCondicionIva === 'Responsable Inscripto' ? 'A' : 'B',
                    'condicion_iva' => $nombreCondicionIva,
                    'doc_tipo' => $docTipo,
                    'doc_numero' => $docNumero,
                    'aproximado' => false,
                    'razon_social' => $resultadoPadron->razonSocial,
                    'domicilio_fiscal' => $resultadoPadron->domicilioFiscal,
                    'localidad_fiscal' => $resultadoPadron->localidadFiscal,
                    'provincia_fiscal' => $resultadoPadron->provinciaFiscal,
                ];
            }

            return [
                'tipo_comprobante' => $docTipo === 'CUIT' ? 'A' : 'B',
                'condicion_iva' => 'Consumidor Final',
                'doc_tipo' => $docTipo,
                'doc_numero' => $docNumero,
                'aproximado' => true,
                'razon_social' => null,
                'domicilio_fiscal' => null,
                'localidad_fiscal' => null,
                'provincia_fiscal' => null,
            ];
        }

        // FR-040a: sin ningún dato fiscal, se asume y se persiste Consumidor Final.
        return [
            'tipo_comprobante' => 'B',
            'condicion_iva' => 'Consumidor Final',
            'doc_tipo' => null,
            'doc_numero' => null,
            'aproximado' => false,
            'razon_social' => null,
            'domicilio_fiscal' => null,
            'localidad_fiscal' => null,
            'provincia_fiscal' => null,
        ];
    }

    /**
     * Descarta un CUIT/CUIL matemáticamente inválido antes de que se use para
     * derivar el comprobante o persistirse en un Cliente (FR-005/FR-006/FR-007,
     * research.md R4). Cualquier otro tipo de documento (DNI/Pasaporte/CDI) o
     * un documento ya vacío pasa sin tocar.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function sanearDocumento(?string $tipo, ?string $numero): array
    {
        if (in_array($tipo, ['CUIT', 'CUIL'], true) && ! CuitValido::esValido((string) $numero)) {
            return [null, null];
        }

        return [$tipo, $numero];
    }

    /**
     * Dos llamados (research.md §R8): `GET /orders/{id}` sólo si todavía no
     * tenemos `billing_info_id` (la búsqueda incremental puede no traerlo —
     * research.md §R2), y luego `GET /orders/billing-info/{SITE_ID}/{ID}`.
     */
    private function asegurarDatosFiscales(MercadoLibreOrden $orden): void
    {
        if ($orden->comprador_condicion_iva) {
            return;
        }

        if (! $orden->billing_info_id) {
            $detalle = $this->cliente->obtener('detalle_orden', "/orders/{$orden->ml_order_id}");

            if ($detalle->exito) {
                $billingInfoId = $detalle->datos['buyer']['billing_info']['id'] ?? null;
                if ($billingInfoId) {
                    $orden->update(['billing_info_id' => $billingInfoId]);
                }
            }
        }

        if (! $orden->billing_info_id) {
            return;
        }

        $siteId = MercadoLibreConfiguracion::actual()->site_id ?: 'MLA';
        $facturacion = $this->cliente->obtener('datos_facturacion', "/orders/billing-info/{$siteId}/{$orden->billing_info_id}");

        if ($facturacion->exito) {
            $orden->update($this->traductor->traducirDatosFiscales($facturacion->datos));
            $this->domicilioFiscal = $this->traductor->traducirDomicilioFiscal($facturacion->datos);
        }
    }
}
