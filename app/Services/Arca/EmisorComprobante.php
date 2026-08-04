<?php

namespace App\Services\Arca;

use App\Models\ArcaLogAuditoria;
use App\Models\CertificadoFiscal;
use App\Models\ComprobanteFiscal;
use App\Models\PuntoVenta;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use App\Services\Arca\Excepciones\ArcaRechazoException;
use App\Services\Arca\Excepciones\CertificadoNoConfiguradoException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Orquesta la emisión de un comprobante fiscal contra ARCA: valida datos, resuelve numeración,
 * solicita el CAE vía WSFEv1 y persiste el resultado. Ver contracts/emision-comprobante.md.
 */
class EmisorComprobante
{
    /**
     * @param  (callable(CertificadoFiscal): ClienteWsaa)|null  $wsaaFactory  Inyectable en tests para evitar SOAP real.
     * @param  (callable(CertificadoFiscal): ClienteWsfev1)|null  $wsfeFactory  Inyectable en tests para evitar SOAP real.
     */
    public function __construct(
        private readonly ValidadorDatosFiscales $validador = new ValidadorDatosFiscales(),
        private readonly MapeadorComprobante $mapeador = new MapeadorComprobante(),
        private $wsaaFactory = null,
        private $wsfeFactory = null,
    ) {
        $this->wsaaFactory ??= fn (CertificadoFiscal $certificado) => new ClienteWsaa($certificado);
        $this->wsfeFactory ??= fn (CertificadoFiscal $certificado) => new ClienteWsfev1($certificado);
    }

    public function emitir(Model $comprobantable, array $datos): ComprobanteFiscal
    {
        $puntoVenta = PuntoVenta::activoPorDefecto();
        $certificado = CertificadoFiscal::activo();

        if (! $puntoVenta || ! $certificado) {
            throw new CertificadoNoConfiguradoException('No hay Punto de Venta y/o certificado fiscal activo configurado.');
        }

        $motivoInvalido = $this->validador->validar(
            $datos['tipo_comprobante'],
            $datos['cliente'] ?? [],
            $datos['items'] ?? null,
            isset($datos['items']) ? (float) $datos['neto'] : null,
            isset($datos['items']) ? (float) $datos['iva'] : null,
        );
        if ($motivoInvalido !== null) {
            $this->registrarLog(null, 'validacion_datos_fiscales', 'error', $motivoInvalido, $datos, null);
            throw new ArcaRechazoException($motivoInvalido);
        }

        $cbteTipo = $this->mapeador->cbteTipo($datos['tipo_comprobante'], $datos['tipo_nota'] ?? null);
        $wsaa = ($this->wsaaFactory)($certificado);
        $wsfe = ($this->wsfeFactory)($certificado);

        $ticketAcceso = $wsaa->obtenerTicketAcceso();

        $ultimo = $wsfe->consultarUltimoAutorizado($ticketAcceso, $puntoVenta->numero, (string) $cbteTipo);
        $siguienteNumero = (int) ($ultimo->FECompUltimoAutorizadoResult->CbteNro ?? 0) + 1;

        // Reconciliación previa (FR-011): si un intento anterior tuvo timeout después de que ARCA
        // ya hubiera asignado CAE, este número ya figura consultado; evita duplicar la solicitud.
        $yaEmitido = $this->buscarYaEmitido($wsfe, $ticketAcceso, $puntoVenta, $comprobantable, $datos, $cbteTipo, $siguienteNumero);
        if ($yaEmitido !== null) {
            return $yaEmitido;
        }

        $datosMapeo = array_merge($datos, [
            'punto_venta' => $puntoVenta->numero,
            'numero' => $siguienteNumero,
        ]);
        $request = $this->mapeador->mapear($datosMapeo);

        try {
            $respuesta = $wsfe->solicitarCae($ticketAcceso, $request);
        } catch (ArcaNoDisponibleException $e) {
            $this->registrarLog(null, 'wsfe_solicitar_cae', 'error', $e->getMessage(), $request, null);
            throw $e;
        }

        $resultado = $respuesta->FECAESolicitarResult ?? $respuesta;
        $detalle = $resultado->FeDetResp->FECAEDetResponse ?? null;
        $respuestaArray = json_decode(json_encode($resultado), true);

        if ($detalle && (string) $detalle->Resultado === 'A' && ! empty($detalle->CAE)) {
            $comprobante = ComprobanteFiscal::create([
                'comprobantable_type' => $comprobantable::class,
                'comprobantable_id' => $comprobantable->getKey(),
                'punto_venta_id' => $puntoVenta->id,
                'tipo_comprobante' => $datos['tipo_comprobante'],
                'numero' => sprintf('%04d-%08d', $puntoVenta->numero, $siguienteNumero),
                'cae' => (string) $detalle->CAE,
                'cae_vencimiento' => Carbon::createFromFormat('Ymd', (string) $detalle->CAEFchVto),
                'estado' => 'aprobado',
                'comprobante_ajustado_id' => $datos['comprobante_ajustado_id'] ?? null,
                'respuesta_cruda' => $respuestaArray,
            ]);
            $this->registrarLog($comprobante->id, 'wsfe_solicitar_cae', 'exito', null, $request, $respuestaArray);

            return $comprobante;
        }

        $motivoRechazo = $this->extraerMotivoRechazo($detalle, $resultado);
        $comprobante = ComprobanteFiscal::create([
            'comprobantable_type' => $comprobantable::class,
            'comprobantable_id' => $comprobantable->getKey(),
            'punto_venta_id' => $puntoVenta->id,
            'tipo_comprobante' => $datos['tipo_comprobante'],
            'numero' => null,
            'estado' => 'rechazado',
            'motivo_rechazo' => $motivoRechazo,
            'comprobante_ajustado_id' => $datos['comprobante_ajustado_id'] ?? null,
            'respuesta_cruda' => $respuestaArray,
        ]);
        $this->registrarLog($comprobante->id, 'wsfe_solicitar_cae', 'error', $motivoRechazo, $request, $respuestaArray);

        throw new ArcaRechazoException($motivoRechazo);
    }

    /**
     * Consulta `FECompConsultar` para el próximo número esperado; si ARCA ya tiene CAE asignado
     * para esa operación (respuesta perdida en un intento anterior), persiste y devuelve el
     * comprobante como aprobado sin volver a solicitar CAE (FR-011).
     */
    public function verificarPendiente(ComprobanteFiscal $pendiente): ?ComprobanteFiscal
    {
        $certificado = CertificadoFiscal::activo();
        if (! $certificado) {
            throw new CertificadoNoConfiguradoException('No hay certificado fiscal activo configurado.');
        }

        $puntoVenta = $pendiente->puntoVenta;
        $wsaa = ($this->wsaaFactory)($certificado);
        $wsfe = ($this->wsfeFactory)($certificado);
        $ticketAcceso = $wsaa->obtenerTicketAcceso();

        $cbteTipo = $this->mapeador->cbteTipo($pendiente->tipo_comprobante);
        $ultimo = $wsfe->consultarUltimoAutorizado($ticketAcceso, $puntoVenta->numero, (string) $cbteTipo);
        $numeroEsperado = (int) ($ultimo->FECompUltimoAutorizadoResult->CbteNro ?? 0) + 1;

        return $this->buscarYaEmitido($wsfe, $ticketAcceso, $puntoVenta, $pendiente->comprobantable, [
            'tipo_comprobante' => $pendiente->tipo_comprobante,
            'comprobante_ajustado_id' => $pendiente->comprobante_ajustado_id,
        ], $cbteTipo, $numeroEsperado, $pendiente);
    }

    private function buscarYaEmitido(
        ClienteWsfev1 $wsfe,
        array $ticketAcceso,
        PuntoVenta $puntoVenta,
        Model $comprobantable,
        array $datos,
        int $cbteTipo,
        int $numero,
        ?ComprobanteFiscal $pendienteExistente = null,
    ): ?ComprobanteFiscal {
        try {
            $consulta = $wsfe->consultarComprobante($ticketAcceso, $puntoVenta->numero, (string) $cbteTipo, $numero);
        } catch (ArcaNoDisponibleException) {
            return null;
        }

        $detalle = $consulta->FECompConsultarResult->ResultGet ?? null;
        if (! $detalle || empty($detalle->CAE)) {
            return null;
        }

        $atributos = [
            'comprobantable_type' => $comprobantable::class,
            'comprobantable_id' => $comprobantable->getKey(),
            'punto_venta_id' => $puntoVenta->id,
            'tipo_comprobante' => $datos['tipo_comprobante'],
            'numero' => sprintf('%04d-%08d', $puntoVenta->numero, $numero),
            'cae' => (string) $detalle->CAE,
            'cae_vencimiento' => Carbon::createFromFormat('Ymd', (string) $detalle->CAEFchVto),
            'estado' => 'aprobado',
            'comprobante_ajustado_id' => $datos['comprobante_ajustado_id'] ?? null,
            'respuesta_cruda' => json_decode(json_encode($consulta), true),
        ];

        $comprobante = $pendienteExistente ?? new ComprobanteFiscal();
        $comprobante->fill($atributos);
        $comprobante->save();

        $this->registrarLog($comprobante->id, 'wsfe_consultar', 'exito', 'Reconciliado tras timeout previo', null, $atributos['respuesta_cruda']);

        return $comprobante;
    }

    private function extraerMotivoRechazo(?object $detalle, object $resultado): string
    {
        $observaciones = $detalle->Observaciones->Obs ?? $resultado->Errors->Err ?? null;

        if ($observaciones === null) {
            return 'ARCA rechazó el comprobante sin detalle de motivo.';
        }

        $lista = is_array($observaciones) ? $observaciones : [$observaciones];

        return collect($lista)
            ->map(fn ($o) => trim(($o->Code ?? '').' '.($o->Msg ?? '')))
            ->implode(' | ');
    }

    private function registrarLog(?int $comprobanteFiscalId, string $operacion, string $resultado, ?string $mensaje, ?array $payloadSolicitud, ?array $payloadRespuesta): void
    {
        ArcaLogAuditoria::create([
            'user_id' => Auth::id(),
            'comprobante_fiscal_id' => $comprobanteFiscalId,
            'operacion' => $operacion,
            'resultado' => $resultado,
            'mensaje' => $mensaje,
            'payload_solicitud' => $payloadSolicitud,
            'payload_respuesta' => $payloadRespuesta,
        ]);
    }
}
